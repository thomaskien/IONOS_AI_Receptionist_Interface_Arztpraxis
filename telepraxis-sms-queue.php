<?php
/*
 * Persistente SMS-Ausgangsqueue fuer Telepraxis.
 *
 * Diese Datei ist nur als Include gedacht und hat keine Webapp-Abhaengigkeit.
 */

declare(strict_types=1);

if (!defined('TELEPRAXIS_APP') && !defined('TELEPRAXIS_SMS_CONFIG')) {
    http_response_code(404);
    exit;
}

const TP_SMS_QUEUE_SCHEMA_VERSION = 3;
const TP_SMS_QUEUE_STATUSES = ['pending', 'sending', 'accepted', 'failed', 'uncertain'];

/** @return never */
function tp_sms_queue_database_error(): void
{
    throw new RuntimeException('SMS-Queue-Datenbank konnte nicht verarbeitet werden.');
}

function tp_sms_queue_is_absolute_path(string $path): bool
{
    return str_starts_with($path, DIRECTORY_SEPARATOR)
        || (bool)preg_match('/^[A-Za-z]:[\\\\\/]/', $path);
}

/**
 * @return array{path:string,directory:string,exists:bool}
 */
function tp_sms_queue_database_location(array $settings): array
{
    $queue = $settings['queue'] ?? null;
    $configuredPath = is_array($queue) ? ($queue['database_path'] ?? '') : '';
    $path = is_string($configuredPath) ? trim($configuredPath) : '';
    if ($path === '' || str_contains($path, "\0") || !tp_sms_queue_is_absolute_path($path)) {
        throw new RuntimeException('Fuer die SMS-Queue ist ein absoluter Datenbankpfad erforderlich.');
    }
    if (str_ends_with($path, '/') || str_ends_with($path, '\\') || in_array(basename($path), ['.', '..'], true)) {
        throw new RuntimeException('Der SMS-Queue-Datenbankpfad ist ungueltig.');
    }

    $directoryInput = dirname($path);
    $directory = realpath($directoryInput);
    if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
        throw new RuntimeException('Das Verzeichnis der SMS-Queue fehlt oder ist nicht beschreibbar.');
    }

    $directoryPrefix = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $webroots = [__DIR__];
    if (!empty($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT'])) {
        $webroots[] = $_SERVER['DOCUMENT_ROOT'];
    }
    foreach ($webroots as $candidate) {
        $webroot = realpath($candidate);
        $webrootPrefix = $webroot === false ? '' : rtrim($webroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($webroot !== false && ($directory === $webroot || str_starts_with($directoryPrefix, $webrootPrefix))) {
            throw new RuntimeException('Die SMS-Queue-Datenbank muss ausserhalb des Webroots liegen.');
        }
    }

    $permissions = @fileperms($directory);
    if ($permissions !== false && (($permissions & 0002) !== 0)) {
        throw new RuntimeException('Das Verzeichnis der SMS-Queue darf nicht fuer alle beschreibbar sein.');
    }

    $canonicalPath = $directory . DIRECTORY_SEPARATOR . basename($path);
    $exists = file_exists($canonicalPath) || is_link($canonicalPath);
    if ($exists) {
        if (is_link($canonicalPath) || !is_file($canonicalPath)) {
            throw new RuntimeException('Der SMS-Queue-Datenbankpfad ist ungueltig.');
        }
        $databasePermissions = @fileperms($canonicalPath);
        if ($databasePermissions !== false && (($databasePermissions & 0007) !== 0)) {
            throw new RuntimeException('Die SMS-Queue-Datenbank hat unsichere Dateirechte.');
        }
        $resolved = realpath($canonicalPath);
        if ($resolved === false || $resolved !== $canonicalPath) {
            throw new RuntimeException('Der SMS-Queue-Datenbankpfad ist ungueltig.');
        }
    }

    return ['path' => $canonicalPath, 'directory' => $directory, 'exists' => $exists];
}

function tp_sms_queue_busy_timeout(array $settings): int
{
    $queue = $settings['queue'] ?? [];
    $configured = is_array($queue) ? ($queue['busy_timeout_ms'] ?? 1000) : 1000;
    $timeout = is_int($configured) || is_float($configured) || is_string($configured)
        ? (int)$configured
        : 1000;
    return max(0, min(60000, $timeout));
}

function tp_sms_queue_open(array $settings, bool $initialize, bool $readOnly = false): ?PDO
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('PHP-pdo_sqlite ist fuer die SMS-Queue erforderlich.');
    }

    $location = tp_sms_queue_database_location($settings);
    if ($readOnly && !$location['exists']) {
        return null;
    }

    $oldUmask = umask(0007);
    $wasNew = !$location['exists'];
    try {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => max(1, (int)ceil(tp_sms_queue_busy_timeout($settings) / 1000)),
        ];
        if ($readOnly) {
            if (PHP_VERSION_ID >= 80500 && class_exists('Pdo\\Sqlite')) {
                $options[Pdo\Sqlite::ATTR_OPEN_FLAGS] = Pdo\Sqlite::OPEN_READONLY;
            } else {
                $options[PDO::SQLITE_ATTR_OPEN_FLAGS] = PDO::SQLITE_OPEN_READONLY;
            }
        }
        $pdo = new PDO('sqlite:' . $location['path'], null, null, $options);
        $pdo->exec('PRAGMA busy_timeout = ' . tp_sms_queue_busy_timeout($settings));
        $pdo->exec('PRAGMA foreign_keys = ON');
        if (!$readOnly) {
            $pdo->exec('PRAGMA synchronous = FULL');
        }

        if (is_link($location['path']) || !is_file($location['path'])) {
            throw new RuntimeException('Der SMS-Queue-Datenbankpfad ist ungueltig.');
        }
        $resolved = realpath($location['path']);
        if ($resolved === false || $resolved !== $location['path']) {
            throw new RuntimeException('Der SMS-Queue-Datenbankpfad ist ungueltig.');
        }
        if ($wasNew) {
            @chmod($location['path'], 0660);
        }

        if ($initialize) {
            tp_sms_queue_initialize_schema($pdo);
        } else {
            tp_sms_queue_require_schema($pdo);
        }
        return $pdo;
    } catch (RuntimeException $exception) {
        if (str_starts_with($exception->getMessage(), 'Der SMS-Queue-Datenbankpfad')) {
            throw $exception;
        }
        tp_sms_queue_database_error();
    } catch (Throwable $exception) {
        tp_sms_queue_database_error();
    } finally {
        umask($oldUmask);
    }
}

function tp_sms_queue_initialize_schema(PDO $pdo): void
{
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if (!in_array($version, [0, 1, 2, TP_SMS_QUEUE_SCHEMA_VERSION], true)) {
            throw new RuntimeException('schema-version');
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sms_queue (
                enqueue_order INTEGER PRIMARY KEY AUTOINCREMENT,
                id TEXT NOT NULL UNIQUE,
                request_id TEXT NOT NULL UNIQUE,
                delivery_provider TEXT NOT NULL CHECK (delivery_provider IN ('fritz', 'seven')),
                recipient TEXT NOT NULL,
                message TEXT NOT NULL,
                source_file TEXT NOT NULL,
                workplace TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('pending', 'sending', 'accepted', 'failed', 'uncertain')),
                failure_code TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )"
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS sms_queue_source_idx ON sms_queue(source_file, enqueue_order)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS sms_queue_status_idx ON sms_queue(status, enqueue_order)');
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sms_queue_batches (
                request_id TEXT PRIMARY KEY,
                fingerprint TEXT NOT NULL,
                job_ids TEXT NOT NULL
            )'
        );
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS sms_queue_cleanup (
                id TEXT PRIMARY KEY,
                job_id TEXT NOT NULL,
                message_uid TEXT NOT NULL,
                fritzbox_host TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('pending', 'done')),
                attempts INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
                next_attempt_at TEXT NOT NULL,
                failure_code TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                UNIQUE (job_id, message_uid),
                FOREIGN KEY (job_id) REFERENCES sms_queue(id) ON DELETE CASCADE
            )"
        );
        $pdo->exec(
            'CREATE INDEX IF NOT EXISTS sms_queue_cleanup_due_idx
             ON sms_queue_cleanup(status, next_attempt_at, created_at)'
        );
        if ($version < TP_SMS_QUEUE_SCHEMA_VERSION) {
            $pdo->exec('PRAGMA user_version = ' . TP_SMS_QUEUE_SCHEMA_VERSION);
        }
        $pdo->exec('COMMIT');
        $pdo->exec('PRAGMA journal_mode = WAL');
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function tp_sms_queue_require_schema(PDO $pdo): void
{
    $version = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
    // Alte Aufträge können bereits vor der ersten Schreiboperation angezeigt werden.
    if (!in_array($version, [1, 2, TP_SMS_QUEUE_SCHEMA_VERSION], true)) {
        throw new RuntimeException('schema-version');
    }
}

function tp_sms_queue_timestamp(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\\TH:i:s.u\\Z');
}

function tp_sms_queue_delivery_provider(array $settings): string
{
    $queue = $settings['queue'] ?? [];
    $configured = is_array($queue) ? ($queue['delivery_provider'] ?? 'fritz') : 'fritz';
    $provider = is_string($configured) ? trim($configured) : '';
    if (!in_array($provider, ['fritz', 'seven'], true)) {
        throw new RuntimeException('Ungueltiger SMS-Queue-Zustellprovider.');
    }
    return $provider;
}

/** @return array{request_id:string,source_file:string,workplace:string} */
function tp_sms_queue_context(array $context): array
{
    $requestId = $context['request_id'] ?? bin2hex(random_bytes(16));
    if (!is_string($requestId)) {
        throw new RuntimeException('Die SMS-Queue-request_id ist ungueltig.');
    }
    if (!preg_match('/^[a-f0-9]{32,64}$/D', $requestId)) {
        throw new RuntimeException('Die SMS-Queue-request_id ist ungueltig.');
    }
    $sourceFile = $context['source_file'] ?? '';
    if (!is_string($sourceFile)
        || ($sourceFile !== '' && ($sourceFile !== basename($sourceFile)
            || str_contains($sourceFile, '\\') || in_array($sourceFile, ['.', '..'], true)))) {
        throw new RuntimeException('source_file muss ein Dateibasisname sein.');
    }
    $workplace = $context['workplace'] ?? '';
    if (!is_string($workplace)) {
        throw new RuntimeException('workplace muss eine Zeichenkette sein.');
    }
    return ['request_id' => $requestId, 'source_file' => $sourceFile, 'workplace' => $workplace];
}

/**
 * @return array{ok:true,provider:string,queued:true,job_id:string,status:string,duplicate:bool,message:string,details:array{job_id:string,status:string}}
 */
function tp_sms_queue_enqueue(
    array $settings,
    string $recipient,
    string $message,
    array $context = []
): array {
    $provider = tp_sms_queue_delivery_provider($settings);
    tp_sms_validate_provider_text($provider, $message);
    $queueContext = tp_sms_queue_context($context);
    $pdo = tp_sms_queue_open($settings, true);
    if (!$pdo instanceof PDO) {
        tp_sms_queue_database_error();
    }

    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $result = tp_sms_queue_store_job($pdo, $provider, $recipient, $message, $queueContext);
        $pdo->commit();
        return $result;
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($exception->getMessage(), 'request_id gehoert')) {
            throw $exception;
        }
        tp_sms_queue_database_error();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tp_sms_queue_database_error();
    }
}

/** Interner Baustein: der Aufrufer hält die Schreibtransaktion. */
function tp_sms_queue_store_job(PDO $pdo, string $provider, string $recipient, string $message, array $context): array
{
    $select = $pdo->prepare(
        'SELECT id, recipient, message, source_file, workplace, status
         FROM sms_queue WHERE request_id = :request_id'
    );
    $select->execute([':request_id' => $context['request_id']]);
    $existing = $select->fetch();
    if (is_array($existing)) {
        if ($existing['recipient'] !== $recipient || $existing['message'] !== $message
            || $existing['source_file'] !== $context['source_file'] || $existing['workplace'] !== $context['workplace']) {
            throw new RuntimeException('Die request_id gehoert bereits zu einem anderen SMS-Auftrag.');
        }
        return tp_sms_queue_enqueue_result((string)$existing['id'], (string)$existing['status'], true);
    }

    $jobId = bin2hex(random_bytes(16));
    $now = tp_sms_queue_timestamp();
    $insert = $pdo->prepare(
        'INSERT INTO sms_queue
         (id, request_id, delivery_provider, recipient, message, source_file, workplace, status, created_at, updated_at)
         VALUES
         (:id, :request_id, :delivery_provider, :recipient, :message, :source_file, :workplace, :status, :created_at, :updated_at)'
    );
    $insert->execute([
        ':id' => $jobId, ':request_id' => $context['request_id'], ':delivery_provider' => $provider,
        ':recipient' => $recipient, ':message' => $message, ':source_file' => $context['source_file'],
        ':workplace' => $context['workplace'], ':status' => 'pending', ':created_at' => $now, ':updated_at' => $now,
    ]);
    return tp_sms_queue_enqueue_result($jobId, 'pending', false);
}

/** Mehrere eigenständige SMS atomar und idempotent in der angegebenen Reihenfolge speichern. */
function tp_sms_queue_enqueue_batch(array $settings, string $recipient, array $messages, array $context = []): array
{
    if (trim($recipient) === '' || !array_is_list($messages) || $messages === []) {
        throw new RuntimeException('SMS-Empfänger und eine nichtleere Nachrichtenliste sind erforderlich.');
    }
    $provider = tp_sms_queue_delivery_provider($settings);
    $queueContext = tp_sms_queue_context($context);
    $maxLength = (int)($settings['sms']['max_text_length'] ?? 612);
    foreach ($messages as $message) {
        if (!is_string($message) || trim($message) === '') {
            throw new RuntimeException('Die SMS-Nachrichtenliste darf nur nichtleere Texte enthalten.');
        }
        tp_sms_validate_provider_text($provider, $message);
        if ($maxLength > 0 && tp_sms_text_length($message) > $maxLength) {
            throw new RuntimeException('SMS-Text ist laenger als ' . $maxLength . ' Zeichen.');
        }
    }
    $fingerprint = hash('sha256', json_encode(
        [$recipient, $messages, $queueContext['source_file'], $queueContext['workplace']], JSON_THROW_ON_ERROR
    ));
    $pdo = tp_sms_queue_open($settings, true);
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $select = $pdo->prepare('SELECT fingerprint, job_ids FROM sms_queue_batches WHERE request_id = :request_id');
        $select->execute([':request_id' => $queueContext['request_id']]);
        $batch = $select->fetch();
        $duplicate = is_array($batch);
        $jobs = [];
        if ($duplicate) {
            if (!hash_equals((string)$batch['fingerprint'], $fingerprint)) {
                throw new RuntimeException('Die request_id gehoert bereits zu einem anderen SMS-Auftrag.');
            }
            $jobIds = json_decode($batch['job_ids'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($jobIds) || !array_is_list($jobIds) || count($jobIds) !== count($messages)) {
                tp_sms_queue_database_error();
            }
            $query = $pdo->prepare('SELECT id, status FROM sms_queue WHERE id = :id');
            foreach ($jobIds as $jobId) {
                $query->execute([':id' => $jobId]);
                $job = $query->fetch();
                if (!is_array($job)) {
                    tp_sms_queue_database_error();
                }
                $jobs[] = tp_sms_queue_enqueue_result($job['id'], $job['status'], true);
            }
        } else {
            foreach ($messages as $index => $message) {
                $partContext = $queueContext;
                $partContext['request_id'] = hash('sha256', 'sms-batch:' . $queueContext['request_id'] . ':' . $index);
                $job = tp_sms_queue_store_job($pdo, $provider, $recipient, $message, $partContext);
                if ($job['duplicate']) {
                    throw new RuntimeException('Die request_id gehoert bereits zu einem anderen SMS-Auftrag.');
                }
                $jobs[] = $job;
            }
            $insert = $pdo->prepare('INSERT INTO sms_queue_batches (request_id, fingerprint, job_ids) VALUES (?, ?, ?)');
            $insert->execute([$queueContext['request_id'], $fingerprint, json_encode(array_column($jobs, 'job_id'), JSON_THROW_ON_ERROR)]);
        }
        $pdo->commit();
        return [
            'ok' => true, 'provider' => 'queue', 'queued' => true, 'batch_id' => $queueContext['request_id'],
            'duplicate' => $duplicate, 'jobs' => $jobs, 'message' => count($jobs) . ' SMS zum Versand vorgemerkt.',
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($error instanceof RuntimeException && str_contains($error->getMessage(), 'request_id gehoert')) {
            throw $error;
        }
        tp_sms_queue_database_error();
    }
}

/** Erst nach dauerhafter Telepraxis-Übernahme aufrufen; Received-Key muss geräteübergreifend stabil/eindeutig sein. */
function tp_sms_queue_auto_reply(array $settings, string $recipient, string $receivedMessageKey, array $context = []): array
{
    if (trim($receivedMessageKey) === '') {
        throw new RuntimeException('Eine eindeutige Kennung der eingegangenen SMS ist erforderlich.');
    }
    $messages = tp_sms_auto_reply_messages($settings);
    if ($messages === []) {
        return ['ok' => true, 'provider' => 'queue', 'queued' => false, 'jobs' => [], 'message' => 'Automatische SMS-Antwort ist deaktiviert.'];
    }
    $context['request_id'] = hash('sha256', 'sms-auto-reply:' . $receivedMessageKey);
    return tp_sms_queue_enqueue_batch($settings, $recipient, $messages, $context);
}

function tp_sms_queue_enqueue_result(string $jobId, string $status, bool $duplicate): array
{
    return [
        'ok' => true,
        'provider' => 'queue',
        'queued' => true,
        'job_id' => $jobId,
        'status' => $status,
        'duplicate' => $duplicate,
        'message' => 'SMS zum Versand vorgemerkt.',
        'details' => ['job_id' => $jobId, 'status' => $status],
    ];
}

/** @return list<array<string,mixed>> */
function tp_sms_queue_for_source(array $settings, string $sourceFile): array
{
    if ($sourceFile === '' || $sourceFile !== basename($sourceFile) || str_contains($sourceFile, '\\')) {
        throw new RuntimeException('source_file muss ein Dateibasisname sein.');
    }
    $pdo = tp_sms_queue_open($settings, false, true);
    if (!$pdo instanceof PDO) {
        return [];
    }
    try {
        $query = $pdo->prepare(
            'SELECT id, delivery_provider, recipient, message, source_file, workplace, status, created_at, updated_at
             FROM sms_queue WHERE source_file = :source_file ORDER BY enqueue_order ASC'
        );
        $query->execute([':source_file' => $sourceFile]);
        return $query->fetchAll();
    } catch (Throwable $exception) {
        tp_sms_queue_database_error();
    }
}

function tp_sms_queue_validate_job(array $settings, array $job): ?string
{
    $provider = (string)($job['delivery_provider'] ?? '');
    if (!in_array($provider, ['fritz', 'seven'], true)) {
        return 'invalid_provider';
    }
    if (trim((string)($job['recipient'] ?? '')) === '' || trim((string)($job['message'] ?? '')) === '') {
        return 'invalid_payload';
    }
    try {
        tp_sms_validate_provider_text($provider, (string)$job['message']);
    } catch (RuntimeException $error) {
        return 'invalid_payload';
    }
    $maxLength = (int)($settings['sms']['max_text_length'] ?? 612);
    if ($maxLength > 0 && function_exists('tp_sms_text_length')
        && tp_sms_text_length((string)$job['message']) > $maxLength) {
        return 'invalid_payload';
    }

    if ($provider === 'seven') {
        $config = $settings['seven'] ?? null;
        $apiKey = is_array($config) ? ($config['api_key'] ?? '') : '';
        if (!is_array($config) || !function_exists('tp_sms_is_placeholder')
            || !is_string($apiKey) || tp_sms_is_placeholder($apiKey)) {
            return 'invalid_configuration';
        }
        return null;
    }

    $config = $settings['fritzbox'] ?? null;
    if (!is_array($config) || !function_exists('tp_sms_is_placeholder')) {
        return 'invalid_configuration';
    }
    foreach (['host', 'username', 'password'] as $key) {
        $value = $config[$key] ?? '';
        if (!is_string($value) || tp_sms_is_placeholder($value)) {
            return 'invalid_configuration';
        }
    }
    return null;
}

/** @return array{resource:string,handle:resource}|null */
function tp_sms_queue_worker_lock(array $settings): ?array
{
    $location = tp_sms_queue_database_location($settings);
    $lockPath = $location['path'] . '.worker.lock';
    if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
        throw new RuntimeException('Die SMS-Queue-Worker-Sperre ist ungueltig.');
    }
    if (is_file($lockPath)) {
        $lockPermissions = @fileperms($lockPath);
        if ($lockPermissions !== false && (($lockPermissions & 0007) !== 0)) {
            throw new RuntimeException('Die SMS-Queue-Worker-Sperre hat unsichere Dateirechte.');
        }
    }
    $oldUmask = umask(0007);
    try {
        $handle = @fopen($lockPath, 'c+');
    } finally {
        umask($oldUmask);
    }
    if ($handle === false) {
        throw new RuntimeException('Die SMS-Queue-Worker-Sperre konnte nicht geoeffnet werden.');
    }
    if (is_link($lockPath)) {
        fclose($handle);
        throw new RuntimeException('Die SMS-Queue-Worker-Sperre ist ungueltig.');
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }
    return ['resource' => $lockPath, 'handle' => $handle];
}

function tp_sms_queue_set_status(PDO $pdo, string $jobId, string $status, ?string $failureCode): void
{
    if (!in_array($status, TP_SMS_QUEUE_STATUSES, true)) {
        throw new RuntimeException('Ungueltiger interner SMS-Queue-Status.');
    }
    $update = $pdo->prepare(
        'UPDATE sms_queue SET status = :status, failure_code = :failure_code, updated_at = :updated_at
         WHERE id = :id'
    );
    $update->execute([
        ':status' => $status,
        ':failure_code' => $failureCode,
        ':updated_at' => tp_sms_queue_timestamp(),
        ':id' => $jobId,
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('SMS-Queue-Auftrag wurde nicht gefunden.');
    }
}

function tp_sms_queue_fritz_host(array $settings): string
{
    $config = $settings['fritzbox'] ?? null;
    $host = is_array($config) ? ($config['host'] ?? '') : '';
    if (!is_string($host) || trim($host) === '') {
        throw new RuntimeException('FRITZ!Box-Konfiguration fehlt.');
    }
    return $host;
}

/** Eine vom Router vergebene UID sofort, dauerhaft und idempotent zum Löschen vormerken. */
function tp_sms_queue_record_cleanup(array $settings, string $jobId, string $messageUid): void
{
    $messageUid = trim($messageUid);
    if ($messageUid === '') {
        throw new RuntimeException('FRITZ!Box-Nachrichtenkennung fehlt.');
    }
    $host = tp_sms_queue_fritz_host($settings);
    $pdo = tp_sms_queue_open($settings, true);
    if (!$pdo instanceof PDO) {
        tp_sms_queue_database_error();
    }
    try {
        $pdo->exec('BEGIN IMMEDIATE');
        $now = tp_sms_queue_timestamp();
        $insert = $pdo->prepare(
            "INSERT OR IGNORE INTO sms_queue_cleanup
             (id, job_id, message_uid, fritzbox_host, status, attempts, next_attempt_at, created_at, updated_at)
             VALUES (:id, :job_id, :message_uid, :fritzbox_host, 'pending', 0, :next_attempt_at, :created_at, :updated_at)"
        );
        $insert->execute([
            ':id' => bin2hex(random_bytes(16)),
            ':job_id' => $jobId,
            ':message_uid' => $messageUid,
            ':fritzbox_host' => $host,
            ':next_attempt_at' => $now,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        tp_sms_queue_database_error();
    }
}

/** @return array{job_id:string,status:string}|null */
function tp_sms_queue_process_one(array $settings, ?callable $sender = null): ?array
{
    $lock = tp_sms_queue_worker_lock($settings);
    if ($lock === null) {
        return null;
    }

    try {
        $pdo = tp_sms_queue_open($settings, true);
        if (!$pdo instanceof PDO) {
            tp_sms_queue_database_error();
        }
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            $recovery = $pdo->prepare(
                "UPDATE sms_queue SET status = 'uncertain', failure_code = 'worker_interrupted', updated_at = :updated_at
                 WHERE status = 'sending'"
            );
            $recovery->execute([':updated_at' => tp_sms_queue_timestamp()]);

            $job = $pdo->query(
                "SELECT id, delivery_provider, recipient, message
                 FROM sms_queue WHERE status = 'pending' ORDER BY enqueue_order ASC LIMIT 1"
            )->fetch();
            if (!is_array($job)) {
                $pdo->commit();
                return null;
            }

            $validationFailure = tp_sms_queue_validate_job($settings, $job);
            if ($validationFailure !== null) {
                tp_sms_queue_set_status($pdo, (string)$job['id'], 'failed', $validationFailure);
                $pdo->commit();
                return ['job_id' => (string)$job['id'], 'status' => 'failed'];
            }

            tp_sms_queue_set_status($pdo, (string)$job['id'], 'sending', null);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tp_sms_queue_database_error();
        }

        $provider = (string)$job['delivery_provider'];
        $sendSettings = $settings;
        if ($provider === 'fritz') {
            if (!isset($sendSettings['fritzbox']) || !is_array($sendSettings['fritzbox'])) {
                $sendSettings['fritzbox'] = [];
            }
            $sendSettings['fritzbox']['delete_after_send'] = false;
        }

        $cleanupPersistenceFailed = false;
        $sendContext = [];
        if ($provider === 'fritz') {
            $sendContext['on_fritz_message_created'] = static function (string $messageUid) use (
                $settings,
                $job,
                &$cleanupPersistenceFailed
            ): void {
                try {
                    tp_sms_queue_record_cleanup($settings, (string)$job['id'], $messageUid);
                } catch (Throwable $exception) {
                    $cleanupPersistenceFailed = true;
                    throw $exception;
                }
            };
        }

        try {
            $result = $sender !== null
                ? $sender($sendSettings, $provider, (string)$job['recipient'], (string)$job['message'], $sendContext)
                : tp_sms_dispatch(
                    $sendSettings,
                    $provider,
                    (string)$job['recipient'],
                    (string)$job['message'],
                    $sendContext
                );
            $confirmed = is_array($result) && (($result['ok'] ?? false) === true);
            if ($provider === 'fritz') {
                $messageUid = $result['details']['message_uid'] ?? '';
                $messageUid = is_string($messageUid) ? trim($messageUid) : '';
                $confirmed = $confirmed && $messageUid !== '';
                if ($messageUid !== '') {
                    try {
                        tp_sms_queue_record_cleanup($settings, (string)$job['id'], $messageUid);
                        $cleanupPersistenceFailed = false;
                    } catch (Throwable $exception) {
                        $cleanupPersistenceFailed = true;
                        throw $exception;
                    }
                }
            }
            $finalStatus = $confirmed ? 'accepted' : 'uncertain';
            $failureCode = $confirmed ? null : 'delivery_not_confirmed';
        } catch (Throwable $exception) {
            $finalStatus = 'uncertain';
            $failureCode = $cleanupPersistenceFailed ? 'cleanup_persistence_failed' : 'transport_exception';
        }

        try {
            $pdo->exec('BEGIN IMMEDIATE');
            tp_sms_queue_set_status($pdo, (string)$job['id'], $finalStatus, $failureCode);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tp_sms_queue_database_error();
        }
        return ['job_id' => (string)$job['id'], 'status' => $finalStatus];
    } finally {
        flock($lock['handle'], LOCK_UN);
        fclose($lock['handle']);
    }
}

function tp_sms_queue_cleanup_failure_code(array $settings, array $cleanup): ?string
{
    $config = $settings['fritzbox'] ?? null;
    $currentHost = is_array($config) ? ($config['host'] ?? null) : null;
    if (!is_string($currentHost) || $currentHost !== (string)$cleanup['fritzbox_host']) {
        return 'target_host_changed';
    }
    if (!function_exists('tp_sms_is_placeholder')) {
        return 'invalid_configuration';
    }
    foreach (['host', 'username', 'password'] as $key) {
        $value = $config[$key] ?? '';
        if (!is_string($value) || tp_sms_is_placeholder($value)) {
            return 'invalid_configuration';
        }
    }
    return null;
}

function tp_sms_queue_cleanup_retry_at(int $attempts): string
{
    $exponent = max(0, min(4, $attempts - 1));
    $delay = min(300, 30 * (2 ** $exponent));
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
        ->modify('+' . $delay . ' seconds')
        ->format('Y-m-d\\TH:i:s.u\\Z');
}

function tp_sms_queue_update_cleanup(
    PDO $pdo,
    string $cleanupId,
    string $status,
    int $attempts,
    string $nextAttemptAt,
    ?string $failureCode
): void {
    $update = $pdo->prepare(
        'UPDATE sms_queue_cleanup
         SET status = :status, attempts = :attempts, next_attempt_at = :next_attempt_at,
             failure_code = :failure_code, updated_at = :updated_at
         WHERE id = :id'
    );
    $update->execute([
        ':status' => $status,
        ':attempts' => $attempts,
        ':next_attempt_at' => $nextAttemptAt,
        ':failure_code' => $failureCode,
        ':updated_at' => tp_sms_queue_timestamp(),
        ':id' => $cleanupId,
    ]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('SMS-Queue-Loeschauftrag wurde nicht gefunden.');
    }
}

/** @return array{cleanup_id:string,job_id:string,status:string,failure_code:?string}|null */
function tp_sms_queue_process_cleanup_one(array $settings, ?callable $deleter = null): ?array
{
    $lock = tp_sms_queue_worker_lock($settings);
    if ($lock === null) {
        return null;
    }

    try {
        $pdo = tp_sms_queue_open($settings, true);
        if (!$pdo instanceof PDO) {
            tp_sms_queue_database_error();
        }
        try {
            $query = $pdo->prepare(
                "SELECT cleanup.id, cleanup.job_id, cleanup.message_uid, cleanup.fritzbox_host, cleanup.attempts
                 FROM sms_queue_cleanup AS cleanup
                 INNER JOIN sms_queue AS job ON job.id = cleanup.job_id
                 WHERE cleanup.status = 'pending' AND cleanup.next_attempt_at <= :now
                   AND job.status <> 'sending'
                 ORDER BY cleanup.next_attempt_at ASC, cleanup.created_at ASC
                 LIMIT 1"
            );
            $query->execute([':now' => tp_sms_queue_timestamp()]);
            $cleanup = $query->fetch();
            $query->closeCursor();
        } catch (Throwable $exception) {
            tp_sms_queue_database_error();
        }
        if (!is_array($cleanup)) {
            return null;
        }

        $failureCode = tp_sms_queue_cleanup_failure_code($settings, $cleanup);
        if ($failureCode === null) {
            try {
                $result = $deleter !== null
                    ? $deleter($settings, (string)$cleanup['message_uid'])
                    : tp_sms_fritz_delete_message($settings, (string)$cleanup['message_uid']);
                if (!is_array($result) || (($result['deleted'] ?? false) !== true)) {
                    $failureCode = 'delete_not_confirmed';
                }
            } catch (Throwable $exception) {
                $failureCode = 'delete_exception';
            }
        }

        $done = $failureCode === null;
        $attempts = (int)$cleanup['attempts'] + ($done ? 0 : 1);
        $nextAttemptAt = $done ? tp_sms_queue_timestamp() : tp_sms_queue_cleanup_retry_at($attempts);
        try {
            $pdo->exec('BEGIN IMMEDIATE');
            tp_sms_queue_update_cleanup(
                $pdo,
                (string)$cleanup['id'],
                $done ? 'done' : 'pending',
                $attempts,
                $nextAttemptAt,
                $failureCode
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            tp_sms_queue_database_error();
        }
        return [
            'cleanup_id' => (string)$cleanup['id'],
            'job_id' => (string)$cleanup['job_id'],
            'status' => $done ? 'done' : 'pending',
            'failure_code' => $failureCode,
        ];
    } finally {
        flock($lock['handle'], LOCK_UN);
        fclose($lock['handle']);
    }
}

/** @return array<string,int> */
function tp_sms_queue_status_counts(array $settings): array
{
    $counts = array_fill_keys(TP_SMS_QUEUE_STATUSES, 0);
    $pdo = tp_sms_queue_open($settings, false, true);
    if (!$pdo instanceof PDO) {
        return $counts;
    }
    try {
        $rows = $pdo->query('SELECT status, COUNT(*) AS count FROM sms_queue GROUP BY status')->fetchAll();
        foreach ($rows as $row) {
            $status = (string)($row['status'] ?? '');
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int)$row['count'];
            }
        }
        return $counts;
    } catch (Throwable $exception) {
        tp_sms_queue_database_error();
    }
}

function tp_sms_queue_cleanup_pending_count(array $settings): int
{
    $pdo = tp_sms_queue_open($settings, false, true);
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        $exists = $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sms_queue_cleanup'"
        )->fetchColumn();
        if ($exists === false) {
            return 0;
        }
        return (int)$pdo->query(
            "SELECT COUNT(*) FROM sms_queue_cleanup WHERE status = 'pending'"
        )->fetchColumn();
    } catch (Throwable $exception) {
        tp_sms_queue_database_error();
    }
}

function tp_sms_queue_cleanup_failure_count(array $settings): int
{
    $pdo = tp_sms_queue_open($settings, false, true);
    if (!$pdo instanceof PDO) {
        return 0;
    }
    try {
        $exists = $pdo->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'sms_queue_cleanup'"
        )->fetchColumn();
        if ($exists === false) {
            return 0;
        }
        return (int)$pdo->query(
            "SELECT COUNT(*) FROM sms_queue_cleanup
             WHERE status = 'pending' AND failure_code IS NOT NULL"
        )->fetchColumn();
    } catch (Throwable $exception) {
        tp_sms_queue_database_error();
    }
}

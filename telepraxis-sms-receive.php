<?php
/*
 * Eigenstaendiger Journalempfang fuer die lokale Telepraxis-Zielinstallation.
 * Changelog 2026-09-29: Geraetebindung, gesicherte Journalaufnahme und gepruefte Bereinigung.
 */

declare(strict_types=1);

if (!defined('TELEPRAXIS_APP') && !defined('TELEPRAXIS_SMS_CONFIG')) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/telepraxis-sms.php';
require_once __DIR__ . '/telepraxis-sms-queue.php';
require_once __DIR__ . '/telepraxis-sms-journal.php';
require_once __DIR__ . '/telepraxis-sms-receive-store.php';

/** Die Seriennummer bleibt im Arbeitsspeicher; persistiert wird nur ihre Geraetekennung. */
function tp_sms_receive_device_key(array $config): string
{
    $response = tp_sms_http_request('GET', tp_sms_fritz_url($config['host'], 'jason_boxinfo.xml'), [
        'timeout' => (int)$config['timeout_seconds'],
        'verify_tls' => (bool)$config['verify_tls'],
        'cookie_file' => (string)($config['_cookie_file'] ?? ''),
    ]);
    if ($response['status'] !== 200 || preg_match('/<!DOCTYPE|<!ENTITY/i', $response['body'])) {
        throw new RuntimeException('SMS-Geraetekennung konnte nicht geprueft werden.');
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $xml = simplexml_load_string($response['body'], SimpleXMLElement::class, LIBXML_NONET);
        if ($xml === false || $xml->getName() !== 'BoxInfo') {
            throw new RuntimeException('SMS-Geraetekennung konnte nicht geprueft werden.');
        }
        $serials = $xml->xpath('/*[local-name()="BoxInfo"]/*[local-name()="Serial"]');
        if (!is_array($serials) || count($serials) !== 1) {
            throw new RuntimeException('SMS-Geraetekennung konnte nicht geprueft werden.');
        }
        $serial = trim((string)$serials[0]);
        if ($serial === '' || strlen($serial) > 128) {
            throw new RuntimeException('SMS-Geraetekennung konnte nicht geprueft werden.');
        }
        return hash('sha256', 'fritz-serial:' . $serial);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
}

function tp_sms_receive_session(array $settings, callable $operation): array
{
    $config = $settings['fritzbox'] ?? null;
    if (!is_array($config)) {
        throw new RuntimeException('SMS-Routerkonfiguration fehlt.');
    }
    $config = array_replace(tp_sms_default_settings()['fritzbox'], $config);
    $sid = '';
    $cookieFile = null;
    // Auch cURL-/PHP-Warnungen koennen Hostnamen, Pfade und Antwortdaten enthalten.
    set_error_handler(static function (int $severity): bool {
        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return true;
        }
        throw new RuntimeException('SMS-Routerzugriff fehlgeschlagen.');
    });
    try {
        foreach (['host', 'username', 'password'] as $name) {
            tp_sms_require_config_value($config, $name, 'SMS-Routerkonfiguration');
        }
        $config['timeout_seconds'] = max(1, min(60, (int)$config['timeout_seconds']));
        $cookieFile = tempnam(sys_get_temp_dir(), 'tp-sms-receive-');
        if ($cookieFile === false || !chmod($cookieFile, 0600)) {
            throw new RuntimeException('SMS-Routersitzung konnte nicht angelegt werden.');
        }
        $config['_cookie_file'] = $cookieFile;
        $deviceKey = tp_sms_receive_device_key($config);
        $sid = tp_sms_fritz_login($config);
        return $operation($config, $sid, $deviceKey);
    } catch (Throwable $error) {
        // Providerantworten, Logindaten und Klartextnachrichten nicht weiterreichen.
        throw new RuntimeException('SMS-Journalzugriff fehlgeschlagen.');
    } finally {
        try {
            tp_sms_fritz_logout($config, $sid);
        } catch (Throwable $ignored) {
        }
        try {
            if (is_string($cookieFile) && is_file($cookieFile)) {
                unlink($cookieFile);
            }
        } finally {
            restore_error_handler();
        }
    }
}

/** Liest die komplette vom bestaetigten smsList-Format gelieferte messages-Liste. */
function tp_sms_receive_session_entries(array $config, string &$sid, string $deviceKey): array
{
    $response = tp_sms_fritz_request($config, 'data.lua', [
        'sid' => $sid, 'page' => 'smsList', 'xhr' => '1',
    ], 'SMS-Journal');
    $sid = tp_sms_fritz_update_sid($sid, $response);
    $entries = tp_sms_journal_parse($response);
    if (!hash_equals($deviceKey, tp_sms_receive_device_key($config))) {
        throw new RuntimeException('SMS-Geraet hat sich waehrend des Abrufs geaendert.');
    }
    return $entries;
}

/** @return array{device_key:string,entries:array} */
function tp_sms_receive_read_snapshot(array $settings): array
{
    return tp_sms_receive_session($settings, static function (array $config, string &$sid, string $deviceKey): array {
        return [
            'device_key' => $deviceKey,
            'entries' => tp_sms_receive_session_entries($config, $sid, $deviceKey),
        ];
    });
}

/** Vor jedem Loeschen dieselbe Geraete- UND Nachrichtenidentitaet frisch bestaetigen. */
function tp_sms_receive_delete_checked(array $settings, string $deviceKey, string $uid, string $fingerprint): array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $deviceKey)
        || !preg_match('/^[a-f0-9]{64}$/D', $fingerprint)
        || !preg_match('/^[0-9]{1,19}$/D', $uid)) {
        throw new RuntimeException('Ungueltiger SMS-Journallöschauftrag.');
    }
    return tp_sms_receive_session($settings, static function (array $config, string &$sid, string $actualDevice) use (
        $deviceKey, $uid, $fingerprint
    ): array {
        if (!hash_equals($deviceKey, $actualDevice)) {
            return ['deleted' => false, 'failure_code' => 'device_changed'];
        }
        $entries = tp_sms_receive_session_entries($config, $sid, $actualDevice);
        foreach ($entries as $entry) {
            if ($entry['uid'] === null) {
                return ['deleted' => false, 'failure_code' => 'journal_uid_unverified'];
            }
        }
        $matches = array_values(array_filter($entries, static fn(array $entry): bool => $entry['uid'] === $uid));
        if ($matches === []) {
            return ['absent' => true];
        }
        if (count($matches) !== 1) {
            return ['deleted' => false, 'failure_code' => 'uid_ambiguous'];
        }
        if (!hash_equals($fingerprint, tp_sms_receive_fingerprint($matches[0]))) {
            // Alte Nachricht ist verschwunden, UID gehoert jetzt zu einem anderen Eintrag.
            // Dieser neue Eintrag bleibt bis zu seiner eigenen dauerhaften Aufnahme stehen.
            return ['absent' => true];
        }
        $raw = $matches[0]['raw'];
        if (!is_array($raw) || !(($raw['status_name'] ?? null) === 'received' && ($raw['status'] ?? null) === 6)
            && !(($raw['status_name'] ?? null) === 'sent' && ($raw['status'] ?? null) === 0)) {
            return ['deleted' => false, 'failure_code' => 'remote_state_unverified'];
        }
        return tp_sms_fritz_delete_sms($config, $sid, $uid);
    });
}

/** Ein begrenzter Zyklus; Caller ruft Versand erst nach erfolgreichem Journalabgleich auf. */
function tp_sms_receive_process_cycle(array $settings, ?callable $reader = null, ?callable $deleter = null): ?array
{
    if (!tp_sms_receive_enabled($settings)) {
        return null;
    }
    if (($settings['receive']['format'] ?? '') !== 'fritz-sms-list-assembled'
        || ($settings['receive']['output_mode'] ?? '') !== 'target-local') {
        throw new RuntimeException('SMS-Empfang benoetigt das bestaetigte Journalformat und target-local.');
    }
    if (($settings['default_provider'] ?? 'none') === 'fritz') {
        throw new RuntimeException('Bei SMS-Empfang muss der FRITZ!Box-Versand ueber die Queue laufen.');
    }
    $lock = tp_sms_queue_worker_lock($settings);
    if ($lock === null) {
        return null;
    }
    $result = [
        'ok' => false, 'stored' => 0, 'duplicates' => 0,
        'export' => null, 'reply' => null, 'cleanup' => null, 'failure_code' => null,
    ];
    try {
        tp_sms_receive_open($settings);
        $snapshot = null;
        try {
            $snapshot = $reader === null ? tp_sms_receive_read_snapshot($settings) : $reader($settings);
            if (!is_array($snapshot) || !is_string($snapshot['device_key'] ?? null)
                || !is_array($snapshot['entries'] ?? null)) {
                throw new RuntimeException('Ungueltiger Journalabgleich.');
            }
            $saved = tp_sms_receive_store($settings, $snapshot['device_key'], $snapshot['entries']);
            tp_sms_receive_reconcile_absent($settings, $snapshot['device_key'], $snapshot['entries']);
            $result['stored'] = $saved['stored'];
            $result['duplicates'] = $saved['duplicates'];
            $result['ok'] = true;
        } catch (Throwable $error) {
            $result['failure_code'] = 'journal_sync_failed';
        }
        // Bereits lokal gesicherte Eingänge bleiben auch bei Routerausfall bearbeitbar.
        try {
            $result['export'] = tp_sms_receive_export_one($settings);
            $result['reply'] = tp_sms_receive_reply_one($settings);
        } catch (Throwable $error) {
            $result['ok'] = false;
            $result['failure_code'] = 'local_processing_failed';
        }
        if ($result['ok']) {
            $result['cleanup'] = tp_sms_receive_cleanup_one(
                $settings, $snapshot['device_key'], $deleter ?? 'tp_sms_receive_delete_checked'
            );
        }
        return $result;
    } finally {
        flock($lock['handle'], LOCK_UN);
        fclose($lock['handle']);
    }
}

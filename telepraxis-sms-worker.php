#!/usr/bin/env php
<?php
/*
 * Eigenstaendiger CLI-Worker fuer SMS-Queue und optionalen Journalempfang.
 * Changelog 2026-09-29: Empfang, lokale Ausgabe und gesicherte Journalbereinigung integriert.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

define('TELEPRAXIS_SMS_CONFIG', true);
require_once __DIR__ . '/telepraxis-sms.php';
require_once __DIR__ . '/telepraxis-sms-queue.php';

function tp_sms_worker_usage(): string
{
    return "Verwendung: php telepraxis-sms-worker.php --config /absolut/sms-credentials.json [--once|--init|--status]\n"
        . "  --once    ein Journalzyklus (falls aktiviert), höchstens ein Sende- und Löschauftrag\n"
        . "  --init    Queue-Datenbank initialisieren, nichts versenden\n"
        . "  --status  nur Auftragszahlen je Status ausgeben\n"
        . "  --help    diese Hilfe anzeigen\n";
}

/** @return array{config:?string,action:string} */
function tp_sms_worker_arguments(array $arguments): array
{
    $config = null;
    $actions = [];
    for ($index = 1, $count = count($arguments); $index < $count; $index++) {
        $argument = $arguments[$index];
        if ($argument === '--help') {
            $actions[] = 'help';
        } elseif ($argument === '--once') {
            $actions[] = 'once';
        } elseif ($argument === '--init') {
            $actions[] = 'init';
        } elseif ($argument === '--status') {
            $actions[] = 'status';
        } elseif ($argument === '--config') {
            $index++;
            if ($index >= $count) {
                throw new RuntimeException('Nach --config fehlt die Konfigurationsdatei.');
            }
            $config = $arguments[$index];
        } elseif (str_starts_with($argument, '--config=')) {
            $config = substr($argument, strlen('--config='));
        } else {
            throw new RuntimeException('Unbekannte Kommandozeilenoption.');
        }
    }
    if (in_array('help', $actions, true)) {
        return ['config' => $config, 'action' => 'help'];
    }
    if (count($actions) > 1) {
        throw new RuntimeException('--once, --init und --status schliessen einander aus.');
    }
    return ['config' => $config, 'action' => $actions[0] ?? 'run'];
}

function tp_sms_worker_load_config(?string $path): array
{
    if ($path === null || $path === '') {
        throw new RuntimeException('--config ist erforderlich.');
    }
    if (!tp_sms_queue_is_absolute_path($path) || !is_file($path) || is_link($path) || !is_readable($path)) {
        throw new RuntimeException('Die Konfigurationsdatei ist ungueltig oder nicht lesbar.');
    }
    $json = @file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('Die Konfigurationsdatei konnte nicht gelesen werden.');
    }
    $loaded = json_decode($json, true);
    if (!is_array($loaded)) {
        throw new RuntimeException('Die Konfigurationsdatei enthaelt kein gueltiges JSON.');
    }
    return tp_sms_merge_settings(tp_sms_default_settings(), $loaded);
}

function tp_sms_worker_print_counts(array $counts): void
{
    foreach (TP_SMS_QUEUE_STATUSES as $status) {
        fwrite(STDOUT, $status . ': ' . (int)($counts[$status] ?? 0) . PHP_EOL);
    }
}

function tp_sms_worker_print_cleanup(?array $cleanup): void
{
    if ($cleanup === null) {
        return;
    }
    $line = 'Löschauftrag ' . $cleanup['cleanup_id'] . ': ' . $cleanup['status'];
    if ($cleanup['failure_code'] !== null) {
        $line .= ' (' . $cleanup['failure_code'] . ')';
    }
    fwrite(STDOUT, $line . PHP_EOL);
}

function tp_sms_worker_print_receive(?array $received): void
{
    if ($received === null) {
        return;
    }
    if (!$received['ok']) {
        fwrite(STDOUT, 'SMS-Journal: ' . $received['failure_code'] . PHP_EOL);
    }
    if ($received['stored'] > 0) {
        fwrite(STDOUT, 'Journal lokal gesichert: ' . (int)$received['stored'] . PHP_EOL);
    }
    foreach (['export' => 'state', 'reply' => 'reply_status', 'cleanup' => 'cleanup_status'] as $part => $status) {
        $operation = $received[$part] ?? null;
        if (is_array($operation)) {
            fwrite(STDOUT, 'SMS-Empfang ' . $part . ': ' . $operation[$status]
                . (!empty($operation['failure_code']) ? ' (' . $operation['failure_code'] . ')' : '') . PHP_EOL);
        }
    }
}

$exitCode = 0;
$receiveEnabled = false;
try {
    $arguments = tp_sms_worker_arguments($argv);
    if ($arguments['action'] === 'help') {
        fwrite(STDOUT, tp_sms_worker_usage());
        exit(0);
    }
    $settings = tp_sms_worker_load_config($arguments['config']);
    $receiveEnabled = is_array($settings['receive'] ?? null) && ($settings['receive']['enabled'] ?? false) === true;
    if ($receiveEnabled) {
        require_once __DIR__ . '/telepraxis-sms-receive.php';
    }

    if ($arguments['action'] === 'init') {
        tp_sms_queue_open($settings, true);
        if ($receiveEnabled) {
            tp_sms_receive_open($settings);
        }
        fwrite(STDOUT, "SMS-Queue initialisiert.\n");
        exit(0);
    }
    if ($arguments['action'] === 'status') {
        tp_sms_worker_print_counts(tp_sms_queue_status_counts($settings));
        fwrite(STDOUT, 'cleanup_pending: ' . tp_sms_queue_cleanup_pending_count($settings) . PHP_EOL);
        if ($receiveEnabled) {
            foreach (tp_sms_receive_status_counts($settings) as $name => $count) {
                fwrite(STDOUT, $name . ': ' . (int)$count . PHP_EOL);
            }
        }
        exit(0);
    }
    if ($arguments['action'] === 'once') {
        $received = $receiveEnabled ? tp_sms_receive_process_cycle($settings) : null;
        $canSend = !$receiveEnabled || ($received !== null && $received['ok']);
        $result = $canSend ? tp_sms_queue_process_one($settings) : null;
        // Im Empfangsmodus sichert und prueft der Journalabgleich auch Sendeeintraege.
        $cleanup = $receiveEnabled ? null : tp_sms_queue_process_cleanup_one($settings);
        tp_sms_worker_print_receive($received);
        if ($result === null && $cleanup === null) {
            fwrite(STDOUT, "Kein Auftrag vorhanden.\n");
        } elseif ($result !== null) {
            fwrite(STDOUT, 'Auftrag ' . $result['job_id'] . ': ' . $result['status'] . PHP_EOL);
        }
        tp_sms_worker_print_cleanup($cleanup);
        $sendFailed = $result !== null && in_array($result['status'], ['failed', 'uncertain'], true);
        $cleanupFailures = tp_sms_queue_cleanup_failure_count($settings);
        if ($cleanupFailures > 0) {
            fwrite(STDOUT, 'Ausstehende Löschfehler: ' . $cleanupFailures . PHP_EOL);
        }
        $cleanupFailed = $cleanupFailures > 0;
        $receiveFailed = $received !== null && !$received['ok'];
        if ($receiveEnabled) {
            $counts = tp_sms_receive_status_counts($settings);
            $receiveFailed = $receiveFailed || $counts['received_held'] > 0 || $counts['reply_error'] > 0
                || $counts['journal_unclassified'] > 0 || $counts['journal_cleanup_failed'] > 0;
            foreach (['export', 'reply', 'cleanup'] as $phase) {
                $receiveFailed = $receiveFailed || !empty($received[$phase]['failure_code']);
            }
        }
        exit($sendFailed || $cleanupFailed || $receiveFailed ? 1 : 0);
    }

    $running = true;
    if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
        pcntl_async_signals(true);
        $stop = static function () use (&$running): void {
            $running = false;
        };
        pcntl_signal(SIGTERM, $stop);
        pcntl_signal(SIGINT, $stop);
    }
    $queue = $settings['queue'] ?? [];
    $pollSeconds = is_array($queue) ? (float)($queue['poll_interval_seconds'] ?? 2) : 2.0;
    $pollSeconds = max(0.1, min(2.0, $pollSeconds));
    while ($running) {
        $received = $receiveEnabled ? tp_sms_receive_process_cycle($settings) : null;
        $canSend = !$receiveEnabled || ($received !== null && $received['ok']);
        $result = $canSend ? tp_sms_queue_process_one($settings) : null;
        $cleanup = $receiveEnabled ? null : tp_sms_queue_process_cleanup_one($settings);
        tp_sms_worker_print_receive($received);
        if ($result === null && $cleanup === null) {
            usleep((int)round($pollSeconds * 1000000));
        }
        if ($result !== null) {
            fwrite(STDOUT, 'Auftrag ' . $result['job_id'] . ': ' . $result['status'] . PHP_EOL);
        }
        tp_sms_worker_print_cleanup($cleanup);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, 'SMS-Worker-Fehler: '
        . ($receiveEnabled ? 'Empfangsverarbeitung fehlgeschlagen.' : $exception->getMessage()) . PHP_EOL);
    $exitCode = 1;
}
exit($exitCode);

#!/usr/bin/env php
<?php
/*
 * Lesende Strukturdiagnose fuer das FRITZ!Box-SMS-Journal.
 * Changelog 2026-09-29: Separater CLI-Abruf ohne Versand, Loeschen oder Rohdatenexport.
 * Das echte Empfangs-/Multipartformat ist damit noch nicht implementiert.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (!defined('TELEPRAXIS_SMS_CONFIG')) {
    define('TELEPRAXIS_SMS_CONFIG', true);
}
require_once __DIR__ . '/telepraxis-sms.php';

function tp_sms_inspect_usage(): string
{
    return "Verwendung: php telepraxis-sms-inspect.php --config /absolut/sms-credentials.json\n"
        . "  Liest einmal die SMS-Journalseite; kein Versand, Loeschen oder Queue-Zugriff.\n"
        . "  Ausgabe: Feldstruktur, Typen, Textlaengen und lokale Vergleichskennungen.\n"
        . "  --help    diese Hilfe anzeigen, ohne Routerzugriff\n";
}

function tp_sms_inspect_config(array $arguments): array
{
    $path = null;
    for ($index = 1; $index < count($arguments); $index++) {
        $argument = $arguments[$index];
        if ($path !== null) {
            throw new RuntimeException('arguments');
        }
        if ($argument === '--config' && isset($arguments[$index + 1])) {
            $path = $arguments[++$index];
        } elseif (str_starts_with($argument, '--config=')) {
            $path = substr($argument, strlen('--config='));
        } else {
            throw new RuntimeException('arguments');
        }
    }
    if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, "\0")
        || is_link($path) || !is_file($path) || !is_readable($path)) {
        throw new RuntimeException('config');
    }
    $loaded = json_decode((string)file_get_contents($path), false, 64, JSON_THROW_ON_ERROR);
    if (!$loaded instanceof stdClass || !isset($loaded->fritzbox) || !$loaded->fritzbox instanceof stdClass) {
        throw new RuntimeException('config');
    }
    // Ausschliesslich den Routerabschnitt lesen; keine Default-Credentials/Queue oeffnen.
    return array_replace(tp_sms_default_settings()['fritzbox'], (array)$loaded->fritzbox);
}

/** Unbekannte Objektschluessel koennen selbst Rufnummern oder Freitext enthalten. */
function tp_sms_inspect_field_name(string $key, array &$keys): string
{
    static $known = [
        'data', 'page', 'smsList', 'smsListData', 'sms', 'smslist', 'sms_list', 'messages', 'message',
        'messageList', 'message_list', 'list', 'entries', 'items', 'inbox', 'outbox',
        'received', 'sent', 'incoming', 'outgoing', 'id', 'uid', 'messageId',
        'message_id', 'messageID', 'new_uid', 'sender', 'recipient', 'receiver', 'number',
        'phone', 'phoneNumber', 'phone_number', 'from', 'to', 'text', 'body',
        'content', 'msg', 'newMessage', 'date', 'time', 'timestamp', 'datetime',
        'received_at', 'created_at', 'status', 'status_name', 'state', 'type', 'direction',
        'answerable', 'tfaEnabled',
        'read', 'unread', 'isRead', 'isUnread', 'count', 'total', 'offset', 'limit',
        'more', 'hasMore', 'next', 'parts', 'part', 'partNumber', 'part_number',
        'partCount', 'part_count', 'totalParts', 'total_parts', 'sequence', 'index',
        'reference', 'ref', 'group', 'groupId', 'group_id', 'concat', 'concatenation',
        'multipart', 'udh', 'pdu', 'encoding', 'dcs', 'error', 'errors', 'code',
        'err', 'ok', 'result', 'success', 'serial', 'version', 'firmware',
    ];
    if (in_array($key, $known, true)) {
        return $key;
    }
    // Keine Hashwerte ausgeben: Vergleichskennungen gelten nur fuer diesen Abruf.
    if (!isset($keys[$key])) {
        $keys[$key] = 'field-' . (count($keys) + 1);
    }
    return $keys[$key];
}

/** Keine Scalar-Werte ausgeben, auch nicht unbekannte Metadaten oder Fehlertexte. */
function tp_sms_inspect_shape(mixed $value, array &$values, array &$keys, int $depth = 0): array
{
    if ($depth > 32) {
        return ['type' => 'depth_limit'];
    }
    if ($value instanceof stdClass) {
        $fields = [];
        foreach (get_object_vars($value) as $key => $child) {
            $key = (string)$key;
            // Sitzungs- und Authentifizierungsdaten komplett auslassen.
            if (preg_match('/sid|token|secret|password|challenge|cookie|auth/i', $key)) {
                continue;
            }
            $fields[tp_sms_inspect_field_name($key, $keys)] = tp_sms_inspect_shape($child, $values, $keys, $depth + 1);
        }
        return ['type' => 'object', 'fields' => (object)$fields];
    }
    if (is_array($value)) {
        $items = [];
        foreach ($value as $child) {
            $items[] = tp_sms_inspect_shape($child, $values, $keys, $depth + 1);
        }
        return ['type' => 'array', 'count' => count($value), 'items' => $items];
    }
    if ($value === null) {
        return ['type' => 'null'];
    }
    // Nur interne Vergleiche; keine deterministischen Hashes privater Werte exportieren.
    $identity = get_debug_type($value) . ':' . json_encode($value, JSON_THROW_ON_ERROR);
    if (!isset($values[$identity])) {
        $values[$identity] = 'value-' . (count($values) + 1);
    }
    $shape = ['type' => get_debug_type($value), 'ref' => $values[$identity]];
    if (is_string($value)) {
        $shape['utf16_units'] = intdiv(strlen(tp_sms_utf16le($value)), 2);
    }
    return $shape;
}

function tp_sms_inspect_journal(array $config): stdClass
{
    $sid = '';
    $cookieFile = tempnam(sys_get_temp_dir(), 'tp-sms-inspect-');
    if ($cookieFile === false) {
        throw new RuntimeException('session');
    }
    try {
        if (!chmod($cookieFile, 0600)) {
            throw new RuntimeException('session');
        }
        $config['_cookie_file'] = $cookieFile;
        $sid = tp_sms_fritz_login($config);
        // Feste Leseanfrage. Keine vom Router gelieferten Aktionen/URLs ausfuehren.
        $response = tp_sms_http_request('POST', tp_sms_fritz_url($config['host'], 'data.lua'), [
            'timeout' => (int)$config['timeout_seconds'],
            'verify_tls' => (bool)$config['verify_tls'],
            'cookie_file' => $cookieFile,
            'data' => ['sid' => $sid, 'page' => 'smsList', 'xhr' => '1'],
        ]);
        if ($response['status'] !== 200) {
            throw new RuntimeException('journal');
        }
        $body = json_decode($response['body'], false, 64, JSON_THROW_ON_ERROR);
        if (!$body instanceof stdClass) {
            throw new RuntimeException('journal');
        }
        if (isset($body->sid) && is_string($body->sid)) {
            $sid = tp_sms_fritz_update_sid($sid, ['sid' => $body->sid]);
        }
        if (!isset($body->data) || (!is_array($body->data) && !$body->data instanceof stdClass)) {
            throw new RuntimeException('journal');
        }
        return $body;
    } finally {
        try {
            tp_sms_fritz_logout($config, $sid);
        } finally {
            if (is_file($cookieFile)) {
                unlink($cookieFile);
            }
        }
    }
}

function tp_sms_inspect_main(array $arguments): int
{
    if (in_array('--help', array_slice($arguments, 1), true)) {
        fwrite(STDOUT, tp_sms_inspect_usage());
        return 0;
    }
    $stage = 'Konfiguration';
    // PHP-/cURL-Warnungen koennen private Hostnamen/Pfade enthalten.
    set_error_handler(static function (int $severity): bool {
        // Die bestehende Transportbibliothek verwendet unter PHP 8.5 noch curl_close().
        // Deprecations ohne Ausgabe unterdruecken; operative Fehler bleiben Fehler.
        if (in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], true)) {
            return true;
        }
        throw new RuntimeException('diagnostic');
    });
    try {
        $config = tp_sms_inspect_config($arguments);
        $stage = 'Routerabruf';
        $body = tp_sms_inspect_journal($config);
        $stage = 'Strukturausgabe';
        $values = [];
        $keys = [];
        $report = [
            'diagnostic' => 'fritz-sms-journal-structure',
            'multipart_verified' => false,
            'note' => 'Einzelabruf; keine Vollstaendigkeitsgarantie. Vergleichskennungen gelten nur in dieser Ausgabe.',
            'response' => tp_sms_inspect_shape($body, $values, $keys),
        ];
        fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL);
        return 0;
    } catch (Throwable $error) {
        // Absichtlich keine Exception-Nachricht und keinen Stacktrace ausgeben.
        fwrite(STDERR, 'SMS-Strukturdiagnose fehlgeschlagen (' . $stage . '). Keine Rohdaten ausgegeben.' . PHP_EOL);
        return 1;
    } finally {
        restore_error_handler();
    }
}

if (!defined('TELEPRAXIS_SMS_INSPECT_LIBRARY')) {
    exit(tp_sms_inspect_main($argv));
}

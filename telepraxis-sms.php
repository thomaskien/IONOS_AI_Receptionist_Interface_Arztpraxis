<?php
/*
 * telepraxis-sms.php
 * Version: 0.2.0 (2026-06-25)
 *
 * Changelog:
 * - Credentials-Pfad auf patchbare absolute Datei ausserhalb des Webroots vorbereitet.
 * - SMS-Funktionsdatei fuer telepraxis-app.php erstellt.
 * - Provider none, seven.io und FRITZ!Box aus sms.php ohne Testoberflaeche uebernommen.
 * - FRITZ!Box-Versand mit TOTP, Session-Cookies und optionalem Loeschen gesendeter SMS.
 *
 * Diese Datei ist nur als Include gedacht und wird nicht direkt aufgerufen.
 */

declare(strict_types=1);

if (!defined('TELEPRAXIS_APP') && !defined('TELEPRAXIS_SMS_CONFIG')) {
    http_response_code(404);
    exit;
}

const TP_SMS_CREDENTIALS_FILE = 'sms-credentials.json';

function tp_sms_credentials_path(): string
{
    if (strpos(TP_SMS_CREDENTIALS_FILE, DIRECTORY_SEPARATOR) === 0) {
        return TP_SMS_CREDENTIALS_FILE;
    }
    return __DIR__ . '/' . TP_SMS_CREDENTIALS_FILE;
}

function tp_sms_default_settings(): array
{
    return [
        'default_provider' => 'none',
        'sms' => [
            'max_text_length' => 612,
        ],
        'seven' => [
            'api_key' => '',
            'from' => 'Telepraxis',
            'endpoint' => 'https://gateway.seven.io/api/sms',
            'timeout_seconds' => 15,
        ],
        'fritzbox' => [
            'host' => 'fritz.box',
            'username' => '',
            'password' => '',
            'totp_secret' => '',
            'totp_digits' => 6,
            'totp_period' => 30,
            'timeout_seconds' => 20,
            'verify_tls' => false,
            'delete_after_send' => true,
        ],
    ];
}

function tp_sms_merge_settings(array $base, array $override): array
{
    foreach ($override as $key => $value) {
        if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
            $base[$key] = tp_sms_merge_settings($base[$key], $value);
            continue;
        }
        $base[$key] = $value;
    }
    return $base;
}

function tp_sms_load_settings(): array
{
    $settings = tp_sms_default_settings();
    $path = tp_sms_credentials_path();
    if (!is_file($path)) {
        return $settings;
    }

    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('SMS-Credentials konnten nicht gelesen werden.');
    }

    $loaded = json_decode($json, true);
    if (!is_array($loaded)) {
        throw new RuntimeException('SMS-Credentials sind kein gueltiges JSON.');
    }

    return tp_sms_merge_settings($settings, $loaded);
}

function tp_sms_is_placeholder(?string $value): bool
{
    $value = trim((string)$value);
    return $value === '' || strpos($value, '###CHANGE_ME') !== false;
}

function tp_sms_require_config_value(array $config, string $key, string $label): string
{
    $value = trim((string)($config[$key] ?? ''));
    if (tp_sms_is_placeholder($value)) {
        throw new RuntimeException($label . ' fehlt in ' . TP_SMS_CREDENTIALS_FILE . '.');
    }
    return $value;
}

function tp_sms_text_length(string $text): int
{
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($text, 'UTF-8');
    }
    return strlen($text);
}

function tp_sms_provider_from_value(string $provider): string
{
    return in_array($provider, ['none', 'seven', 'fritz'], true) ? $provider : 'none';
}

function tp_sms_build_url(string $url, array $params): string
{
    if ($params === []) {
        return $url;
    }
    $separator = strpos($url, '?') === false ? '?' : '&';
    return $url . $separator . http_build_query($params, '', '&');
}

function tp_sms_http_request(string $method, string $url, array $options = []): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP-curl ist nicht verfuegbar.');
    }

    $method = strtoupper($method);
    $timeout = (int)($options['timeout'] ?? 15);
    $headers = $options['headers'] ?? [];
    $data = $options['data'] ?? null;
    $verifyTls = (bool)($options['verify_tls'] ?? true);
    $cookieFile = (string)($options['cookie_file'] ?? '');

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('HTTP-Client konnte nicht initialisiert werden.');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min($timeout, 10));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifyTls);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifyTls ? 2 : 0);
    if ($cookieFile !== '') {
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data, '', '&') : (string)$data);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP-Anfrage fehlgeschlagen: ' . $error);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [
        'status' => $status,
        'content_type' => $contentType,
        'body' => (string)$body,
    ];
}

function tp_sms_decode_json(string $body, string $context): array
{
    $data = json_decode($body, true);
    if (!is_array($data)) {
        throw new RuntimeException($context . ': Antwort ist kein gueltiges JSON.');
    }
    return $data;
}

function tp_sms_parse_xml_text(string $body, string $name, string $context): string
{
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($xml === false) {
        throw new RuntimeException($context . ': Antwort ist kein gueltiges XML.');
    }

    $value = trim((string)($xml->{$name} ?? ''));
    if ($value === '') {
        throw new RuntimeException($context . ': XML-Feld ' . $name . ' fehlt.');
    }
    return $value;
}

function tp_sms_utf16le(string $value): string
{
    if (function_exists('mb_convert_encoding')) {
        return mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
    }
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'UTF-16LE//IGNORE', $value);
        if ($converted !== false) {
            return $converted;
        }
    }
    throw new RuntimeException('Fuer den FRITZ!Box-Login fehlt mbstring oder iconv.');
}

function tp_sms_fritz_challenge_response(string $challenge, string $password): string
{
    if (strpos($challenge, '2$') === 0) {
        return tp_sms_fritz_pbkdf2_response($challenge, $password);
    }
    $hash = strtolower(hash('md5', tp_sms_utf16le($challenge . '-' . $password)));
    return $challenge . '-' . $hash;
}

function tp_sms_fritz_pbkdf2_response(string $challenge, string $password): string
{
    $parts = explode('$', $challenge);
    if (count($parts) < 5 || $parts[0] !== '2') {
        throw new RuntimeException('Unbekanntes FRITZ!Box-Challenge-Format.');
    }

    $iterations1 = (int)$parts[1];
    $salt1 = hex2bin($parts[2]);
    $iterations2 = (int)$parts[3];
    $salt2 = hex2bin($parts[4]);
    if ($iterations1 <= 0 || $iterations2 <= 0 || $salt1 === false || $salt2 === false) {
        throw new RuntimeException('Ungueltige FRITZ!Box-PBKDF2-Challenge.');
    }

    $hash1 = hash_pbkdf2('sha256', $password, $salt1, $iterations1, 32, true);
    $hash2 = hash_pbkdf2('sha256', $hash1, $salt2, $iterations2, 32, true);
    return $challenge . '$' . bin2hex($hash2);
}

function tp_sms_fritz_url(string $host, string $path): string
{
    $host = trim($host);
    if ($host === '') {
        throw new RuntimeException('FRITZ!Box-Host fehlt in ' . TP_SMS_CREDENTIALS_FILE . '.');
    }
    if (!preg_match('#^https?://#i', $host)) {
        $host = 'http://' . $host;
    }
    return rtrim($host, '/') . '/' . ltrim($path, '/');
}

function tp_sms_fritz_request(array $config, string $path, array $data, string $context): array
{
    $host = tp_sms_require_config_value($config, 'host', 'FRITZ!Box-Host');
    $response = tp_sms_http_request('POST', tp_sms_fritz_url($host, $path), [
        'timeout' => (int)($config['timeout_seconds'] ?? 20),
        'verify_tls' => (bool)($config['verify_tls'] ?? false),
        'cookie_file' => (string)($config['_cookie_file'] ?? ''),
        'data' => $data,
    ]);
    if ($response['status'] !== 200) {
        throw new RuntimeException($context . ': FRITZ!Box antwortet mit HTTP ' . $response['status'] . '.');
    }
    return tp_sms_decode_json($response['body'], $context);
}

function tp_sms_fritz_login(array $config): string
{
    $host = tp_sms_require_config_value($config, 'host', 'FRITZ!Box-Host');
    $username = tp_sms_require_config_value($config, 'username', 'FRITZ!Box-Benutzer');
    $password = tp_sms_require_config_value($config, 'password', 'FRITZ!Box-Passwort');
    $timeout = (int)($config['timeout_seconds'] ?? 20);
    $verifyTls = (bool)($config['verify_tls'] ?? false);
    $loginUrl = tp_sms_fritz_url($host, 'login_sid.lua');

    $challengeResponse = tp_sms_http_request('GET', $loginUrl, [
        'timeout' => $timeout,
        'verify_tls' => $verifyTls,
        'cookie_file' => (string)($config['_cookie_file'] ?? ''),
    ]);
    if ($challengeResponse['status'] !== 200) {
        throw new RuntimeException('FRITZ!Box-Login: HTTP ' . $challengeResponse['status'] . ' beim Challenge-Abruf.');
    }

    $challenge = tp_sms_parse_xml_text($challengeResponse['body'], 'Challenge', 'FRITZ!Box-Login');
    $responseValue = tp_sms_fritz_challenge_response($challenge, $password);
    $loginResponse = tp_sms_http_request('POST', $loginUrl, [
        'timeout' => $timeout,
        'verify_tls' => $verifyTls,
        'cookie_file' => (string)($config['_cookie_file'] ?? ''),
        'data' => [
            'username' => $username,
            'response' => $responseValue,
        ],
    ]);
    if ($loginResponse['status'] !== 200) {
        throw new RuntimeException('FRITZ!Box-Login: HTTP ' . $loginResponse['status'] . ' beim Login.');
    }

    $sid = tp_sms_parse_xml_text($loginResponse['body'], 'SID', 'FRITZ!Box-Login');
    if ($sid === '0000000000000000') {
        throw new RuntimeException('FRITZ!Box-Login fehlgeschlagen.');
    }
    return $sid;
}

function tp_sms_fritz_logout(array $config, string $sid): void
{
    if ($sid === '' || $sid === '0000000000000000') {
        return;
    }
    $host = (string)($config['host'] ?? 'fritz.box');
    $url = tp_sms_build_url(tp_sms_fritz_url($host, 'login_sid.lua'), [
        'sid' => $sid,
        'logout' => '1',
    ]);
    try {
        tp_sms_http_request('GET', $url, [
            'timeout' => (int)($config['timeout_seconds'] ?? 20),
            'verify_tls' => (bool)($config['verify_tls'] ?? false),
            'cookie_file' => (string)($config['_cookie_file'] ?? ''),
        ]);
    } catch (Throwable $ignored) {
    }
}

function tp_sms_fritz_update_sid(string $sid, array $data): string
{
    $newSid = (string)($data['sid'] ?? '');
    if ($newSid !== '' && $newSid !== '0000000000000000') {
        return $newSid;
    }
    return $sid;
}

function tp_sms_safe_debug_value($value)
{
    if (is_array($value)) {
        $safe = [];
        foreach ($value as $key => $child) {
            $keyString = strtolower((string)$key);
            if (preg_match('/sid|token|secret|password|recipient|message|uid/', $keyString)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            $safe[$key] = tp_sms_safe_debug_value($child);
        }
        return $safe;
    }
    if (is_string($value) && strlen($value) > 180) {
        return substr($value, 0, 180) . '...';
    }
    return $value;
}

function tp_sms_safe_debug_json(array $data): string
{
    $json = json_encode(tp_sms_safe_debug_value($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? $json : '{}';
}

function tp_sms_boolish($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value)) {
        return $value === 1;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function tp_sms_base32_decode(string $secret): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = strtoupper(preg_replace('/[\s=]+/', '', $secret) ?? '');
    if ($secret === '') {
        throw new RuntimeException('TOTP-Secret fehlt.');
    }

    $bits = '';
    $length = strlen($secret);
    for ($i = 0; $i < $length; $i++) {
        $position = strpos($alphabet, $secret[$i]);
        if ($position === false) {
            throw new RuntimeException('TOTP-Secret enthaelt ungueltige Base32-Zeichen.');
        }
        $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
    }

    $bytes = '';
    $bitLength = strlen($bits);
    for ($i = 0; $i + 8 <= $bitLength; $i += 8) {
        $bytes .= chr(bindec(substr($bits, $i, 8)));
    }
    return $bytes;
}

function tp_sms_totp_now(string $secret, int $digits = 6, int $period = 30): string
{
    $digits = max(6, min(8, $digits));
    $period = max(15, $period);
    $key = tp_sms_base32_decode($secret);
    $counter = intdiv(time(), $period);
    $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);
    $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
    $code = ((ord($hash[$offset]) & 0x7f) << 24)
        | ((ord($hash[$offset + 1]) & 0xff) << 16)
        | ((ord($hash[$offset + 2]) & 0xff) << 8)
        | (ord($hash[$offset + 3]) & 0xff);
    $modulo = (int)pow(10, $digits);
    return str_pad((string)($code % $modulo), $digits, '0', STR_PAD_LEFT);
}

function tp_sms_fritz_confirm_totp(array $config, string &$sid, string $recipient, string $message, string $newUid): void
{
    $info = tp_sms_fritz_request($config, 'twofactor.lua', [
        'sid' => $sid,
        'tfa_googleauth_info' => '',
        'no_sidrenew' => '',
    ], 'FRITZ!Box-TOTP-Status');

    $googleAuth = $info['googleauth'] ?? [];
    if (!is_array($googleAuth) || !tp_sms_boolish($googleAuth['isConfigured'] ?? false)) {
        throw new RuntimeException('FRITZ!Box-TOTP ist nicht konfiguriert.');
    }
    if (!tp_sms_boolish($googleAuth['isAvailable'] ?? false)) {
        throw new RuntimeException('FRITZ!Box-TOTP ist nicht verfuegbar.');
    }

    $totpSecret = tp_sms_require_config_value($config, 'totp_secret', 'FRITZ!Box-TOTP-Secret');
    $totp = tp_sms_totp_now($totpSecret, (int)($config['totp_digits'] ?? 6), (int)($config['totp_period'] ?? 30));
    $totpResult = tp_sms_fritz_request($config, 'twofactor.lua', [
        'sid' => $sid,
        'tfa_googleauth' => $totp,
        'no_sidrenew' => '',
    ], 'FRITZ!Box-TOTP-Bestaetigung');

    if ((int)($totpResult['err'] ?? 1) !== 0) {
        throw new RuntimeException('FRITZ!Box-TOTP wurde nicht akzeptiert.');
    }
    $sid = tp_sms_fritz_update_sid($sid, $totpResult);

    try {
        $active = tp_sms_fritz_request($config, 'twofactor.lua', [
            'sid' => $sid,
            'tfa_active' => '',
            'no_sidrenew' => '',
        ], 'FRITZ!Box-TOTP-Status nach Code');
        $sid = tp_sms_fritz_update_sid($sid, $active);
    } catch (Throwable $ignored) {
    }

    $final = tp_sms_fritz_request($config, 'data.lua', [
        'sid' => $sid,
        'page' => 'smsSendMsg',
        'recipient' => $recipient,
        'newMessage' => $message,
        'new_uid' => $newUid,
        'second_apply' => '1',
        'confirmed' => '1',
        'twofactor' => '1',
    ], 'FRITZ!Box-SMS-Abschluss');

    $sid = tp_sms_fritz_update_sid($sid, $final);
    if (($final['data']['second_apply'] ?? '') !== 'ok') {
        throw new RuntimeException('FRITZ!Box-SMS wurde nach TOTP nicht bestaetigt. Antwort: ' . tp_sms_safe_debug_json($final));
    }
}

function tp_sms_fritz_delete_sms(array $config, string &$sid, string $messageId): array
{
    $messageId = trim($messageId);
    if ($messageId === '') {
        throw new RuntimeException('FRITZ!Box-SMS kann nicht geloescht werden: messageId fehlt.');
    }

    $delete = tp_sms_fritz_request($config, 'data.lua', [
        'sid' => $sid,
        'page' => 'smsList',
        'messageId' => $messageId,
        'delete' => '',
    ], 'FRITZ!Box-SMS-Loeschen');

    $sid = tp_sms_fritz_update_sid($sid, $delete);
    if (($delete['data']['delete'] ?? '') !== 'ok') {
        throw new RuntimeException('FRITZ!Box-SMS wurde versendet, konnte aber nicht geloescht werden. Antwort: ' . tp_sms_safe_debug_json($delete));
    }

    return [
        'deleted' => true,
        'message_id' => $messageId,
    ];
}

function tp_sms_send_fritz(array $settings, string $recipient, string $message): array
{
    $config = $settings['fritzbox'] ?? [];
    if (!is_array($config)) {
        throw new RuntimeException('FRITZ!Box-Konfiguration fehlt.');
    }

    $sid = '';
    $cookieFile = tempnam(sys_get_temp_dir(), 'tp-sms-fritz-');
    if ($cookieFile === false) {
        throw new RuntimeException('Temporaere FRITZ!Box-Session konnte nicht angelegt werden.');
    }
    @chmod($cookieFile, 0600);
    $config['_cookie_file'] = $cookieFile;
    $deleteAfterSend = !empty($config['delete_after_send']);

    try {
        $sid = tp_sms_fritz_login($config);
        $initial = tp_sms_fritz_request($config, 'data.lua', [
            'sid' => $sid,
            'page' => 'smsSendMsg',
            'recipient' => $recipient,
            'newMessage' => $message,
            'apply' => 'true',
        ], 'FRITZ!Box-SMS-Start');

        $sid = tp_sms_fritz_update_sid($sid, $initial);
        $newUid = (string)($initial['data']['new_uid'] ?? '');
        if ($newUid === '') {
            return [
                'ok' => true,
                'provider' => 'fritz',
                'message' => 'FRITZ!Box hat die SMS-Anfrage angenommen.',
                'details' => [
                    'twofactor' => false,
                    'delete_after_send' => $deleteAfterSend,
                    'delete' => [
                        'deleted' => false,
                        'reason' => 'FRITZ!Box hat keine messageId/new_uid geliefert.',
                    ],
                ],
            ];
        }

        $second = tp_sms_fritz_request($config, 'data.lua', [
            'sid' => $sid,
            'page' => 'smsSendMsg',
            'recipient' => $recipient,
            'newMessage' => $message,
            'new_uid' => $newUid,
            'second_apply' => '',
        ], 'FRITZ!Box-SMS-2FA-Anforderung');

        $sid = tp_sms_fritz_update_sid($sid, $second);
        if (($second['data']['second_apply'] ?? '') !== 'twofactor') {
            throw new RuntimeException('FRITZ!Box hat keine erwartete TOTP-Anforderung geliefert.');
        }

        tp_sms_fritz_confirm_totp($config, $sid, $recipient, $message, $newUid);
        $deleteInfo = [
            'deleted' => false,
            'reason' => 'delete_after_send ist deaktiviert.',
        ];
        if ($deleteAfterSend) {
            $deleteInfo = tp_sms_fritz_delete_sms($config, $sid, $newUid);
        }

        return [
            'ok' => true,
            'provider' => 'fritz',
            'message' => $deleteAfterSend
                ? 'FRITZ!Box hat die SMS angenommen und lokal geloescht.'
                : 'FRITZ!Box hat die SMS angenommen.',
            'details' => [
                'twofactor' => true,
                'message_uid' => $newUid,
                'delete_after_send' => $deleteAfterSend,
                'delete' => $deleteInfo,
            ],
        ];
    } finally {
        tp_sms_fritz_logout($config, $sid);
        @unlink($cookieFile);
    }
}

function tp_sms_send_seven(array $settings, string $recipient, string $message): array
{
    $config = $settings['seven'] ?? [];
    if (!is_array($config)) {
        throw new RuntimeException('seven.io-Konfiguration fehlt.');
    }

    $apiKey = tp_sms_require_config_value($config, 'api_key', 'seven.io API-Key');
    $endpoint = trim((string)($config['endpoint'] ?? 'https://gateway.seven.io/api/sms'));
    if ($endpoint === '') {
        $endpoint = 'https://gateway.seven.io/api/sms';
    }

    $payload = [
        'to' => $recipient,
        'text' => $message,
    ];
    $from = trim((string)($config['from'] ?? ''));
    if (!tp_sms_is_placeholder($from)) {
        $payload['from'] = $from;
    }

    $response = tp_sms_http_request('POST', $endpoint, [
        'timeout' => (int)($config['timeout_seconds'] ?? 15),
        'headers' => [
            'X-Api-Key: ' . $apiKey,
            'Accept: application/json',
        ],
        'data' => $payload,
    ]);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException('seven.io antwortet mit HTTP ' . $response['status'] . '.');
    }

    $data = tp_sms_decode_json($response['body'], 'seven.io');
    $messages = $data['messages'] ?? [];
    $safeMessages = [];
    if (is_array($messages)) {
        foreach ($messages as $item) {
            if (!is_array($item)) {
                continue;
            }
            $safeMessages[] = [
                'id' => $item['id'] ?? null,
                'recipient' => $item['recipient'] ?? null,
                'success' => $item['success'] ?? null,
                'parts' => $item['parts'] ?? null,
                'encoding' => $item['encoding'] ?? null,
                'price' => $item['price'] ?? null,
                'error_text' => $item['error_text'] ?? null,
            ];
        }
    }

    $successCode = (string)($data['success'] ?? '');
    $ok = $successCode === '100';
    if (!$ok && $safeMessages !== []) {
        $ok = true;
        foreach ($safeMessages as $item) {
            if (($item['success'] ?? false) !== true) {
                $ok = false;
                break;
            }
        }
    }
    if (!$ok) {
        throw new RuntimeException('seven.io hat den Versand nicht bestaetigt.');
    }

    return [
        'ok' => true,
        'provider' => 'seven',
        'message' => 'seven.io hat die SMS-Anfrage angenommen.',
        'details' => [
            'success' => $data['success'] ?? null,
            'total_price' => $data['total_price'] ?? null,
            'balance' => $data['balance'] ?? null,
            'messages' => $safeMessages,
        ],
    ];
}

function tp_sms_dispatch(array $settings, string $provider, string $recipient, string $message): array
{
    $maxLength = (int)($settings['sms']['max_text_length'] ?? 612);
    if (!in_array($provider, ['none', 'seven', 'fritz'], true)) {
        throw new RuntimeException('Unbekannter SMS-Provider.');
    }
    if ($provider !== 'none' && trim($recipient) === '') {
        throw new RuntimeException('Empfaengernummer fehlt.');
    }
    if (trim($message) === '') {
        throw new RuntimeException('SMS-Text fehlt.');
    }
    if ($maxLength > 0 && tp_sms_text_length($message) > $maxLength) {
        throw new RuntimeException('SMS-Text ist laenger als ' . $maxLength . ' Zeichen.');
    }

    if ($provider === 'none') {
        return [
            'ok' => true,
            'provider' => 'none',
            'message' => 'SMS-Versand ist deaktiviert.',
            'details' => [
                'text_length' => tp_sms_text_length($message),
            ],
        ];
    }
    if ($provider === 'seven') {
        return tp_sms_send_seven($settings, $recipient, $message);
    }
    return tp_sms_send_fritz($settings, $recipient, $message);
}

function tp_sms_send_default(string $recipient, string $message): array
{
    $settings = tp_sms_load_settings();
    $provider = tp_sms_provider_from_value((string)($settings['default_provider'] ?? 'none'));
    return tp_sms_dispatch($settings, $provider, $recipient, $message);
}

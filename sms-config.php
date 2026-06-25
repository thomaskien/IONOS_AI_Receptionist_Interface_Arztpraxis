<?php
/*
 * sms-config.php
 * Version: 0.3.0 (2026-06-25)
 *
 * Changelog:
 * - Adminpasswort-Schutz fuer normal erreichbare Konfigurationsoberflaeche ergaenzt.
 * - Credentials-Pfad auf patchbare absolute Datei ausserhalb des Webroots vorbereitet.
 * - Lokale Client-IP-Sperre standardmaessig deaktiviert; Zugriffsschutz erfolgt ueber Adminpasswort.
 * - Optionales automatisches Loeschen gesendeter FRITZ!Box-SMS nach Versand ergaenzt.
 * - Finalen FRITZ!Box-SMS-Abschluss nach TOTP mit expliziten Bestaetigungsflags gesendet.
 * - FRITZ!Box-Requests innerhalb eines SMS-Versands mit temporaerem Cookie-Jar ausgefuehrt.
 * - Sichere Diagnose fuer nicht bestaetigten finalen FRITZ!Box-SMS-Abschluss ergaenzt.
 * - FRITZ!Box-TOTP-Statuspruefung nach akzeptiertem Code tolerant gemacht.
 * - Lokalen Button zum Anzeigen des aktuellen FRITZ!Box-TOTP-Codes ergaenzt.
 * - FRITZ!Box-TOTP-Fehlermeldung praezisiert, wenn die Box keine Authenticator-Bestaetigung meldet.
 * - Einstellungen direkt in sms.php bearbeitbar gemacht.
 * - Lokale Speicherung in sms-credentials.json statt separater PHP-Settingsdateien.
 * - Secret-Felder werden in der Oberflaeche nicht im Klartext zurueckgeschrieben.
 * - Initiale isolierte SMS-Beispielanwendung.
 * - Provider none, seven.io und FRITZ!Box vorbereitet.
 * - FRITZ!Box-SMS-Versand nach pyfritzsms-Ablauf in PHP umgesetzt.
 */

declare(strict_types=1);

const TP_SMS_VERSION = '0.3.0';
const TP_SMS_CREDENTIALS_FILE = 'sms-credentials.json';
const TP_SMS_CONFIG_ADMIN_PASSWORD = 'bitte-aendern';

function tp_sms_credentials_path(): string
{
    if (strpos(TP_SMS_CREDENTIALS_FILE, '/') === 0) {
        return TP_SMS_CREDENTIALS_FILE;
    }

    return __DIR__ . '/' . TP_SMS_CREDENTIALS_FILE;
}

function tp_sms_default_settings(): array
{
    return [
        'default_provider' => 'none',
        'sms' => [
            'default_to' => '',
            'default_text' => 'Telepraxis-Testnachricht',
            'max_text_length' => 612,
        ],
        'ui' => [
            'local_only' => false,
            'allowed_client_ips' => ['127.0.0.1', '::1'],
            'require_pin' => false,
            'admin_pin' => '',
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

function tp_sms_save_settings(array $settings): void
{
    $path = tp_sms_credentials_path();
    $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('SMS-Credentials konnten nicht als JSON erzeugt werden.');
    }

    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        throw new RuntimeException('SMS-Credentials konnten nicht geschrieben werden.');
    }

    @chmod($tmp, 0660);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        throw new RuntimeException('SMS-Credentials konnten nicht atomar gespeichert werden.');
    }
    @chmod($path, 0660);
}

function tp_sms_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tp_sms_is_placeholder(?string $value): bool
{
    $value = trim((string) $value);

    return $value === '' || strpos($value, '###CHANGE_ME') !== false;
}

function tp_sms_require_config_value(array $config, string $key, string $label): string
{
    $value = trim((string) ($config[$key] ?? ''));
    if (tp_sms_is_placeholder($value)) {
        throw new RuntimeException($label . ' fehlt in ' . TP_SMS_CREDENTIALS_FILE . '.');
    }

    return $value;
}

function tp_sms_bool_from_post(string $key): bool
{
    return isset($_POST[$key]) && (string) $_POST[$key] === '1';
}

function tp_sms_int_from_post(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_POST, $key, FILTER_VALIDATE_INT);
    if (!is_int($value)) {
        return $default;
    }

    return max($min, min($max, $value));
}

function tp_sms_provider_from_value(string $provider): string
{
    return in_array($provider, ['none', 'seven', 'fritz'], true) ? $provider : 'none';
}

function tp_sms_split_ip_list(string $value): array
{
    $parts = preg_split('/[\s,;]+/', trim($value));
    if (!is_array($parts)) {
        return ['127.0.0.1', '::1'];
    }

    $ips = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part !== '') {
            $ips[] = $part;
        }
    }

    return $ips !== [] ? array_values(array_unique($ips)) : ['127.0.0.1', '::1'];
}

function tp_sms_settings_from_post(array $current): array
{
    $settings = $current;

    $settings['default_provider'] = tp_sms_provider_from_value((string) ($_POST['settings_default_provider'] ?? 'none'));
    $settings['sms']['default_to'] = trim((string) ($_POST['settings_default_to'] ?? ''));
    $settings['sms']['default_text'] = (string) ($_POST['settings_default_text'] ?? '');
    $settings['sms']['max_text_length'] = tp_sms_int_from_post('settings_max_text_length', 612, 1, 1600);

    $settings['ui']['local_only'] = tp_sms_bool_from_post('settings_local_only');
    $settings['ui']['allowed_client_ips'] = tp_sms_split_ip_list((string) ($_POST['settings_allowed_client_ips'] ?? '127.0.0.1 ::1'));
    $settings['ui']['require_pin'] = tp_sms_bool_from_post('settings_require_pin');
    if (tp_sms_bool_from_post('settings_admin_pin_clear')) {
        $settings['ui']['admin_pin'] = '';
    } elseif ((string) ($_POST['settings_admin_pin'] ?? '') !== '') {
        $settings['ui']['admin_pin'] = (string) $_POST['settings_admin_pin'];
    }
    if (!empty($settings['ui']['require_pin']) && trim((string) ($settings['ui']['admin_pin'] ?? '')) === '') {
        throw new RuntimeException('Fuer PIN-Schutz muss eine lokale PIN gesetzt sein.');
    }

    if (tp_sms_bool_from_post('settings_seven_api_key_clear')) {
        $settings['seven']['api_key'] = '';
    } elseif ((string) ($_POST['settings_seven_api_key'] ?? '') !== '') {
        $settings['seven']['api_key'] = trim((string) $_POST['settings_seven_api_key']);
    }
    $settings['seven']['from'] = trim((string) ($_POST['settings_seven_from'] ?? 'Telepraxis'));
    $settings['seven']['endpoint'] = trim((string) ($_POST['settings_seven_endpoint'] ?? 'https://gateway.seven.io/api/sms'));
    $settings['seven']['timeout_seconds'] = tp_sms_int_from_post('settings_seven_timeout_seconds', 15, 1, 120);

    $settings['fritzbox']['host'] = trim((string) ($_POST['settings_fritz_host'] ?? 'fritz.box'));
    $settings['fritzbox']['username'] = trim((string) ($_POST['settings_fritz_username'] ?? ''));
    if (tp_sms_bool_from_post('settings_fritz_password_clear')) {
        $settings['fritzbox']['password'] = '';
    } elseif ((string) ($_POST['settings_fritz_password'] ?? '') !== '') {
        $settings['fritzbox']['password'] = (string) $_POST['settings_fritz_password'];
    }
    if (tp_sms_bool_from_post('settings_fritz_totp_secret_clear')) {
        $settings['fritzbox']['totp_secret'] = '';
    } elseif ((string) ($_POST['settings_fritz_totp_secret'] ?? '') !== '') {
        $settings['fritzbox']['totp_secret'] = trim((string) $_POST['settings_fritz_totp_secret']);
    }
    $settings['fritzbox']['totp_digits'] = tp_sms_int_from_post('settings_fritz_totp_digits', 6, 6, 8);
    $settings['fritzbox']['totp_period'] = tp_sms_int_from_post('settings_fritz_totp_period', 30, 15, 120);
    $settings['fritzbox']['timeout_seconds'] = tp_sms_int_from_post('settings_fritz_timeout_seconds', 20, 1, 120);
    $settings['fritzbox']['verify_tls'] = tp_sms_bool_from_post('settings_fritz_verify_tls');
    $settings['fritzbox']['delete_after_send'] = tp_sms_bool_from_post('settings_fritz_delete_after_send');

    return $settings;
}

function tp_sms_text_length(string $text): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($text, 'UTF-8');
    }

    return strlen($text);
}

function tp_sms_client_allowed(array $settings): bool
{
    $ui = $settings['ui'] ?? [];
    if (empty($ui['local_only'])) {
        return true;
    }

    $remoteAddr = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $allowed = $ui['allowed_client_ips'] ?? ['127.0.0.1', '::1'];
    if (!is_array($allowed)) {
        $allowed = ['127.0.0.1', '::1'];
    }

    return in_array($remoteAddr, $allowed, true);
}

function tp_sms_pin_allowed(array $settings): bool
{
    if (tp_sms_config_admin_enabled() && tp_sms_config_is_admin()) {
        return true;
    }

    $ui = $settings['ui'] ?? [];
    if (empty($ui['require_pin'])) {
        return true;
    }

    $configuredPin = (string) ($ui['admin_pin'] ?? '');
    if (tp_sms_is_placeholder($configuredPin)) {
        return false;
    }

    $givenPin = (string) ($_POST['admin_pin'] ?? '');

    return hash_equals($configuredPin, $givenPin);
}

function tp_sms_config_admin_enabled(): bool
{
    return trim((string) TP_SMS_CONFIG_ADMIN_PASSWORD) !== '';
}

function tp_sms_config_is_admin(): bool
{
    return !empty($_SESSION['tp_sms_config_admin']);
}

function tp_sms_config_self_url(): string
{
    $path = (string) ($_SERVER['PHP_SELF'] ?? 'sms-config.php');

    return $path !== '' ? $path : 'sms-config.php';
}

function tp_sms_config_render_login(?string $loginError, string $csrfToken): void
{
    if ($loginError !== null) {
        http_response_code(403);
    }
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telepraxis SMS-Konfiguration</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f7f8;
            --surface: #ffffff;
            --border: #cfd8dc;
            --text: #172026;
            --muted: #5c6b73;
            --accent: #0f766e;
            --accent-dark: #0b5f59;
            --danger: #a32727;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.45;
            padding: 24px;
        }
        main {
            width: min(420px, 100%);
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 22px;
        }
        h1 {
            margin: 0 0 6px;
            font-size: 1.35rem;
            line-height: 1.2;
            letter-spacing: 0;
        }
        p {
            margin: 0 0 18px;
            color: var(--muted);
        }
        label {
            display: block;
            margin: 0 0 8px;
            font-weight: 650;
        }
        input[type="password"] {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 11px;
            font: inherit;
            background: #fff;
            color: var(--text);
        }
        button {
            width: 100%;
            border: 0;
            border-radius: 6px;
            background: var(--accent);
            color: #fff;
            font: inherit;
            font-weight: 700;
            padding: 10px 16px;
            cursor: pointer;
            margin-top: 14px;
        }
        button:hover,
        button:focus-visible {
            background: var(--accent-dark);
        }
        .message {
            border: 1px solid currentColor;
            border-radius: 8px;
            color: var(--danger);
            background: #fff1f1;
            padding: 10px 12px;
            margin-bottom: 14px;
        }
    </style>
</head>
<body>
<main>
    <h1>Telepraxis SMS-Konfiguration</h1>
    <p>Bitte mit dem Adminpasswort anmelden.</p>
    <?php if ($loginError !== null): ?>
        <div class="message"><?php echo tp_sms_e($loginError); ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <input type="hidden" name="csrf" value="<?php echo tp_sms_e($csrfToken); ?>">
        <input type="hidden" name="action" value="admin_login">
        <label for="admin_password">Adminpasswort</label>
        <input id="admin_password" type="password" name="admin_password" value="" autofocus>
        <button type="submit">Anmelden</button>
    </form>
</main>
</body>
</html>
<?php
    exit;
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
    $timeout = (int) ($options['timeout'] ?? 15);
    $headers = $options['headers'] ?? [];
    $data = $options['data'] ?? null;
    $verifyTls = (bool) ($options['verify_tls'] ?? true);
    $cookieFile = (string) ($options['cookie_file'] ?? '');

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
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data, '', '&') : (string) $data);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    $body = curl_exec($ch);
    if ($body === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('HTTP-Anfrage fehlgeschlagen: ' . $error);
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    return [
        'status' => $status,
        'content_type' => $contentType,
        'body' => (string) $body,
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

    $value = trim((string) ($xml->{$name} ?? ''));
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

    $iterations1 = (int) $parts[1];
    $salt1 = hex2bin($parts[2]);
    $iterations2 = (int) $parts[3];
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
    $timeout = (int) ($config['timeout_seconds'] ?? 20);
    $verifyTls = (bool) ($config['verify_tls'] ?? false);
    $response = tp_sms_http_request('POST', tp_sms_fritz_url($host, $path), [
        'timeout' => $timeout,
        'verify_tls' => $verifyTls,
        'cookie_file' => (string) ($config['_cookie_file'] ?? ''),
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
    $timeout = (int) ($config['timeout_seconds'] ?? 20);
    $verifyTls = (bool) ($config['verify_tls'] ?? false);
    $loginUrl = tp_sms_fritz_url($host, 'login_sid.lua');

    $challengeResponse = tp_sms_http_request('GET', $loginUrl, [
        'timeout' => $timeout,
        'verify_tls' => $verifyTls,
        'cookie_file' => (string) ($config['_cookie_file'] ?? ''),
    ]);
    if ($challengeResponse['status'] !== 200) {
        throw new RuntimeException('FRITZ!Box-Login: HTTP ' . $challengeResponse['status'] . ' beim Challenge-Abruf.');
    }

    $challenge = tp_sms_parse_xml_text($challengeResponse['body'], 'Challenge', 'FRITZ!Box-Login');
    $responseValue = tp_sms_fritz_challenge_response($challenge, $password);

    $loginResponse = tp_sms_http_request('POST', $loginUrl, [
        'timeout' => $timeout,
        'verify_tls' => $verifyTls,
        'cookie_file' => (string) ($config['_cookie_file'] ?? ''),
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
        throw new RuntimeException('FRITZ!Box-Login fehlgeschlagen. Benutzer, Passwort oder 2FA-Konfiguration pruefen.');
    }

    return $sid;
}

function tp_sms_fritz_logout(array $config, string $sid): void
{
    if ($sid === '' || $sid === '0000000000000000') {
        return;
    }

    $host = (string) ($config['host'] ?? 'fritz.box');
    $timeout = (int) ($config['timeout_seconds'] ?? 20);
    $verifyTls = (bool) ($config['verify_tls'] ?? false);
    $url = tp_sms_build_url(tp_sms_fritz_url($host, 'login_sid.lua'), [
        'sid' => $sid,
        'logout' => '1',
    ]);

    try {
        tp_sms_http_request('GET', $url, [
            'timeout' => $timeout,
            'verify_tls' => $verifyTls,
            'cookie_file' => (string) ($config['_cookie_file'] ?? ''),
        ]);
    } catch (Throwable $ignored) {
    }
}

function tp_sms_fritz_update_sid(string $sid, array $data): string
{
    $newSid = (string) ($data['sid'] ?? '');
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
            $keyString = strtolower((string) $key);
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

    $value = strtolower(trim((string) $value));

    return in_array($value, ['1', 'true', 'yes', 'on'], true);
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
    $modulo = (int) pow(10, $digits);

    return str_pad((string) ($code % $modulo), $digits, '0', STR_PAD_LEFT);
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
        throw new RuntimeException('Die FRITZ!Box fordert eine zweite Bestaetigung, meldet fuer diesen Benutzer aber keine eingerichtete Authenticator-/TOTP-Bestaetigung. Das TOTP-Secret in sms.php reicht allein nicht aus; die FRITZ!Box selbst muss dieselbe Authenticator-Bestaetigung eingerichtet haben.');
    }
    if (!tp_sms_boolish($googleAuth['isAvailable'] ?? false)) {
        throw new RuntimeException('Die FRITZ!Box meldet die Authenticator-/TOTP-Bestaetigung als eingerichtet, aber aktuell nicht verfuegbar.');
    }

    $totpSecret = tp_sms_require_config_value($config, 'totp_secret', 'FRITZ!Box-TOTP-Secret');
    $totp = tp_sms_totp_now($totpSecret, (int) ($config['totp_digits'] ?? 6), (int) ($config['totp_period'] ?? 30));

    $totpResult = tp_sms_fritz_request($config, 'twofactor.lua', [
        'sid' => $sid,
        'tfa_googleauth' => $totp,
        'no_sidrenew' => '',
    ], 'FRITZ!Box-TOTP-Bestaetigung');

    if ((int) ($totpResult['err'] ?? 1) !== 0) {
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
        $newUid = (string) ($initial['data']['new_uid'] ?? '');
        if ($newUid === '') {
            $deleteInfo = [
                'deleted' => false,
                'reason' => 'FRITZ!Box hat keine messageId/new_uid geliefert.',
            ];
            return [
                'ok' => true,
                'provider' => 'fritz',
                'message' => 'FRITZ!Box hat die SMS-Anfrage angenommen.',
                'details' => [
                    'twofactor' => false,
                    'delete_after_send' => $deleteAfterSend,
                    'delete' => $deleteInfo,
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
                ? 'FRITZ!Box hat die SMS nach TOTP-Bestaetigung angenommen und lokal geloescht.'
                : 'FRITZ!Box hat die SMS nach TOTP-Bestaetigung angenommen.',
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
    $endpoint = trim((string) ($config['endpoint'] ?? 'https://gateway.seven.io/api/sms'));
    if ($endpoint === '') {
        $endpoint = 'https://gateway.seven.io/api/sms';
    }

    $payload = [
        'to' => $recipient,
        'text' => $message,
    ];
    $from = trim((string) ($config['from'] ?? ''));
    if (!tp_sms_is_placeholder($from)) {
        $payload['from'] = $from;
    }

    $response = tp_sms_http_request('POST', $endpoint, [
        'timeout' => (int) ($config['timeout_seconds'] ?? 15),
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

    $successCode = (string) ($data['success'] ?? '');
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
    $maxLength = (int) ($settings['sms']['max_text_length'] ?? 612);
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
            'message' => 'SMS-Versand ist deaktiviert. Die Eingaben wurden nur lokal geprueft.',
            'details' => [
                'recipient_set' => trim($recipient) !== '',
                'text_length' => tp_sms_text_length($message),
            ],
        ];
    }

    if ($provider === 'seven') {
        return tp_sms_send_seven($settings, $recipient, $message);
    }

    return tp_sms_send_fritz($settings, $recipient, $message);
}

function tp_sms_fritz_current_totp(array $settings): array
{
    $config = $settings['fritzbox'] ?? [];
    if (!is_array($config)) {
        throw new RuntimeException('FRITZ!Box-Konfiguration fehlt.');
    }

    $period = (int) ($config['totp_period'] ?? 30);
    $period = max(15, $period);
    $secret = tp_sms_require_config_value($config, 'totp_secret', 'FRITZ!Box-TOTP-Secret');
    $code = tp_sms_totp_now($secret, (int) ($config['totp_digits'] ?? 6), $period);
    $remaining = $period - (time() % $period);

    return [
        'ok' => true,
        'provider' => 'fritz',
        'message' => 'Aktueller FRITZ!Box-TOTP-Code wurde lokal berechnet.',
        'details' => [
            'code' => $code,
            'valid_for_seconds' => $remaining,
        ],
    ];
}

function tp_sms_config_status(array $settings): array
{
    $seven = $settings['seven'] ?? [];
    $fritz = $settings['fritzbox'] ?? [];

    return [
        'credentials_file' => TP_SMS_CREDENTIALS_FILE,
        'credentials_exists' => is_file(tp_sms_credentials_path()),
        'seven' => is_array($seven) && !tp_sms_is_placeholder((string) ($seven['api_key'] ?? '')),
        'fritz_login' => is_array($fritz)
            && !tp_sms_is_placeholder((string) ($fritz['host'] ?? ''))
            && !tp_sms_is_placeholder((string) ($fritz['username'] ?? ''))
            && !tp_sms_is_placeholder((string) ($fritz['password'] ?? '')),
        'fritz_totp' => is_array($fritz) && !tp_sms_is_placeholder((string) ($fritz['totp_secret'] ?? '')),
    ];
}

$settings = [];
$result = null;
$error = null;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['tp_sms_csrf'])) {
    $_SESSION['tp_sms_csrf'] = bin2hex(random_bytes(16));
}
$csrfToken = (string) ($_SESSION['tp_sms_csrf'] ?? '');

if (tp_sms_config_admin_enabled()) {
    $loginError = null;
    $authAction = (string) ($_POST['action'] ?? '');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array($authAction, ['admin_login', 'admin_logout'], true)) {
        $postedCsrf = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals($csrfToken, $postedCsrf)) {
            $loginError = 'Formular-Token ist ungueltig.';
        } elseif ($authAction === 'admin_logout') {
            unset($_SESSION['tp_sms_config_admin']);
            header('Location: ' . tp_sms_config_self_url());
            exit;
        } else {
            $givenPassword = (string) ($_POST['admin_password'] ?? '');
            if (hash_equals((string) TP_SMS_CONFIG_ADMIN_PASSWORD, $givenPassword)) {
                $_SESSION['tp_sms_config_admin'] = true;
                header('Location: ' . tp_sms_config_self_url());
                exit;
            }

            $loginError = 'Adminpasswort ist ungueltig.';
        }
    }

    if (!tp_sms_config_is_admin()) {
        tp_sms_config_render_login($loginError, $csrfToken);
    }
}

try {
    $settings = tp_sms_load_settings();
} catch (Throwable $throwable) {
    $error = $throwable->getMessage();
}

$provider = tp_sms_provider_from_value((string) ($_POST['provider'] ?? ($settings['default_provider'] ?? 'none')));
$recipient = (string) ($_POST['recipient'] ?? ($settings['sms']['default_to'] ?? ''));
$message = (string) ($_POST['message'] ?? ($settings['sms']['default_text'] ?? 'Telepraxis-Testnachricht'));
$maxLength = (int) ($settings['sms']['max_text_length'] ?? 612);

if ($error === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $csrf = (string) ($_POST['csrf'] ?? '');
        if (!hash_equals((string) ($_SESSION['tp_sms_csrf'] ?? ''), $csrf)) {
            throw new RuntimeException('Formular-Token ist ungueltig.');
        }
        if (!tp_sms_pin_allowed($settings)) {
            throw new RuntimeException('Lokale PIN fehlt oder ist ungueltig.');
        }

        $action = (string) ($_POST['action'] ?? 'send');
        if ($action === 'save_settings') {
            $settings = tp_sms_settings_from_post($settings);
            tp_sms_save_settings($settings);
            $provider = (string) ($settings['default_provider'] ?? 'none');
            $recipient = (string) ($settings['sms']['default_to'] ?? '');
            $message = (string) ($settings['sms']['default_text'] ?? 'Telepraxis-Testnachricht');
            $maxLength = (int) ($settings['sms']['max_text_length'] ?? 612);
            $result = [
                'ok' => true,
                'provider' => 'settings',
                'message' => 'SMS-Einstellungen wurden lokal gespeichert.',
                'details' => [
                    'credentials_file' => TP_SMS_CREDENTIALS_FILE,
                    'provider' => $provider,
                ],
            ];
        } elseif ($action === 'show_totp') {
            $previewSettings = tp_sms_settings_from_post($settings);
            $result = tp_sms_fritz_current_totp($previewSettings);
        } else {
            $provider = tp_sms_provider_from_value($provider);
            $result = tp_sms_dispatch($settings, $provider, trim($recipient), $message);
        }
    } catch (Throwable $throwable) {
        $error = $throwable->getMessage();
    }
}

$status = $settings !== [] ? tp_sms_config_status($settings) : [
    'credentials_file' => TP_SMS_CREDENTIALS_FILE,
    'credentials_exists' => false,
    'seven' => false,
    'fritz_login' => false,
    'fritz_totp' => false,
];
$formSettings = $settings !== [] ? $settings : tp_sms_default_settings();
$pinRequired = !tp_sms_config_admin_enabled() && !empty($formSettings['ui']['require_pin']);
$adminPinSet = trim((string) ($formSettings['ui']['admin_pin'] ?? '')) !== '';
$sevenApiKeySet = trim((string) ($formSettings['seven']['api_key'] ?? '')) !== '';
$fritzPasswordSet = trim((string) ($formSettings['fritzbox']['password'] ?? '')) !== '';
$fritzTotpSet = trim((string) ($formSettings['fritzbox']['totp_secret'] ?? '')) !== '';
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telepraxis SMS-Konfiguration</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f7f8;
            --surface: #ffffff;
            --border: #cfd8dc;
            --text: #172026;
            --muted: #5c6b73;
            --accent: #0f766e;
            --accent-dark: #0b5f59;
            --danger: #a32727;
            --ok: #216e39;
        }
        * {
            box-sizing: border-box;
        }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.45;
        }
        main {
            width: min(920px, calc(100% - 32px));
            margin: 28px auto;
        }
        h1 {
            margin: 0 0 4px;
            font-size: clamp(1.45rem, 2vw, 2rem);
            line-height: 1.15;
            letter-spacing: 0;
        }
        .subtitle {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 0.98rem;
        }
        .logout-form {
            display: flex;
            justify-content: flex-end;
            margin: -10px 0 18px;
        }
        .logout-form button {
            width: auto;
        }
        .layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 280px;
            gap: 18px;
            align-items: start;
        }
        .stack {
            display: grid;
            gap: 18px;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 18px;
        }
        fieldset {
            border: 1px solid var(--border);
            border-radius: 8px;
            margin: 0 0 16px;
            padding: 12px;
        }
        legend {
            padding: 0 6px;
            font-weight: 700;
        }
        label {
            display: block;
            margin: 0 0 8px;
            font-weight: 650;
        }
        .provider-row {
            display: grid;
            gap: 8px;
        }
        .radio-line {
            display: flex;
            gap: 8px;
            align-items: center;
            min-height: 32px;
            font-weight: 500;
        }
        input[type="text"],
        input[type="password"],
        input[type="number"],
        input[type="url"],
        select,
        textarea {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 11px;
            font: inherit;
            background: #fff;
            color: var(--text);
        }
        textarea {
            min-height: 136px;
            resize: vertical;
        }
        .field {
            margin-bottom: 16px;
        }
        .field.compact {
            margin-bottom: 0;
        }
        .check-line {
            display: flex;
            gap: 8px;
            align-items: center;
            min-height: 32px;
            margin: 0 0 10px;
            font-weight: 500;
        }
        .hint {
            color: var(--muted);
            font-size: 0.88rem;
            margin: 6px 0 0;
        }
        .actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 8px;
        }
        button {
            border: 0;
            border-radius: 6px;
            background: var(--accent);
            color: #fff;
            font: inherit;
            font-weight: 700;
            padding: 10px 16px;
            cursor: pointer;
        }
        button:hover,
        button:focus-visible {
            background: var(--accent-dark);
        }
        button.secondary {
            background: #475569;
        }
        button.secondary:hover,
        button.secondary:focus-visible {
            background: #334155;
        }
        .message {
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
            border: 1px solid currentColor;
        }
        .message.ok {
            color: var(--ok);
            background: #edf8f1;
        }
        .message.error {
            color: var(--danger);
            background: #fff1f1;
        }
        .meta-list {
            display: grid;
            gap: 10px;
            margin: 0;
        }
        .meta-line {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px solid #e7ecef;
            padding-bottom: 8px;
        }
        .meta-line:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }
        .meta-key {
            color: var(--muted);
        }
        .badge {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            border-radius: 999px;
            padding: 2px 9px;
            background: #edf2f4;
            font-size: 0.88rem;
            font-weight: 700;
        }
        .badge.ok {
            color: var(--ok);
            background: #e6f4eb;
        }
        .badge.warn {
            color: #7a4a00;
            background: #fff5db;
        }
        pre {
            margin: 12px 0 0;
            max-height: 260px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            background: #101820;
            color: #e8eef2;
            border-radius: 8px;
            padding: 12px;
            font-size: 0.88rem;
        }
        @media (max-width: 760px) {
            main {
                width: min(100% - 20px, 920px);
                margin-top: 14px;
            }
            .layout {
                grid-template-columns: 1fr;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .actions {
                justify-content: stretch;
            }
            .logout-form {
                justify-content: stretch;
            }
            button {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<main>
    <h1>Telepraxis SMS-Konfiguration</h1>
    <p class="subtitle">Einstellungen fuer seven.io, FRITZ!Box und deaktivierten Versand.</p>
    <?php if (tp_sms_config_admin_enabled()): ?>
        <form method="post" class="logout-form">
            <input type="hidden" name="csrf" value="<?php echo tp_sms_e($csrfToken); ?>">
            <button type="submit" name="action" value="admin_logout" class="secondary">Abmelden</button>
        </form>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="message ok">
            <strong><?php echo tp_sms_e($result['message'] ?? 'SMS-Anfrage verarbeitet.'); ?></strong>
            <pre><?php echo tp_sms_e(json_encode($result['details'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
        </div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="message error">
            <strong><?php echo tp_sms_e($error); ?></strong>
        </div>
    <?php endif; ?>

    <div class="layout">
        <div class="stack">
            <form method="post" class="panel" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo tp_sms_e($csrfToken); ?>">
                <input type="hidden" name="action" value="send_sms">

                <fieldset>
                    <legend>Provider</legend>
                    <div class="provider-row">
                        <?php foreach (['none' => 'Keine SMS', 'seven' => 'seven.io', 'fritz' => 'FRITZ!Box'] as $value => $label): ?>
                            <label class="radio-line">
                                <input type="radio" name="provider" value="<?php echo tp_sms_e($value); ?>" <?php echo $provider === $value ? 'checked' : ''; ?>>
                                <span><?php echo tp_sms_e($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>

                <?php if ($pinRequired): ?>
                    <div class="field">
                        <label for="admin_pin">Lokale PIN</label>
                        <input id="admin_pin" type="password" name="admin_pin" value="">
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="recipient">Empfaenger</label>
                    <input id="recipient" type="text" name="recipient" value="<?php echo tp_sms_e($recipient); ?>" placeholder="+491701234567">
                </div>

                <div class="field">
                    <label for="message">SMS-Text</label>
                    <textarea id="message" name="message" maxlength="<?php echo (int) $maxLength; ?>"><?php echo tp_sms_e($message); ?></textarea>
                </div>

                <div class="actions">
                    <button type="submit">Senden / testen</button>
                </div>
            </form>

            <form method="post" class="panel" autocomplete="off">
                <input type="hidden" name="csrf" value="<?php echo tp_sms_e($csrfToken); ?>">

                <fieldset>
                    <legend>Allgemein</legend>
                    <?php if ($pinRequired): ?>
                        <div class="field">
                            <label for="settings_current_pin">Aktuelle lokale PIN</label>
                            <input id="settings_current_pin" type="password" name="admin_pin" value="">
                        </div>
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="field compact">
                            <label for="settings_default_provider">Standard-Provider</label>
                            <select id="settings_default_provider" name="settings_default_provider">
                                <?php foreach (['none' => 'Keine SMS', 'seven' => 'seven.io', 'fritz' => 'FRITZ!Box'] as $value => $label): ?>
                                    <option value="<?php echo tp_sms_e($value); ?>" <?php echo ($formSettings['default_provider'] ?? 'none') === $value ? 'selected' : ''; ?>><?php echo tp_sms_e($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field compact">
                            <label for="settings_default_to">Standard-Empfaenger</label>
                            <input id="settings_default_to" type="text" name="settings_default_to" value="<?php echo tp_sms_e((string) ($formSettings['sms']['default_to'] ?? '')); ?>" placeholder="+491701234567">
                        </div>
                    </div>

                    <div class="field">
                        <label for="settings_default_text">Standard-SMS-Text</label>
                        <textarea id="settings_default_text" name="settings_default_text" maxlength="<?php echo (int) ($formSettings['sms']['max_text_length'] ?? 612); ?>"><?php echo tp_sms_e((string) ($formSettings['sms']['default_text'] ?? '')); ?></textarea>
                    </div>

                    <div class="form-grid">
                        <div class="field compact">
                            <label for="settings_max_text_length">Maximale Textlaenge</label>
                            <input id="settings_max_text_length" type="number" min="1" max="1600" name="settings_max_text_length" value="<?php echo (int) ($formSettings['sms']['max_text_length'] ?? 612); ?>">
                        </div>
                    </div>

                    <?php if (!tp_sms_config_admin_enabled()): ?>
                        <label class="check-line">
                            <input type="checkbox" name="settings_require_pin" value="1" <?php echo !empty($formSettings['ui']['require_pin']) ? 'checked' : ''; ?>>
                            <span>Lokale PIN verlangen</span>
                        </label>
                        <div class="field">
                            <label for="settings_admin_pin">Neue lokale PIN</label>
                            <input id="settings_admin_pin" type="password" name="settings_admin_pin" value="" placeholder="<?php echo $adminPinSet ? 'PIN ist gespeichert' : ''; ?>">
                            <?php if ($adminPinSet): ?>
                                <label class="check-line">
                                    <input type="checkbox" name="settings_admin_pin_clear" value="1">
                                    <span>PIN loeschen</span>
                                </label>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </fieldset>

                <fieldset>
                    <legend>seven.io</legend>
                    <div class="field">
                        <label for="settings_seven_api_key">API-Key</label>
                        <input id="settings_seven_api_key" type="password" name="settings_seven_api_key" value="" placeholder="<?php echo $sevenApiKeySet ? 'API-Key ist gespeichert' : ''; ?>">
                        <?php if ($sevenApiKeySet): ?>
                            <label class="check-line">
                                <input type="checkbox" name="settings_seven_api_key_clear" value="1">
                                <span>API-Key loeschen</span>
                            </label>
                        <?php endif; ?>
                    </div>
                    <div class="form-grid">
                        <div class="field compact">
                            <label for="settings_seven_from">Absender</label>
                            <input id="settings_seven_from" type="text" name="settings_seven_from" value="<?php echo tp_sms_e((string) ($formSettings['seven']['from'] ?? 'Telepraxis')); ?>">
                        </div>
                        <div class="field compact">
                            <label for="settings_seven_timeout_seconds">Timeout Sekunden</label>
                            <input id="settings_seven_timeout_seconds" type="number" min="1" max="120" name="settings_seven_timeout_seconds" value="<?php echo (int) ($formSettings['seven']['timeout_seconds'] ?? 15); ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label for="settings_seven_endpoint">Endpoint</label>
                        <input id="settings_seven_endpoint" type="url" name="settings_seven_endpoint" value="<?php echo tp_sms_e((string) ($formSettings['seven']['endpoint'] ?? 'https://gateway.seven.io/api/sms')); ?>">
                    </div>
                </fieldset>

                <fieldset>
                    <legend>FRITZ!Box</legend>
                    <div class="form-grid">
                        <div class="field compact">
                            <label for="settings_fritz_host">Host</label>
                            <input id="settings_fritz_host" type="text" name="settings_fritz_host" value="<?php echo tp_sms_e((string) ($formSettings['fritzbox']['host'] ?? 'fritz.box')); ?>">
                        </div>
                        <div class="field compact">
                            <label for="settings_fritz_username">Benutzer</label>
                            <input id="settings_fritz_username" type="text" name="settings_fritz_username" value="<?php echo tp_sms_e((string) ($formSettings['fritzbox']['username'] ?? '')); ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label for="settings_fritz_password">Passwort</label>
                        <input id="settings_fritz_password" type="password" name="settings_fritz_password" value="" placeholder="<?php echo $fritzPasswordSet ? 'Passwort ist gespeichert' : ''; ?>">
                        <?php if ($fritzPasswordSet): ?>
                            <label class="check-line">
                                <input type="checkbox" name="settings_fritz_password_clear" value="1">
                                <span>Passwort loeschen</span>
                            </label>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="settings_fritz_totp_secret">TOTP-Secret</label>
                        <input id="settings_fritz_totp_secret" type="password" name="settings_fritz_totp_secret" value="" placeholder="<?php echo $fritzTotpSet ? 'TOTP-Secret ist gespeichert' : ''; ?>">
                        <p class="hint">Zum Einrichten: Secret aus dem FRITZ!Box-QR-Code eintragen, Code anzeigen, sechsstelligen Code in der FRITZ!Box eingeben.</p>
                        <?php if ($fritzTotpSet): ?>
                            <label class="check-line">
                                <input type="checkbox" name="settings_fritz_totp_secret_clear" value="1">
                                <span>TOTP-Secret loeschen</span>
                            </label>
                        <?php endif; ?>
                        <div class="actions">
                            <button type="submit" name="action" value="show_totp" class="secondary">Aktuellen TOTP-Code anzeigen</button>
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="field compact">
                            <label for="settings_fritz_totp_digits">TOTP-Stellen</label>
                            <input id="settings_fritz_totp_digits" type="number" min="6" max="8" name="settings_fritz_totp_digits" value="<?php echo (int) ($formSettings['fritzbox']['totp_digits'] ?? 6); ?>">
                        </div>
                        <div class="field compact">
                            <label for="settings_fritz_totp_period">TOTP-Periode</label>
                            <input id="settings_fritz_totp_period" type="number" min="15" max="120" name="settings_fritz_totp_period" value="<?php echo (int) ($formSettings['fritzbox']['totp_period'] ?? 30); ?>">
                        </div>
                        <div class="field compact">
                            <label for="settings_fritz_timeout_seconds">Timeout Sekunden</label>
                            <input id="settings_fritz_timeout_seconds" type="number" min="1" max="120" name="settings_fritz_timeout_seconds" value="<?php echo (int) ($formSettings['fritzbox']['timeout_seconds'] ?? 20); ?>">
                        </div>
                        <label class="check-line">
                            <input type="checkbox" name="settings_fritz_verify_tls" value="1" <?php echo !empty($formSettings['fritzbox']['verify_tls']) ? 'checked' : ''; ?>>
                            <span>TLS-Zertifikat pruefen</span>
                        </label>
                        <label class="check-line">
                            <input type="checkbox" name="settings_fritz_delete_after_send" value="1" <?php echo !empty($formSettings['fritzbox']['delete_after_send']) ? 'checked' : ''; ?>>
                            <span>Gesendete SMS danach auf der FRITZ!Box loeschen</span>
                        </label>
                    </div>
                </fieldset>

                <div class="actions">
                    <button type="submit" name="action" value="save_settings" class="secondary">Einstellungen speichern</button>
                </div>
            </form>
        </div>

        <aside class="panel" aria-label="Konfigurationsstatus">
            <dl class="meta-list">
                <div class="meta-line">
                    <dt class="meta-key">Version</dt>
                    <dd><?php echo tp_sms_e(TP_SMS_VERSION); ?></dd>
                </div>
                <div class="meta-line">
                    <dt class="meta-key">Credentials</dt>
                    <dd><?php echo tp_sms_e($status['credentials_file']); ?></dd>
                </div>
                <div class="meta-line">
                    <dt class="meta-key">Datei</dt>
                    <dd><span class="badge <?php echo $status['credentials_exists'] ? 'ok' : 'warn'; ?>"><?php echo $status['credentials_exists'] ? 'vorhanden' : 'fehlt'; ?></span></dd>
                </div>
                <div class="meta-line">
                    <dt class="meta-key">seven.io</dt>
                    <dd><span class="badge <?php echo $status['seven'] ? 'ok' : 'warn'; ?>"><?php echo $status['seven'] ? 'bereit' : 'offen'; ?></span></dd>
                </div>
                <div class="meta-line">
                    <dt class="meta-key">FRITZ Login</dt>
                    <dd><span class="badge <?php echo $status['fritz_login'] ? 'ok' : 'warn'; ?>"><?php echo $status['fritz_login'] ? 'bereit' : 'offen'; ?></span></dd>
                </div>
                <div class="meta-line">
                    <dt class="meta-key">FRITZ TOTP</dt>
                    <dd><span class="badge <?php echo $status['fritz_totp'] ? 'ok' : 'warn'; ?>"><?php echo $status['fritz_totp'] ? 'gesetzt' : 'offen'; ?></span></dd>
                </div>
            </dl>
        </aside>
    </div>
</main>
</body>
</html>

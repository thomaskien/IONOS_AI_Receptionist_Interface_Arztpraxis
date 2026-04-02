<?php
// telepraxis-receive.php
// IONOS: static PSK via Header X-TP-Token (kein Rate-Limit)
// Webformular: id == "web-formular" -> OTP (1 Tag, 1x) + Rate-Limit 20/10min
// Speichert jede Anfrage als einzelne VERSCHLÜSSELTE JSON-Datei nach /srv/telepraxis/inbox

header('Content-Type: application/json; charset=utf-8');

// ======= Konfiguration =======
$INBOX_DIR = '/srv/telepraxis/inbox';

// IONOS-PSK (statisch) – in IONOS Tool Headers setzen: X-TP-Token: <dieser Wert>
$IONOS_PSK = 'CHANGE_ME_LONG_RANDOM_SECRET';

// OTP DB (für Webformular)
$OTP_DB = '/srv/telepraxis/otp.sqlite';

// Rate-Limit nur fürs Webformular
$WEB_RL_LIMIT = 20;
$WEB_RL_WINDOW_SEC = 10 * 60;

// Public Key für Inhaltsverschlüsselung
// HIER DEN ECHTEN PUBLIC KEY 1:1 EINFÜGEN
$PUBLIC_KEY_PEM = <<<'PEM'
-----BEGIN PUBLIC KEY-----
HIER_DEIN_PUBLIC_KEY_EINFUEGEN
-----END PUBLIC KEY-----
PEM;

// Cipher für openssl_seal()
$CONTENT_CIPHER = 'AES-256-CBC';
// ======= /Konfiguration =======

function scalar_str($v) {
    if (is_array($v) || is_object($v)) return '';
    return trim((string)$v);
}

function json_out($code, $arr) {
    http_response_code($code);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
    exit;
}

function encrypt_record_wrapper($plaintextJson, $publicKeyPem, $cipher, &$error = null) {
    $error = null;

    if (!function_exists('openssl_seal')) {
        $error = 'PHP OpenSSL-Erweiterung nicht verfügbar';
        return false;
    }

    $pubKey = openssl_pkey_get_public($publicKeyPem);
    if ($pubKey === false) {
        $error = 'Ungültiger Public Key in PHP-Datei';
        return false;
    }

    $sealedData = '';
    $encryptedKeys = array();
    $iv = '';

    $ok = openssl_seal(
        $plaintextJson,
        $sealedData,
        $encryptedKeys,
        array($pubKey),
        $cipher,
        $iv
    );

    if ($ok === false || !isset($encryptedKeys[0])) {
        $error = 'Verschlüsselung fehlgeschlagen';
        return false;
    }

    $wrapper = array(
        'v'          => 1,
        'created_at' => date('c'),
        'cipher'     => $cipher,
        'sha256'     => hash('sha256', $plaintextJson),
        'ek'         => base64_encode($encryptedKeys[0]),
        'iv'         => base64_encode($iv),
        'ct'         => base64_encode($sealedData),
    );

    return $wrapper;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    // Wenn du CORS brauchst, hier erlauben (optional)
    // header('Access-Control-Allow-Origin: https://DEINE-ORIGIN');
    // header('Access-Control-Allow-Headers: Content-Type, X-TP-Token');
    // header('Access-Control-Allow-Methods: POST, OPTIONS');
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(405, array('ok' => false, 'error' => 'Nur POST erlaubt'));
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    json_out(400, array('ok' => false, 'error' => 'Ungültiges JSON'));
}

// Mindestens ein Feld befüllt?
$hasAny = false;
foreach ($data as $v) {
    if (is_array($v) || is_object($v)) {
        if (!empty($v)) {
            $hasAny = true;
            break;
        }
    } else if (is_bool($v) || is_int($v) || is_float($v)) {
        $hasAny = true;
        break;
    } else if (trim((string)$v) !== '') {
        $hasAny = true;
        break;
    }
}
if (!$hasAny) {
    json_out(400, array('ok' => false, 'error' => 'Mindestens ein Feld muss befüllt sein'));
}

$typ = scalar_str($data['typ'] ?? '');
if ($typ === '') $typ = 'unknown';

$id = scalar_str($data['id'] ?? '');
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// --- Auth Entscheidung ---
$hdrToken = scalar_str($_SERVER['HTTP_X_TP_TOKEN'] ?? '');

// 1) IONOS-Pfad: Header-Token korrekt => OK (kein Rate-Limit)
$isIonosAuthorized = ($hdrToken !== '' && hash_equals($IONOS_PSK, $hdrToken));

// 2) Webformular-Pfad: id == "web-formular" => OTP + Rate-Limit nötig
$isWeb = ($id === 'web-formular');

if (!$isIonosAuthorized && !$isWeb) {
    json_out(403, array(
        'ok' => false,
        'error' => 'forbidden',
        'message' => 'Nicht autorisiert (fehlender/ungültiger PSK oder kein Webformular-Request).'
    ));
}

// --- Webformular Schutz: Rate-Limit + OTP ---
if ($isWeb) {
    // Rate limit pro IP
    $rlFile = '/tmp/telepraxis_rl_' . preg_replace('/[^0-9a-fA-F:\.]/', '_', $ip) . '.json';
    $now = time();
    $events = array();

    if (is_readable($rlFile)) {
        $rlRaw = @file_get_contents($rlFile);
        $rlDec = @json_decode($rlRaw, true);
        if (is_array($rlDec) && isset($rlDec['events']) && is_array($rlDec['events'])) {
            $events = $rlDec['events'];
        }
    }

    $cutoff = $now - $WEB_RL_WINDOW_SEC;
    $kept = array();
    foreach ($events as $ts) {
        if (is_int($ts) && $ts >= $cutoff) $kept[] = $ts;
    }
    $events = $kept;

    if (count($events) >= $WEB_RL_LIMIT) {
        json_out(429, array(
            'ok' => false,
            'error' => 'rate_limited',
            'message' => 'Empfang begrenzt auf maximal 20 Nachrichten in 10 Minuten, bitte warten und dann nochmal absenden.'
        ));
    }

    // OTP prüfen
    $otp = scalar_str($data['otp'] ?? '');
    if ($otp === '') {
        json_out(403, array('ok' => false, 'error' => 'otp_missing', 'message' => 'OTP fehlt.'));
    }

    if (!is_dir(dirname($OTP_DB))) {
        @mkdir(dirname($OTP_DB), 0770, true);
    }

    try {
        $pdo = new PDO('sqlite:' . $OTP_DB);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE IF NOT EXISTS otps (
            token_hash TEXT PRIMARY KEY,
            expires_at INTEGER NOT NULL,
            used_at INTEGER
        )');

        $hash = hash('sha256', $otp);
        $stmt = $pdo->prepare('SELECT expires_at, used_at FROM otps WHERE token_hash = :h');
        $stmt->execute(array(':h' => $hash));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            json_out(403, array('ok' => false, 'error' => 'otp_invalid', 'message' => 'OTP ungültig.'));
        }
        if (!empty($row['used_at'])) {
            json_out(403, array('ok' => false, 'error' => 'otp_used', 'message' => 'OTP wurde bereits verwendet.'));
        }
        if ((int)$row['expires_at'] < time()) {
            json_out(403, array('ok' => false, 'error' => 'otp_expired', 'message' => 'OTP ist abgelaufen.'));
        }

        // OTP als benutzt markieren (atomar)
        $upd = $pdo->prepare('UPDATE otps SET used_at = :u WHERE token_hash = :h AND used_at IS NULL');
        $upd->execute(array(':u' => time(), ':h' => $hash));
        if ($upd->rowCount() !== 1) {
            json_out(403, array('ok' => false, 'error' => 'otp_race', 'message' => 'OTP konnte nicht markiert werden (Race).'));
        }

    } catch (Exception $e) {
        json_out(500, array('ok' => false, 'error' => 'otp_db_error', 'message' => $e->getMessage()));
    }

    // erst nach erfolgreicher OTP-Prüfung zählen
    $events[] = $now;
    @file_put_contents($rlFile, json_encode(array('events' => $events)), LOCK_EX);
}

// --- Inbox schreiben ---
if (!is_dir($INBOX_DIR)) {
    if (!@mkdir($INBOX_DIR, 0770, true)) {
        json_out(500, array('ok' => false, 'error' => 'Konnte Inbox nicht anlegen', 'path' => $INBOX_DIR));
    }
}

$rand = function_exists('random_int') ? random_int(100000, 999999) : mt_rand(100000, 999999);
$fname = date('Ymd_His') . '_' . $rand . '.json.enc';
$file  = rtrim($INBOX_DIR, '/') . '/' . $fname;

$record = array(
    'received_at' => date('c'),
    'remote_ip'   => $ip,
    'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'typ'         => $typ,
    'payload'     => $data
);

$plaintext = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($plaintext === false) {
    json_out(500, array('ok' => false, 'error' => 'JSON-Encoding fehlgeschlagen'));
}

$encError = null;
$wrapper = encrypt_record_wrapper($plaintext, $PUBLIC_KEY_PEM, $CONTENT_CIPHER, $encError);
if ($wrapper === false) {
    json_out(500, array('ok' => false, 'error' => $encError ?: 'Verschlüsselung fehlgeschlagen'));
}

$out = json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($out === false) {
    json_out(500, array('ok' => false, 'error' => 'Wrapper-JSON-Encoding fehlgeschlagen'));
}

$tmp = $file . '.tmp';
if (@file_put_contents($tmp, $out . "\n", LOCK_EX) === false || !@rename($tmp, $file)) {
    @unlink($tmp);
    json_out(500, array('ok' => false, 'error' => 'Konnte Datei nicht schreiben', 'file' => $file));
}

@chmod($file, 0640);

json_out(200, array('ok' => true, 'file' => $fname, 'typ' => $typ));

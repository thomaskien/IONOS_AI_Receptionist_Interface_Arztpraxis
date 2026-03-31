<?php
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Nur POST erlaubt'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Ungültiges JSON'], JSON_UNESCAPED_UNICODE);
    exit;
}

function scalar_str($v): string {
    if (is_array($v) || is_object($v)) return '';
    return trim((string)$v);
}

/**
 * Mindestens ein "befülltes" Feld:
 * - nicht-leerer String
 * - Zahl != 0 oder 0 (lassen wir als befüllt gelten, falls gesendet)
 * - bool (true/false zählt als befüllt, wenn vorhanden)
 * - nicht-leeres Array/Object zählt als befüllt
 */
function has_any_value(array $arr): bool {
    foreach ($arr as $v) {
        if (is_array($v) || is_object($v)) {
            if (!empty($v)) return true;
            continue;
        }
        if (is_bool($v)) return true;
        if (is_int($v) || is_float($v)) return true;
        if (trim((string)$v) !== '') return true;
    }
    return false;
}

if (!has_any_value($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Mindestens ein Feld muss befüllt sein'], JSON_UNESCAPED_UNICODE);
    exit;
}

$typ = scalar_str($data['typ'] ?? '');
if ($typ === '') $typ = 'unknown';

$inbox = '/srv/telepraxis/inbox';
if (!is_dir($inbox)) {
    if (!mkdir($inbox, 0770, true)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Konnte Inbox nicht anlegen', 'path' => $inbox], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/*
 * Public Key direkt eingebettet.
 * HIER den echten Key 1:1 einfügen.
 */
$publicKeyPem = <<<'PEM'
-----BEGIN PUBLIC KEY-----
HIER_DEIN_PUBLIC_KEY_EINFUEGEN
-----END PUBLIC KEY-----
PEM;

$record = [
    'received_at' => date('c'),
    'remote_ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
    'typ'         => $typ,
    'payload'     => $data
];

$plaintext = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($plaintext === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'JSON-Encoding fehlgeschlagen'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pubKey = openssl_pkey_get_public($publicKeyPem);
if ($pubKey === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Ungültiger Public Key in PHP-Datei'], JSON_UNESCAPED_UNICODE);
    exit;
}

$sealedData = '';
$encryptedKeys = [];
$iv = '';
$cipher = 'AES-256-CBC';

$ok = openssl_seal(
    $plaintext,
    $sealedData,
    $encryptedKeys,
    [$pubKey],
    $cipher,
    $iv
);

if ($ok === false || !isset($encryptedKeys[0])) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Verschlüsselung fehlgeschlagen'], JSON_UNESCAPED_UNICODE);
    exit;
}

$wrapper = [
    'v'          => 1,
    'created_at' => date('c'),
    'typ'        => $typ,
    'cipher'     => $cipher,
    'sha256'     => hash('sha256', $plaintext),
    'ek'         => base64_encode($encryptedKeys[0]),
    'iv'         => base64_encode($iv),
    'ct'         => base64_encode($sealedData),
];

$rand = function_exists('random_int') ? random_int(100000, 999999) : mt_rand(100000, 999999);
$stamp = date('Ymd_His');
$fname = $stamp . '_' . $rand . '.json.enc';
$file  = rtrim($inbox, '/') . '/' . $fname;

$out = json_encode($wrapper, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
if ($out === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Wrapper-JSON-Encoding fehlgeschlagen'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tmp = $file . '.tmp';
if (file_put_contents($tmp, $out . "\n", LOCK_EX) === false || !rename($tmp, $file)) {
    @unlink($tmp);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Konnte verschlüsselte Datei nicht schreiben', 'file' => $file], JSON_UNESCAPED_UNICODE);
    exit;
}

@chmod($file, 0640);

echo json_encode(['ok' => true, 'file' => $fname, 'typ' => $typ], JSON_UNESCAPED_UNICODE);

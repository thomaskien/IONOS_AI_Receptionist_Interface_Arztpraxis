<?php
/*
 * telepraxis-app.php
 * Version: 2.1
 *
 * Fortgeführter Changelog (niemals entfernen, nur ergänzen):
 * - v2.1 (2026-03-31)
 *   - Benachrichtigungston prägnanter umgesetzt: gleicher Ton nun viermal direkt hintereinander, der vierte Ton dreimal so lang.
 *   - Namenszeile im Header nun in allen Spalten linksbündig dargestellt.
 * - v2.0 (2026-03-31)
 *   - Darstellungsbug in der linken Spalte behoben: Karten in Bearbeitung wachsen nun zuverlässig vollständig mit dem Inhalt, auch bei längeren Detailblöcken und Button-Reihen.
 * - v1.9 (2026-03-31)
 *   - Zustand aufgeklappter Gesprächszusammenfassungen wird nun pro Karte im Browser gemerkt und bleibt trotz Polling-Refresh erhalten.
 *   - Namenskopie im Header robuster umgesetzt; Klick auf den Namen kopiert wie besprochen „Nachname, Vorname JJJJ“.
 *   - Klick auf das Geburtsdatum kopiert nun immer das Geburtsdatum in die Zwischenablage.
 *   - In der linken Spalte wird die Namenszeile innerhalb der Karte linksbündig dargestellt.
 * - v1.8 (2026-03-31)
 *   - Karten in Bearbeitung wachsen nun immer mit dem Inhalt; keine fixe Begrenzung der Detailhöhe.
 *   - Block „Zusammenfassung des Gesprächs“ in Bearbeitung zunächst eingeklappt; Klick auf die Überschrift öffnet den Text im gleichen umrandeten Bereich.
 * - v1.7 (2026-03-31)
 *   - Header wieder auf den besprochenen Stand aus v1.5 zurückgeführt: vollständiger umrandeter Kopf über die ganze Kartenbreite.
 *   - Kategorien auf die besprochenen Anzeige-Kategorien festgelegt: Rückruf, Sonstiges, Rezept, Überweisung.
 *   - Geburtsdatum im Kopf nicht mehr fett dargestellt.
 *   - Parser/UI um aktuelle Request-Typen und Felder erweitert, inklusive id/anrufer_id als übermittelte Telefonnummer.
 *   - In Bearbeitung: neuer Block „Zusammenfassung des Gesprächs“ und Anzeige „Übermittelte Telefonnummer“ am Kartenende.
 *   - Vorschautext in Mitte/Abgeschlossen/Papierkorb nutzt zusammenfassung als Fallback, wenn typspezifische Felder leer sind.
 * - v1.6 (2026-03-31)
 *   - Unterstützung neuer Typen/Felder aus dem Telefonassistenten ergänzt.
 * - v1.5 (2026-03-30)
 *   - Bei in Bearbeitung steht „bei Arbeitsplatz“ in der untersten Headerzeile zwischen Dringend-Symbol und Eingangsdatum.
 * - v1.4 (2026-03-30)
 *   - Kategorie wieder umrandet, nicht fett; Body linksbündig; Karten minimal vergrößert; kompakte Texte mit Ellipsis und Tooltip.
 * - v1.3 (2026-03-30)
 *   - Kopf wieder als durchgehend umrandeter Vollbreiten-Header mit drei Zeilen umgesetzt.
 * - v1.2 (2026-03-30)
 *   - Namenszeile im Header mit Kopierfunktion und globaler Dringend-Sortierung ergänzt.
 * - v1.1 (2026-03-30)
 *   - Karteninhalt verschlankt und Button-Sets je Spalte angepasst.
 * - v1.0 (2026-03-30)
 *   - Erstversion als Ein-Datei-Webapp für JSON-Dateien aus ./inbox.
 */

declare(strict_types=1);

session_start();
date_default_timezone_set('Europe/Berlin');

const TELEPRAXIS_APP_NAME = 'telepraxis-app';
const TELEPRAXIS_APP_VERSION = '2.1';
const TELEPRAXIS_INBOX_DIR = __DIR__ . DIRECTORY_SEPARATOR . 'inbox';
const TELEPRAXIS_POLL_INTERVAL_MS = 5000;
const TELEPRAXIS_DEFAULT_TIMEZONE = 'Europe/Berlin';
const TELEPRAXIS_ADMIN_PASSWORD = 'bitte-aendern';
const TELEPRAXIS_WORKPLACE_MAXLEN = 64;

function tp_now_iso(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone(TELEPRAXIS_DEFAULT_TIMEZONE)))->format(DATE_ATOM);
}

function tp_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tp_json_response(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tp_is_admin_enabled(): bool
{
    return TELEPRAXIS_ADMIN_PASSWORD !== '';
}

function tp_is_admin(): bool
{
    return !empty($_SESSION['telepraxis_admin']) && $_SESSION['telepraxis_admin'] === true;
}

function tp_get_csrf_token(): string
{
    if (empty($_SESSION['telepraxis_csrf']) || !is_string($_SESSION['telepraxis_csrf'])) {
        $_SESSION['telepraxis_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['telepraxis_csrf'];
}

function tp_require_csrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals(tp_get_csrf_token(), $token)) {
        tp_json_response(['ok' => false, 'error' => 'Ungültiger CSRF-Token.'], 400);
    }
}

function tp_sanitize_workplace(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    $value = preg_replace('/[^a-zA-Z0-9_-]+/', '', $value) ?? '';
    return substr($value, 0, TELEPRAXIS_WORKPLACE_MAXLEN);
}

function tp_normalize_phone(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^\d+]/', '', $value) ?? '';
    if ($value === '') {
        return '';
    }

    if (strpos($value, '+49') === 0) {
        return '0' . substr($value, 3);
    }
    if (strpos($value, '0049') === 0) {
        return '0' . substr($value, 4);
    }
    if (strpos($value, '49') === 0 && (strlen($value) < 3 || $value[0] !== '0')) {
        return '0' . substr($value, 2);
    }
    if ($value[0] === '+') {
        return ltrim($value, '+');
    }
    return $value;
}

function tp_valid_tel_href(?string $value): string
{
    $normalized = tp_normalize_phone($value);
    if ($normalized === '') {
        return '';
    }
    return preg_match('/^[0-9]+$/', $normalized) ? $normalized : '';
}

function tp_format_datetime(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    try {
        return (new DateTimeImmutable($value))->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return $value;
    }
}

function tp_read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return null;
    }

    try {
        if (!flock($fh, LOCK_SH)) {
            fclose($fh);
            return null;
        }
        $raw = stream_get_contents($fh) ?: '';
        flock($fh, LOCK_UN);
        fclose($fh);
    } catch (Throwable $e) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
        return null;
    }

    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function tp_entry_default_app(array $entry): array
{
    $received = (string)($entry['received_at'] ?? tp_now_iso());
    return [
        'status' => 'neu',
        'dringend' => false,
        'deleted' => false,
        'status_updated_at' => $received,
        'status_updated_arbeitsplatz' => '',
        'last_action' => 'created',
        'completed_at' => null,
        'deleted_at' => null,
        'deleted_arbeitsplatz' => null,
        'in_bearbeitung_at' => null,
    ];
}

function tp_ensure_entry_app(array $entry): array
{
    $defaults = tp_entry_default_app($entry);
    if (!isset($entry['app']) || !is_array($entry['app'])) {
        $entry['app'] = [];
    }
    $entry['app'] = array_merge($defaults, $entry['app']);

    if (!in_array($entry['app']['status'], ['neu', 'in_bearbeitung', 'abgeschlossen'], true)) {
        $entry['app']['status'] = 'neu';
    }
    $entry['app']['dringend'] = (bool)$entry['app']['dringend'];
    $entry['app']['deleted'] = (bool)$entry['app']['deleted'];
    return $entry;
}

function tp_payload(array $entry): array
{
    return isset($entry['payload']) && is_array($entry['payload']) ? $entry['payload'] : [];
}

function tp_entry_typ(array $entry): string
{
    $typ = (string)($entry['typ'] ?? '');
    if ($typ === '' && isset($entry['payload']['typ'])) {
        $typ = (string)$entry['payload']['typ'];
    }
    return strtolower(trim($typ));
}

function tp_payload_value(array $payload, array $keys): string
{
    foreach ($keys as $key) {
        if (isset($payload[$key]) && trim((string)$payload[$key]) !== '') {
            return trim((string)$payload[$key]);
        }
    }
    return '';
}

function tp_entry_category_key(array $entry): string
{
    $typ = tp_entry_typ($entry);
    if ($typ === 'rezeptbestellung' || strpos($typ, 'rezept') !== false) {
        return 'rezept';
    }
    if ($typ === 'ueb_req' || strpos($typ, 'ueberweisung') !== false || strpos($typ, 'überweisung') !== false) {
        return 'ueberweisung';
    }
    if ($typ === 'sonstiges') {
        return 'sonstiges';
    }
    return 'rueckruf';
}

function tp_category_label(string $key): string
{
    switch ($key) {
        case 'rezept':
            return 'Rezept';
        case 'ueberweisung':
            return 'Überweisung';
        case 'sonstiges':
            return 'Sonstiges';
        default:
            return 'Rückruf';
    }
}

function tp_category_order(string $key): int
{
    switch ($key) {
        case 'rueckruf':
        case 'sonstiges':
            return 0;
        case 'ueberweisung':
            return 1;
        case 'rezept':
            return 2;
        default:
            return 9;
    }
}

function tp_birth_year(?string $birthDate): string
{
    $birthDate = trim((string)$birthDate);
    if ($birthDate === '') {
        return '';
    }
    if (preg_match('/^\d{2}\.\d{2}\.(\d{4})$/', $birthDate, $m)) {
        return $m[1];
    }
    if (preg_match('/^(\d{4})-\d{2}-\d{2}$/', $birthDate, $m)) {
        return $m[1];
    }
    return '';
}

function tp_person_strings(array $payload): array
{
    $firstName = tp_payload_value($payload, ['vorname']);
    $lastName = tp_payload_value($payload, ['nachname']);
    $singleName = tp_payload_value($payload, ['name']);
    $birthDate = tp_payload_value($payload, ['geburtsdatum']);

    $nameMain = '';
    if ($lastName !== '' || $firstName !== '') {
        $nameMain = trim($lastName . ', ' . $firstName, ' ,');
    } elseif ($singleName !== '') {
        $nameMain = $singleName;
    }

    $display = $nameMain;
    if ($nameMain !== '' && $birthDate !== '') {
        $display .= ' ' . $birthDate;
    }

    $copy = $nameMain;
    $year = tp_birth_year($birthDate);
    if ($copy !== '' && $year !== '') {
        $copy .= ' ' . $year;
    }

    return [
        'name_main' => $nameMain,
        'birth_date' => $birthDate,
        'display' => $display,
        'copy' => $copy,
    ];
}

function tp_build_main_text(array $entry): string
{
    $payload = tp_payload($entry);
    $typ = tp_entry_typ($entry);

    $summary = tp_payload_value($payload, ['zusammenfassung']);
    $medikamente = tp_payload_value($payload, ['medikamente']);
    $fachrichtung = tp_payload_value($payload, ['fachrichtung']);
    $grund = tp_payload_value($payload, ['grund']);
    $anliegen = tp_payload_value($payload, ['anliegen']);

    if ($typ === 'rezeptbestellung' || strpos($typ, 'rezept') !== false) {
        return $medikamente !== '' ? $medikamente : ($summary !== '' ? $summary : '—');
    }

    if ($typ === 'ueb_req') {
        $parts = [];
        if ($fachrichtung !== '') {
            $parts[] = $fachrichtung;
        }
        if ($grund !== '') {
            $parts[] = $grund;
        }
        if ($parts !== []) {
            return implode(' – ', $parts);
        }
        return $summary !== '' ? $summary : '—';
    }

    if (in_array($typ, ['rueckruf_tel_grund', 'rueckruf_details', 'fallback_name_tel_grund', 'fallback_vn_nn_grund'], true)) {
        return $grund !== '' ? $grund : ($summary !== '' ? $summary : '—');
    }

    if ($typ === 'sonstiges') {
        return $anliegen !== '' ? $anliegen : ($summary !== '' ? $summary : '—');
    }

    if ($typ === 'rueckruf_min') {
        return $summary !== '' ? $summary : 'Bitte zurückrufen.';
    }

    if ($typ === 'fallback_id_zusammenfassung') {
        return $summary !== '' ? $summary : '—';
    }

    return $summary !== '' ? $summary : ($grund !== '' ? $grund : ($anliegen !== '' ? $anliegen : '—'));
}

function tp_build_entry_view(array $entry, string $fileName): array
{
    $entry = tp_ensure_entry_app($entry);
    $payload = tp_payload($entry);
    $categoryKey = tp_entry_category_key($entry);
    $person = tp_person_strings($payload);

    $summary = tp_payload_value($payload, ['zusammenfassung']);
    $displayPhoneRaw = tp_payload_value($payload, ['telefon']);
    $transmittedPhoneRaw = tp_payload_value($payload, ['id', 'anrufer_id']);
    if ($displayPhoneRaw === '') {
        $displayPhoneRaw = $transmittedPhoneRaw;
    }

    $body = tp_build_main_text($entry);

    return [
        'id' => (string)($entry['id'] ?? pathinfo($fileName, PATHINFO_FILENAME)),
        'file' => $fileName,
        'received_at' => (string)($entry['received_at'] ?? ''),
        'received_at_display' => tp_format_datetime((string)($entry['received_at'] ?? '')),
        'type' => tp_entry_typ($entry),
        'category_key' => $categoryKey,
        'category_label' => tp_category_label($categoryKey),
        'category_order' => tp_category_order($categoryKey),
        'status' => (string)$entry['app']['status'],
        'urgent' => (bool)$entry['app']['dringend'],
        'deleted' => (bool)$entry['app']['deleted'],
        'person_name' => $person['name_main'],
        'person_birth_date' => $person['birth_date'],
        'person_display' => $person['display'],
        'person_copy' => $person['copy'],
        'body' => $body,
        'summary' => $summary,
        'telephone_display' => tp_normalize_phone($displayPhoneRaw),
        'telephone_href' => tp_valid_tel_href($displayPhoneRaw),
        'telephone_raw' => $displayPhoneRaw,
        'transmitted_phone_display' => tp_normalize_phone($transmittedPhoneRaw) !== '' ? tp_normalize_phone($transmittedPhoneRaw) : $transmittedPhoneRaw,
        'transmitted_phone_href' => tp_valid_tel_href($transmittedPhoneRaw),
        'transmitted_phone_raw' => $transmittedPhoneRaw,
        'last_updated_at' => (string)($entry['app']['status_updated_at'] ?? ''),
        'last_updated_display' => tp_format_datetime((string)($entry['app']['status_updated_at'] ?? '')),
        'last_workplace' => (string)($entry['app']['status_updated_arbeitsplatz'] ?? ''),
        'deleted_at_display' => tp_format_datetime((string)($entry['app']['deleted_at'] ?? '')),
        'deleted_workplace' => (string)($entry['app']['deleted_arbeitsplatz'] ?? ''),
    ];
}

function tp_list_entries(bool $includeDeleted = true): array
{
    if (!is_dir(TELEPRAXIS_INBOX_DIR)) {
        return [];
    }

    $paths = glob(TELEPRAXIS_INBOX_DIR . DIRECTORY_SEPARATOR . '*.json') ?: [];
    rsort($paths, SORT_STRING);

    $entries = [];
    foreach ($paths as $path) {
        $data = tp_read_json_file($path);
        if (!is_array($data)) {
            continue;
        }
        $view = tp_build_entry_view($data, basename($path));
        if (!$includeDeleted && $view['deleted']) {
            continue;
        }
        $entries[] = $view;
    }
    return $entries;
}

function tp_collect_stats(array $entries): array
{
    $stats = ['neu' => 0, 'in_bearbeitung' => 0, 'abgeschlossen' => 0, 'deleted' => 0, 'urgent' => 0, 'total' => 0];
    foreach ($entries as $entry) {
        $stats['total']++;
        if (!empty($entry['deleted'])) {
            $stats['deleted']++;
            continue;
        }
        if (isset($stats[$entry['status']])) {
            $stats[$entry['status']]++;
        }
        if (!empty($entry['urgent'])) {
            $stats['urgent']++;
        }
    }
    return $stats;
}

function tp_update_file(string $fileName, callable $mutator): array
{
    $safeFile = basename($fileName);
    if (!preg_match('/^[A-Za-z0-9._-]+\.json$/', $safeFile)) {
        throw new RuntimeException('Ungültiger Dateiname.');
    }

    $path = TELEPRAXIS_INBOX_DIR . DIRECTORY_SEPARATOR . $safeFile;
    if (!is_file($path)) {
        throw new RuntimeException('Datei nicht gefunden.');
    }

    $fh = @fopen($path, 'c+');
    if (!$fh) {
        throw new RuntimeException('Datei konnte nicht geöffnet werden.');
    }

    try {
        if (!flock($fh, LOCK_EX)) {
            throw new RuntimeException('Dateisperre fehlgeschlagen.');
        }
        rewind($fh);
        $raw = stream_get_contents($fh) ?: '';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('JSON konnte nicht gelesen werden.');
        }

        $decoded = tp_ensure_entry_app($decoded);
        $updated = $mutator($decoded);
        if (!is_array($updated)) {
            throw new RuntimeException('Interner Fehler beim Aktualisieren.');
        }

        $json = json_encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('JSON konnte nicht gespeichert werden.');
        }

        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $json . "\n");
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);
        return $updated;
    } catch (Throwable $e) {
        @flock($fh, LOCK_UN);
        @fclose($fh);
        throw $e;
    }
}

function tp_action_response(array $entry, string $file): void
{
    tp_json_response([
        'ok' => true,
        'entry' => tp_build_entry_view($entry, $file),
        'csrf' => tp_get_csrf_token(),
    ]);
}

function tp_apply_status(array $entry, string $status, string $workplace): array
{
    $entry = tp_ensure_entry_app($entry);
    $entry['app']['status'] = $status;
    $entry['app']['status_updated_at'] = tp_now_iso();
    $entry['app']['status_updated_arbeitsplatz'] = $workplace;
    $entry['app']['last_action'] = 'status_' . $status;

    if ($status === 'in_bearbeitung') {
        $entry['app']['in_bearbeitung_at'] = tp_now_iso();
    }
    if ($status === 'abgeschlossen') {
        $entry['app']['completed_at'] = tp_now_iso();
    }
    return $entry;
}

function tp_handle_api(): void
{
    $action = (string)($_REQUEST['ajax'] ?? $_POST['action'] ?? '');

    if ($action === 'list') {
        $entries = tp_list_entries(true);
        tp_json_response([
            'ok' => true,
            'entries' => $entries,
            'stats' => tp_collect_stats($entries),
            'is_admin' => tp_is_admin(),
            'csrf' => tp_get_csrf_token(),
        ]);
    }

    if ($action === 'admin_login') {
        tp_require_csrf();
        $password = (string)($_POST['password'] ?? '');
        if (!tp_is_admin_enabled()) {
            tp_json_response(['ok' => false, 'error' => 'Admin-Zugang ist deaktiviert.'], 403);
        }
        if (!hash_equals(TELEPRAXIS_ADMIN_PASSWORD, $password)) {
            tp_json_response(['ok' => false, 'error' => 'Admin-Passwort ist falsch.'], 403);
        }
        $_SESSION['telepraxis_admin'] = true;
        tp_json_response(['ok' => true, 'message' => 'Admin-Zugang aktiviert.', 'csrf' => tp_get_csrf_token()]);
    }

    if ($action === 'admin_logout') {
        tp_require_csrf();
        unset($_SESSION['telepraxis_admin']);
        tp_json_response(['ok' => true, 'message' => 'Admin-Zugang beendet.', 'csrf' => tp_get_csrf_token()]);
    }

    tp_require_csrf();
    $file = (string)($_POST['file'] ?? '');
    $workplace = tp_sanitize_workplace((string)($_POST['workplace'] ?? ''));

    try {
        if ($action === 'set_status') {
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['neu', 'in_bearbeitung', 'abgeschlossen'], true)) {
                tp_json_response(['ok' => false, 'error' => 'Ungültiger Status.'], 400);
            }
            $updated = tp_update_file($file, function (array $entry) use ($status, $workplace): array {
                return tp_apply_status($entry, $status, $workplace);
            });
            tp_action_response($updated, $file);
        }

        if ($action === 'toggle_urgent') {
            $updated = tp_update_file($file, function (array $entry) use ($workplace): array {
                $entry = tp_ensure_entry_app($entry);
                $entry['app']['dringend'] = !$entry['app']['dringend'];
                $entry['app']['status_updated_at'] = tp_now_iso();
                $entry['app']['status_updated_arbeitsplatz'] = $workplace;
                $entry['app']['last_action'] = $entry['app']['dringend'] ? 'urgent_on' : 'urgent_off';
                return $entry;
            });
            tp_action_response($updated, $file);
        }

        if ($action === 'soft_delete') {
            $updated = tp_update_file($file, function (array $entry) use ($workplace): array {
                $entry = tp_ensure_entry_app($entry);
                $entry['app']['deleted'] = true;
                $entry['app']['deleted_at'] = tp_now_iso();
                $entry['app']['deleted_arbeitsplatz'] = $workplace;
                $entry['app']['status_updated_at'] = tp_now_iso();
                $entry['app']['status_updated_arbeitsplatz'] = $workplace;
                $entry['app']['last_action'] = 'soft_delete';
                return $entry;
            });
            tp_action_response($updated, $file);
        }

        if ($action === 'restore') {
            if (!tp_is_admin()) {
                tp_json_response(['ok' => false, 'error' => 'Admin erforderlich.'], 403);
            }
            $updated = tp_update_file($file, function (array $entry) use ($workplace): array {
                $entry = tp_ensure_entry_app($entry);
                $entry['app']['deleted'] = false;
                $entry['app']['deleted_at'] = null;
                $entry['app']['deleted_arbeitsplatz'] = null;
                $entry['app']['status_updated_at'] = tp_now_iso();
                $entry['app']['status_updated_arbeitsplatz'] = $workplace;
                $entry['app']['last_action'] = 'restore';
                return $entry;
            });
            tp_action_response($updated, $file);
        }

        if ($action === 'purge') {
            if (!tp_is_admin()) {
                tp_json_response(['ok' => false, 'error' => 'Admin erforderlich.'], 403);
            }
            $safeFile = basename($file);
            $path = TELEPRAXIS_INBOX_DIR . DIRECTORY_SEPARATOR . $safeFile;
            $entry = tp_read_json_file($path);
            if (!is_array($entry)) {
                tp_json_response(['ok' => false, 'error' => 'Datei konnte nicht gelesen werden.'], 400);
            }
            $entry = tp_ensure_entry_app($entry);
            if (empty($entry['app']['deleted'])) {
                tp_json_response(['ok' => false, 'error' => 'Endlöschung nur aus dem Papierkorb erlaubt.'], 400);
            }
            if (!@unlink($path)) {
                tp_json_response(['ok' => false, 'error' => 'Datei konnte nicht endgültig gelöscht werden.'], 500);
            }
            tp_json_response(['ok' => true, 'message' => 'Datei endgültig gelöscht.', 'csrf' => tp_get_csrf_token()]);
        }

        tp_json_response(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
    } catch (Throwable $e) {
        tp_json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if (isset($_REQUEST['ajax']) || isset($_POST['action'])) {
    tp_handle_api();
}

$initialWorkplace = tp_sanitize_workplace((string)($_GET['arbeitsplatz'] ?? ''));
$csrfToken = tp_get_csrf_token();
$adminEnabled = tp_is_admin_enabled();
$isAdmin = tp_is_admin();
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title><?= tp_h(TELEPRAXIS_APP_NAME) ?> v<?= tp_h(TELEPRAXIS_APP_VERSION) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            --bg: #f4f6f9;
            --panel: #ffffff;
            --panel-border: #d7dce3;
            --text: #1c2430;
            --muted: #677487;
            --green-bg: #eef8ef;
            --yellow-bg: #fff8dd;
            --gray-bg: #f0f2f5;
            --red: #c34c4c;
            --shadow: 0 8px 18px rgba(17, 28, 45, 0.08);
            --radius: 14px;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            background: var(--bg);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
        }
        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .topbar {
            position: sticky;
            top: 0;
            z-index: 10;
            background: rgba(255,255,255,0.96);
            backdrop-filter: blur(8px);
            border-bottom: 1px solid var(--panel-border);
        }
        .topbar-inner {
            display: flex;
            flex-wrap: wrap;
            gap: 12px 18px;
            align-items: center;
            padding: 12px 18px;
        }
        .brand {
            display: flex;
            align-items: baseline;
            gap: 10px;
            margin-right: auto;
        }
        .brand h1 {
            margin: 0;
            font-size: 1.15rem;
        }
        .brand .version,
        .brand .author {
            font-size: 0.95rem;
            color: var(--muted);
        }
        .control-group {
            display: flex;
            align-items: center;
            gap: 8px;
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 999px;
            padding: 8px 12px;
        }
        .control-group label,
        .control-group span {
            font-size: 0.92rem;
            white-space: nowrap;
        }
        .control-group input[type="text"],
        .control-group input[type="password"] {
            border: 1px solid var(--panel-border);
            border-radius: 8px;
            padding: 7px 10px;
            font-size: 0.92rem;
            min-width: 150px;
        }
        .btn {
            border: 1px solid var(--panel-border);
            background: #fff;
            color: var(--text);
            border-radius: 10px;
            padding: 8px 11px;
            font-size: 0.88rem;
            cursor: pointer;
        }
        .btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .btn-primary { background: #edf4ff; border-color: #c4d8f5; }
        .btn-danger { background: #fff3f3; border-color: #f0c9c9; }
        .btn-admin { background: #f4efff; border-color: #d7c9f0; }
        .stats {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            padding: 0 18px 12px 18px;
        }
        .stat {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 0.9rem;
            color: var(--muted);
        }
        .app-shell {
            display: flex;
            flex-direction: column;
            gap: 12px;
            padding: 14px 16px 18px 16px;
            flex: 1;
            min-height: 0;
        }
        .message {
            display: none;
            padding: 11px 14px;
            border-radius: 12px;
            border: 1px solid var(--panel-border);
            background: #fff;
            box-shadow: var(--shadow);
        }
        .message.show { display: block; }
        .message.error { border-color: #e2b8b8; background: #fff3f3; }
        .message.success { border-color: #bfd6bf; background: #f1fbf1; }
        .columns {
            display: grid;
            grid-template-columns: minmax(310px, 1.1fr) minmax(450px, 1.9fr) minmax(280px, 1fr);
            gap: 16px;
            min-height: 0;
            flex: 1;
        }
        .columns.hide-completed {
            grid-template-columns: minmax(310px, 1.1fr) minmax(450px, 2fr);
        }
        .column {
            background: rgba(255,255,255,0.55);
            border: 1px solid var(--panel-border);
            border-radius: 18px;
            min-height: 0;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        .column.hidden { display: none; }
        .column-header {
            padding: 14px 16px;
            border-bottom: 1px solid var(--panel-border);
            background: rgba(255,255,255,0.75);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .column-title { margin: 0; font-size: 1rem; }
        .column-count { color: var(--muted); font-size: 0.92rem; }
        .column-body {
            padding: 14px;
            overflow: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
            min-height: 0;
        }
        .middle-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
        }
        .card {
            background: var(--panel);
            border: 1px solid var(--panel-border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .card.status-neu { background: var(--green-bg); }
        .card.status-in_bearbeitung { background: var(--yellow-bg); }
        .card.status-abgeschlossen { background: var(--gray-bg); }
        .card.urgent { border: 2px solid var(--red); }
        .middle-grid .card {
            min-height: 300px;
            max-height: 300px;
            overflow: hidden;
        }
        .left-column .card { min-height: 0; height: auto; overflow: visible; }
        .left-column .card-body,
        .left-column .detail-block,
        .left-column .transmitted-row,
        .left-column .actions {
            flex: 0 0 auto;
        }
        .left-column .transmitted-row,
        .left-column .actions {
            margin-top: 0;
        }
        .right-column .card { min-height: 200px; }
        .card-header-box {
            width: 100%;
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            background: rgba(255,255,255,0.72);
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 0 0 auto;
        }
        .header-line {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            min-width: 0;
        }
        .header-left {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
            flex: 1;
        }
        .name-line {
            justify-content: center;
        }
        .name-line.name-line-left {
            justify-content: flex-start;
        }
        .name-button {
            appearance: none;
            border: 0;
            background: transparent;
            padding: 0;
            margin: 0;
            font: inherit;
            color: inherit;
            cursor: pointer;
            max-width: 100%;
            display: inline-flex;
            align-items: baseline;
            gap: 6px;
            min-width: 0;
            text-align: inherit;
        }
        .name-main {
            font-weight: 700;
            font-size: 1rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            min-width: 0;
        }
        .name-birth {
            font-weight: 400;
            font-size: 0.98rem;
            color: inherit;
            white-space: nowrap;
            flex: 0 0 auto;
            cursor: pointer;
        }
        .category-tag {
            display: inline-block;
            border: 1px solid var(--panel-border);
            border-radius: 999px;
            padding: 3px 8px;
            font-size: 0.8rem;
            font-weight: 400;
            background: rgba(255,255,255,0.86);
            white-space: nowrap;
        }
        .phone-link,
        .header-date,
        .workplace-chip,
        .transmitted-link {
            color: var(--text);
            text-decoration: none;
        }
        .phone-link,
        .workplace-chip,
        .header-date {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .header-date {
            color: var(--muted);
            flex: 0 0 auto;
            text-align: right;
        }
        .workplace-chip {
            color: var(--muted);
            min-width: 0;
        }
        .urgent-mark {
            color: var(--red);
            font-weight: 700;
            flex: 0 0 auto;
        }
        .card-body {
            color: var(--text);
            font-size: 0.95rem;
            line-height: 1.38;
            text-align: left;
        }
        .body-preview {
            overflow: hidden;
            display: -webkit-box;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 4;
            white-space: pre-wrap;
            text-overflow: ellipsis;
            min-height: 5.55em;
            max-height: 5.55em;
        }
        .body-full {
            white-space: pre-wrap;
        }
        .detail-block {
            border: 1px solid var(--panel-border);
            border-radius: 12px;
            background: rgba(255,255,255,0.58);
            padding: 10px 12px;
        }
        .detail-block h4 {
            margin: 0 0 8px 0;
            font-size: 0.92rem;
        }
        .detail-block p {
            margin: 8px 0 0 0;
            white-space: pre-wrap;
            line-height: 1.4;
            text-align: left;
        }
        .detail-block details {
            display: block;
        }
        .detail-block summary {
            list-style: none;
            cursor: pointer;
            font-size: 0.92rem;
            font-weight: 700;
            outline: none;
        }
        .detail-block summary::-webkit-details-marker {
            display: none;
        }
        .transmitted-row {
            margin-top: auto;
            padding-top: 2px;
            font-size: 0.9rem;
            color: var(--muted);
            text-align: left;
        }
        .transmitted-row strong { color: var(--text); }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: auto;
        }
        .trash-panel {
            display: none;
            background: rgba(255,255,255,0.55);
            border: 1px solid var(--panel-border);
            border-radius: 18px;
            overflow: hidden;
        }
        .trash-panel.show { display: flex; flex-direction: column; }
        .trash-body {
            padding: 14px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .empty {
            border: 1px dashed var(--panel-border);
            border-radius: 14px;
            background: rgba(255,255,255,0.72);
            color: var(--muted);
            padding: 18px;
            text-align: center;
        }
        @media (max-width: 1200px) {
            .columns,
            .columns.hide-completed {
                grid-template-columns: 1fr;
            }
            .middle-grid { grid-template-columns: 1fr; }
            .middle-grid .card { max-height: none; min-height: 260px; }
        }
    </style>
</head>
<body>
<div class="topbar">
    <div class="topbar-inner">
        <div class="brand">
            <h1><?= tp_h(TELEPRAXIS_APP_NAME) ?></h1>
            <span class="version">v<?= tp_h(TELEPRAXIS_APP_VERSION) ?></span>
            <span class="author">von Dr. Thomas Kienzle</span>
        </div>

        <div class="control-group">
            <label for="workplace-input">Arbeitsplatz</label>
            <input type="text" id="workplace-input" value="<?= tp_h($initialWorkplace) ?>" placeholder="z. B. anmeldunglinks">
            <button class="btn btn-primary" id="save-workplace-btn" type="button">Speichern</button>
        </div>

        <div class="control-group">
            <label><input type="checkbox" id="sound-toggle" checked> Ton</label>
            <label><input type="checkbox" id="completed-toggle" checked> Abgeschlossen</label>
            <?php if ($adminEnabled): ?>
                <label><input type="checkbox" id="trash-toggle"> Papierkorb</label>
            <?php endif; ?>
        </div>

        <?php if ($adminEnabled): ?>
            <div class="control-group" id="admin-controls">
                <?php if ($isAdmin): ?>
                    <span>Admin aktiv</span>
                    <button class="btn btn-admin" id="admin-logout-btn" type="button">Abmelden</button>
                <?php else: ?>
                    <label for="admin-password">Admin</label>
                    <input type="password" id="admin-password" placeholder="Passwort">
                    <button class="btn btn-admin" id="admin-login-btn" type="button">Anmelden</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <div class="stats" id="stats-bar"></div>
</div>

<div class="app-shell">
    <div class="message" id="message"></div>

    <div class="columns" id="columns">
        <section class="column left-column">
            <div class="column-header">
                <h2 class="column-title">In Bearbeitung</h2>
                <div class="column-count" id="count-left">0</div>
            </div>
            <div class="column-body" id="left-column"></div>
        </section>

        <section class="column middle-column">
            <div class="column-header">
                <h2 class="column-title">Neu</h2>
                <div class="column-count" id="count-middle">0</div>
            </div>
            <div class="column-body">
                <div class="middle-grid" id="middle-column"></div>
            </div>
        </section>

        <section class="column right-column" id="completed-column">
            <div class="column-header">
                <h2 class="column-title">Abgeschlossen</h2>
                <div class="column-count" id="count-right">0</div>
            </div>
            <div class="column-body" id="right-column"></div>
        </section>
    </div>

    <section class="trash-panel" id="trash-panel">
        <div class="column-header">
            <h2 class="column-title">Papierkorb</h2>
            <div class="column-count" id="count-trash">0</div>
        </div>
        <div class="trash-body" id="trash-body"></div>
    </section>
</div>

<script>
(() => {
    const csrfToken = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    let currentCsrf = csrfToken;
    let isAdmin = <?= $isAdmin ? 'true' : 'false' ?>;
    let audioContext = null;
    let lastSeenIds = new Set();
    let initialized = false;
    let pollTimer = null;
    const openSummaryFiles = new Set();

    const els = {
        workplaceInput: document.getElementById('workplace-input'),
        saveWorkplaceBtn: document.getElementById('save-workplace-btn'),
        soundToggle: document.getElementById('sound-toggle'),
        completedToggle: document.getElementById('completed-toggle'),
        trashToggle: document.getElementById('trash-toggle'),
        adminLoginBtn: document.getElementById('admin-login-btn'),
        adminLogoutBtn: document.getElementById('admin-logout-btn'),
        adminPassword: document.getElementById('admin-password'),
        columns: document.getElementById('columns'),
        completedColumn: document.getElementById('completed-column'),
        leftColumn: document.getElementById('left-column'),
        middleColumn: document.getElementById('middle-column'),
        rightColumn: document.getElementById('right-column'),
        trashPanel: document.getElementById('trash-panel'),
        trashBody: document.getElementById('trash-body'),
        countLeft: document.getElementById('count-left'),
        countMiddle: document.getElementById('count-middle'),
        countRight: document.getElementById('count-right'),
        countTrash: document.getElementById('count-trash'),
        statsBar: document.getElementById('stats-bar'),
        message: document.getElementById('message'),
    };

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function currentWorkplace() {
        return String(els.workplaceInput?.value || '').trim().replace(/[^a-zA-Z0-9_-]+/g, '').slice(0, 64);
    }

    function showMessage(text, isError = false) {
        els.message.textContent = text;
        els.message.className = `message show ${isError ? 'error' : 'success'}`;
        window.clearTimeout(showMessage._timer);
        showMessage._timer = window.setTimeout(() => {
            els.message.className = 'message';
            els.message.textContent = '';
        }, 3500);
    }

    function loadSummaryState() {
        try {
            const stored = JSON.parse(localStorage.getItem('telepraxis_open_summaries') || '[]');
            if (Array.isArray(stored)) {
                stored.forEach(file => {
                    if (typeof file === 'string' && file) openSummaryFiles.add(file);
                });
            }
        } catch (error) {
            // ignorieren
        }
    }

    function saveSummaryState() {
        try {
            localStorage.setItem('telepraxis_open_summaries', JSON.stringify(Array.from(openSummaryFiles)));
        } catch (error) {
            // ignorieren
        }
    }

    function loadLocalSettings() {
        const storedWorkplace = localStorage.getItem('telepraxis_workplace');
        if (storedWorkplace && !els.workplaceInput.value.trim()) {
            els.workplaceInput.value = storedWorkplace;
        }
        const soundEnabled = localStorage.getItem('telepraxis_sound_enabled');
        els.soundToggle.checked = soundEnabled === null ? true : soundEnabled === '1';
        const showCompleted = localStorage.getItem('telepraxis_show_completed');
        els.completedToggle.checked = showCompleted === null ? true : showCompleted === '1';
        if (els.trashToggle) {
            els.trashToggle.checked = localStorage.getItem('telepraxis_show_trash') === '1';
        }
        loadSummaryState();
        applyVisibilitySettings();
    }

    function saveWorkplace() {
        const workplace = currentWorkplace();
        els.workplaceInput.value = workplace;
        localStorage.setItem('telepraxis_workplace', workplace);
        showMessage(workplace ? `Arbeitsplatz gespeichert: ${workplace}` : 'Arbeitsplatz geleert.');
    }

    function applyVisibilitySettings() {
        const showCompleted = !!els.completedToggle.checked;
        localStorage.setItem('telepraxis_show_completed', showCompleted ? '1' : '0');
        if (showCompleted) {
            els.completedColumn.classList.remove('hidden');
            els.columns.classList.remove('hide-completed');
        } else {
            els.completedColumn.classList.add('hidden');
            els.columns.classList.add('hide-completed');
        }
        if (els.trashToggle) {
            localStorage.setItem('telepraxis_show_trash', els.trashToggle.checked ? '1' : '0');
            els.trashPanel.classList.toggle('show', isAdmin && els.trashToggle.checked);
        }
    }

    function ensureAudioContext() {
        if (!audioContext) {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) {
                audioContext = new Ctx();
            }
        }
        if (audioContext && audioContext.state === 'suspended') {
            audioContext.resume().catch(() => {});
        }
    }

    function playNotificationTone() {
        if (!els.soundToggle.checked) return;
        ensureAudioContext();
        if (!audioContext) return;

        const now = audioContext.currentTime;
        const shortDuration = 0.11;
        const longDuration = shortDuration * 3;
        const gap = 0.06;
        const starts = [
            now,
            now + shortDuration + gap,
            now + (shortDuration + gap) * 2,
            now + (shortDuration + gap) * 3,
        ];
        const durations = [shortDuration, shortDuration, shortDuration, longDuration];

        starts.forEach((startTime, index) => {
            const duration = durations[index];
            const gain = audioContext.createGain();
            gain.gain.setValueAtTime(0.0001, startTime);
            gain.gain.exponentialRampToValueAtTime(0.22, startTime + 0.01);
            gain.gain.exponentialRampToValueAtTime(0.0001, startTime + duration);
            gain.connect(audioContext.destination);

            const osc = audioContext.createOscillator();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(1046, startTime);
            osc.connect(gain);
            osc.start(startTime);
            osc.stop(startTime + duration);
        });
    }

    function sortMiddle(a, b) {
        if (!!a.urgent !== !!b.urgent) return a.urgent ? -1 : 1;
        if (Number(a.category_order) !== Number(b.category_order)) return Number(a.category_order) - Number(b.category_order);
        return String(b.received_at || '').localeCompare(String(a.received_at || ''));
    }

    function sortLeft(a, b) {
        if (!!a.urgent !== !!b.urgent) return a.urgent ? -1 : 1;
        if (Number(a.category_order) !== Number(b.category_order)) return Number(a.category_order) - Number(b.category_order);
        return String(b.last_updated_at || '').localeCompare(String(a.last_updated_at || ''));
    }

    function sortRight(a, b) {
        if (!!a.urgent !== !!b.urgent) return a.urgent ? -1 : 1;
        return String(b.last_updated_at || '').localeCompare(String(a.last_updated_at || ''));
    }

    function createActionButtons(entry, placement) {
        const file = escapeHtml(entry.file);
        if (placement === 'trash') {
            return `
                <div class="actions">
                    <button class="btn btn-primary" data-action="restore" data-file="${file}">Wiederherstellen</button>
                    <button class="btn btn-danger" data-action="purge" data-file="${file}">Endgültig löschen</button>
                </div>`;
        }
        if (placement === 'middle') {
            return `
                <div class="actions">
                    <button class="btn btn-primary" data-action="set_status" data-status="in_bearbeitung" data-file="${file}">Bearbeitung</button>
                    <button class="btn ${entry.urgent ? 'btn-danger' : ''}" data-action="toggle_urgent" data-file="${file}">Dringend</button>
                </div>`;
        }
        if (placement === 'left') {
            return `
                <div class="actions">
                    <button class="btn" data-action="set_status" data-status="neu" data-file="${file}">Zurücksetzen</button>
                    <button class="btn btn-primary" data-action="set_status" data-status="abgeschlossen" data-file="${file}">Fertig</button>
                    <button class="btn btn-danger" data-action="soft_delete" data-file="${file}">Löschen</button>
                    <button class="btn ${entry.urgent ? 'btn-danger' : ''}" data-action="toggle_urgent" data-file="${file}">Dringend</button>
                </div>`;
        }
        return `
            <div class="actions">
                <button class="btn btn-danger" data-action="soft_delete" data-file="${file}">Löschen</button>
                <button class="btn" data-action="set_status" data-status="neu" data-file="${file}">Zurücksetzen</button>
            </div>`;
    }

    function createHeader(entry, placement) {
        const nameLineClass = 'header-line name-line name-line-left';
        const nameLine = entry.person_display
            ? `
                <div class="${nameLineClass}">
                    <button type="button" class="name-button" data-copy-name="${escapeHtml(entry.person_copy || '')}" title="${escapeHtml(entry.person_display)}">
                        <span class="name-main">${escapeHtml(entry.person_name || '')}</span>
                    </button>
                    ${entry.person_birth_date ? `<span class="name-birth" data-copy-birth="${escapeHtml(entry.person_birth_date)}" title="${escapeHtml(entry.person_birth_date)}">${escapeHtml(entry.person_birth_date)}</span>` : ''}
                </div>`
            : '';

        const phoneNode = entry.telephone_href
            ? `<a class="phone-link" href="tel:${escapeHtml(entry.telephone_href)}">${escapeHtml(entry.telephone_display || '')}</a>`
            : `<span class="phone-link">${escapeHtml(entry.telephone_display || '—')}</span>`;

        const urgentNode = entry.urgent ? '<span class="urgent-mark">!</span>' : '';
        const workplaceNode = (entry.status === 'in_bearbeitung' && entry.last_workplace)
            ? `<span class="workplace-chip" title="${escapeHtml(entry.last_workplace)}">bei ${escapeHtml(entry.last_workplace)}</span>`
            : '';

        return `
            <div class="card-header-box">
                ${nameLine}
                <div class="header-line">
                    <span class="category-tag">${escapeHtml(entry.category_label)}</span>
                    ${phoneNode}
                </div>
                <div class="header-line">
                    <div class="header-left">
                        ${urgentNode}
                        ${workplaceNode}
                    </div>
                    <div class="header-date">${escapeHtml(entry.received_at_display)}</div>
                </div>
            </div>`;
    }

    function createLeftExtras(entry) {
        const isSummaryOpen = openSummaryFiles.has(String(entry.file || ''));
        const summaryBlock = entry.summary
            ? `<div class="detail-block"><details data-summary-file="${escapeHtml(entry.file || '')}"${isSummaryOpen ? ' open' : ''}><summary>Zusammenfassung des Gesprächs</summary><p>${escapeHtml(entry.summary)}</p></details></div>`
            : '';

        const transmittedValue = entry.transmitted_phone_display || '—';
        const transmittedNode = entry.transmitted_phone_href
            ? `<a class="transmitted-link" href="tel:${escapeHtml(entry.transmitted_phone_href)}">${escapeHtml(transmittedValue)}</a>`
            : `<span>${escapeHtml(transmittedValue)}</span>`;

        return `
            ${summaryBlock}
            <div class="transmitted-row"><strong>Übermittelte Telefonnummer:</strong> ${transmittedNode}</div>`;
    }

    function createCard(entry, placement) {
        const classes = ['card', `status-${entry.status}`];
        if (entry.urgent) classes.push('urgent');
        const bodyClass = placement === 'left' ? 'body-full' : 'body-preview';
        const bodyTitle = escapeHtml(entry.body || '');
        const deletedExtra = placement === 'trash'
            ? `<div class="transmitted-row"><strong>Gelöscht:</strong> ${escapeHtml(entry.deleted_at_display || '—')}</div>`
            : '';

        return `
            <article class="${classes.join(' ')}">
                ${createHeader(entry, placement)}
                <div class="card-body ${bodyClass}" title="${bodyTitle}">${escapeHtml(entry.body || '—')}</div>
                ${placement === 'left' ? createLeftExtras(entry) : ''}
                ${deletedExtra}
                ${createActionButtons(entry, placement)}
            </article>`;
    }

    function createEmpty(text) {
        return `<div class="empty">${escapeHtml(text)}</div>`;
    }

    function renderStats(entries) {
        const workplace = currentWorkplace() || '—';
        const active = entries.filter(e => !e.deleted);
        const neu = active.filter(e => e.status === 'neu').length;
        const bearbeitung = active.filter(e => e.status === 'in_bearbeitung').length;
        const abgeschlossen = active.filter(e => e.status === 'abgeschlossen').length;
        const dringend = active.filter(e => e.urgent).length;
        const geloescht = entries.filter(e => e.deleted).length;
        const pills = [
            `Arbeitsplatz: ${workplace}`,
            `Neu: ${neu}`,
            `In Bearbeitung: ${bearbeitung}`,
            `Abgeschlossen: ${abgeschlossen}`,
            `Dringend: ${dringend}`,
        ];
        if (isAdmin) pills.push(`Papierkorb: ${geloescht}`);
        els.statsBar.innerHTML = pills.map(text => `<div class="stat">${escapeHtml(text)}</div>`).join('');
    }

    function render(entries) {
        const workplace = currentWorkplace();
        const left = entries.filter(entry => !entry.deleted && entry.status === 'in_bearbeitung' && workplace !== '' && entry.last_workplace === workplace).sort(sortLeft);
        const middle = entries.filter(entry => !entry.deleted && (entry.status === 'neu' || (entry.status === 'in_bearbeitung' && (workplace === '' || entry.last_workplace !== workplace)))).sort(sortMiddle);
        const right = entries.filter(entry => !entry.deleted && entry.status === 'abgeschlossen').sort(sortRight);
        const trash = entries.filter(entry => entry.deleted).sort(sortRight);

        els.leftColumn.innerHTML = left.length ? left.map(entry => createCard(entry, 'left')).join('') : createEmpty('Keine Vorgänge in Bearbeitung.');
        els.middleColumn.innerHTML = middle.length ? middle.map(entry => createCard(entry, 'middle')).join('') : createEmpty('Keine neuen Eingänge.');
        els.rightColumn.innerHTML = right.length ? right.map(entry => createCard(entry, 'right')).join('') : createEmpty('Keine abgeschlossenen Vorgänge.');
        els.trashBody.innerHTML = trash.length ? trash.map(entry => createCard(entry, 'trash')).join('') : createEmpty('Papierkorb ist leer.');

        els.countLeft.textContent = String(left.length);
        els.countMiddle.textContent = String(middle.length);
        els.countRight.textContent = String(right.length);
        els.countTrash.textContent = String(trash.length);

        renderStats(entries);
    }

    async function apiRequest(formData) {
        const response = await fetch(window.location.pathname, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Unbekannter Fehler.');
        }
        if (data.csrf) currentCsrf = data.csrf;
        return data;
    }

    async function refresh() {
        try {
            const response = await fetch(`${window.location.pathname}?ajax=list&_=${Date.now()}`, { credentials: 'same-origin', cache: 'no-store' });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Liste konnte nicht geladen werden.');
            if (data.csrf) currentCsrf = data.csrf;
            if (typeof data.is_admin === 'boolean') isAdmin = data.is_admin;

            const ids = new Set((data.entries || []).filter(entry => !entry.deleted).map(entry => entry.file));
            if (initialized) {
                for (const id of ids) {
                    if (!lastSeenIds.has(id)) {
                        playNotificationTone();
                        break;
                    }
                }
            }
            lastSeenIds = ids;
            render(Array.isArray(data.entries) ? data.entries : []);
            applyVisibilitySettings();
            initialized = true;
        } catch (error) {
            showMessage(error.message || 'Aktualisierung fehlgeschlagen.', true);
        }
    }

    async function handleAction(target) {
        const action = target.getAttribute('data-action');
        if (!action) return;

        const formData = new FormData();
        formData.set('csrf', currentCsrf);
        formData.set('action', action);
        formData.set('file', target.getAttribute('data-file') || '');
        formData.set('workplace', currentWorkplace());
        if (target.hasAttribute('data-status')) {
            formData.set('status', target.getAttribute('data-status') || '');
        }

        try {
            await apiRequest(formData);
            await refresh();
        } catch (error) {
            showMessage(error.message || 'Aktion fehlgeschlagen.', true);
        }
    }

    async function adminLogin() {
        const password = String(els.adminPassword?.value || '');
        const formData = new FormData();
        formData.set('csrf', currentCsrf);
        formData.set('action', 'admin_login');
        formData.set('password', password);
        try {
            await apiRequest(formData);
            window.location.reload();
        } catch (error) {
            showMessage(error.message || 'Admin-Anmeldung fehlgeschlagen.', true);
        }
    }

    async function adminLogout() {
        const formData = new FormData();
        formData.set('csrf', currentCsrf);
        formData.set('action', 'admin_logout');
        try {
            await apiRequest(formData);
            window.location.reload();
        } catch (error) {
            showMessage(error.message || 'Admin-Abmeldung fehlgeschlagen.', true);
        }
    }

    async function copyText(text) {
        if (!text) return;
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(text);
                return;
            }
        } catch (error) {
            // Fallback unten
        }
        try {
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            textarea.style.pointerEvents = 'none';
            document.body.appendChild(textarea);
            textarea.focus();
            textarea.select();
            document.execCommand('copy');
            document.body.removeChild(textarea);
        } catch (error) {
            // bewusst ohne sichtbare Bestätigung oder Fehlermeldung
        }
    }

    document.addEventListener('click', event => {
        const actionButton = event.target.closest('[data-action]');
        if (actionButton) {
            event.preventDefault();
            handleAction(actionButton);
            return;
        }
        const birthNode = event.target.closest('[data-copy-birth]');
        if (birthNode) {
            event.preventDefault();
            copyText(birthNode.getAttribute('data-copy-birth') || '');
            return;
        }
        const copyButton = event.target.closest('[data-copy-name]');
        if (copyButton) {
            event.preventDefault();
            copyText(copyButton.getAttribute('data-copy-name') || '');
        }
    });

    document.addEventListener('toggle', event => {
        const details = event.target;
        if (!(details instanceof HTMLDetailsElement) || !details.hasAttribute('data-summary-file')) {
            return;
        }
        const file = String(details.getAttribute('data-summary-file') || '');
        if (!file) return;
        if (details.open) {
            openSummaryFiles.add(file);
        } else {
            openSummaryFiles.delete(file);
        }
        saveSummaryState();
    }, true);

    els.saveWorkplaceBtn?.addEventListener('click', saveWorkplace);
    els.soundToggle?.addEventListener('change', () => localStorage.setItem('telepraxis_sound_enabled', els.soundToggle.checked ? '1' : '0'));
    els.completedToggle?.addEventListener('change', applyVisibilitySettings);
    els.trashToggle?.addEventListener('change', applyVisibilitySettings);
    els.adminLoginBtn?.addEventListener('click', adminLogin);
    els.adminLogoutBtn?.addEventListener('click', adminLogout);
    els.workplaceInput?.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            saveWorkplace();
        }
    });

    document.addEventListener('pointerdown', ensureAudioContext, { once: true });
    loadLocalSettings();
    refresh();
    pollTimer = window.setInterval(refresh, <?= (int)TELEPRAXIS_POLL_INTERVAL_MS ?>);
})();
</script>
</body>
</html>

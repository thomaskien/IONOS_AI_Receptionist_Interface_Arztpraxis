<?php
/*
 * Reiner Parser fuer das SMS-Journal der FRITZ!Box.
 *
 * Changelog:
 * - 2026-09-29: Strikte Validierung des beobachteten Journalformats eingefuehrt.
 *
 * Diese Datei ist nur als Include gedacht und hat keine Webapp-Abhaengigkeit.
 */

declare(strict_types=1);

if (!defined('TELEPRAXIS_APP') && !defined('TELEPRAXIS_SMS_CONFIG')) {
    http_response_code(404);
    exit;
}

function tp_sms_journal_uid(mixed $value): ?string
{
    if (is_int($value)) {
        return $value >= 0 ? (string)$value : null;
    }
    if (!is_string($value) || preg_match('/^\d{1,19}$/D', $value) !== 1) {
        return null;
    }

    return $value;
}

function tp_sms_journal_date(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $matches = [];
    if (preg_match(
        '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(Z|[+-](\d{2}):(\d{2}))$/D',
        $value,
        $matches
    ) !== 1) {
        return null;
    }

    $year = (int)$matches[1];
    $month = (int)$matches[2];
    $day = (int)$matches[3];
    $hour = (int)$matches[4];
    $minute = (int)$matches[5];
    $second = (int)$matches[6];
    if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
        return null;
    }

    if ($matches[7] !== 'Z') {
        $zoneHour = (int)$matches[8];
        $zoneMinute = (int)$matches[9];
        if ($zoneHour > 14 || $zoneMinute > 59 || ($zoneHour === 14 && $zoneMinute !== 0)) {
            return null;
        }
    }

    return $value;
}

/**
 * @return array<int, array{
 *     uid: string|null,
 *     kind: 'received'|'sent'|'unknown',
 *     status: int|null,
 *     date: string|null,
 *     phone: string|null,
 *     text: string|null,
 *     answerable: bool|null,
 *     raw: mixed,
 *     problem: string|null
 * }>
 */
function tp_sms_journal_parse(array $response): array
{
    if (
        !isset($response['data'])
        || !is_array($response['data'])
        || !isset($response['data']['smsListData'])
        || !is_array($response['data']['smsListData'])
        || !array_key_exists('messages', $response['data']['smsListData'])
        || !is_array($response['data']['smsListData']['messages'])
        || !array_is_list($response['data']['smsListData']['messages'])
    ) {
        throw new RuntimeException('SMS-Journal-Antwort hat ein ungueltiges Format.');
    }
    foreach (array_keys($response['data']['smsListData']) as $field) {
        if (!in_array($field, ['messages', 'tfaEnabled'], true)) {
            // Unbekannte Seiten-/Fortsetzungsfelder erlauben keinen Abwesenheitsnachweis.
            throw new RuntimeException('SMS-Journal-Antwort hat ein unbestaetigtes Listenformat.');
        }
    }

    $parsed = [];
    foreach ($response['data']['smsListData']['messages'] as $raw) {
        $entry = [
            'uid' => null,
            'kind' => 'unknown',
            'status' => null,
            'date' => null,
            'phone' => null,
            'text' => null,
            'answerable' => null,
            'raw' => $raw,
            'problem' => 'invalid_row',
        ];

        if (!is_array($raw) || array_is_list($raw)) {
            $parsed[] = $entry;
            continue;
        }

        $entry['uid'] = tp_sms_journal_uid($raw['uid'] ?? null);
        $entry['status'] = isset($raw['status']) && is_int($raw['status']) ? $raw['status'] : null;
        $entry['date'] = tp_sms_journal_date($raw['date'] ?? null);
        $entry['text'] = isset($raw['text']) && is_string($raw['text']) ? $raw['text'] : null;
        $entry['answerable'] = isset($raw['answerable']) && is_bool($raw['answerable'])
            ? $raw['answerable']
            : null;

        $candidateKind = null;
        $phoneField = null;
        if (($raw['status_name'] ?? null) === 'received' && $entry['status'] === 6) {
            $candidateKind = 'received';
            $phoneField = 'sender';
        } elseif (($raw['status_name'] ?? null) === 'sent' && $entry['status'] === 0) {
            $candidateKind = 'sent';
            $phoneField = 'receiver';
        }

        if ($candidateKind === null || $phoneField === null) {
            $entry['problem'] = 'unknown_kind';
            $parsed[] = $entry;
            continue;
        }

        // Das nachgewiesene Format liefert fertige Texte ohne Teilinformationen.
        // Neue Segmentfelder duerfen niemals stillschweigend Einzelantworten ausloesen.
        foreach (array_keys($raw) as $field) {
            if (preg_match('/part|segment|concat|^(udh|pdu)$/i', (string)$field)) {
                $entry['problem'] = 'multipart_metadata_unverified';
                $parsed[] = $entry;
                continue 2;
            }
        }

        $phone = $raw[$phoneField] ?? null;
        $entry['phone'] = is_string($phone) && $phone !== '' ? $phone : null;

        if ($entry['uid'] === null) {
            $entry['problem'] = 'invalid_uid';
        } elseif ($entry['date'] === null) {
            $entry['problem'] = 'invalid_date';
        } elseif ($entry['phone'] === null) {
            $entry['problem'] = 'invalid_phone';
        } elseif ($entry['text'] === null) {
            $entry['problem'] = 'invalid_text';
        } else {
            $entry['kind'] = $candidateKind;
            $entry['problem'] = null;
        }

        $parsed[] = $entry;
    }

    return $parsed;
}

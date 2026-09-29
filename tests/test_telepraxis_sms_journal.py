#!/usr/bin/env python3
"""Tests for the side-effect-free FRITZ!Box SMS journal parser."""

import json
from pathlib import Path
import subprocess
import unittest


ROOT = Path(__file__).resolve().parents[1]
PHP_RUNNER = r"""
define('TELEPRAXIS_APP', true);
require 'telepraxis-sms-journal.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    echo json_encode(
        ['result' => tp_sms_journal_parse($input)],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (RuntimeException $exception) {
    echo json_encode(
        ['exception' => get_class($exception), 'message' => $exception->getMessage()],
        JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}
"""


def envelope(messages):
    return {"data": {"smsListData": {"messages": messages, "tfaEnabled": True}}}


def received(**overrides):
    row = {
        "sender": "+491700000000",
        "answerable": True,
        "status": 6,
        "date": "2026-09-29T12:00:00+02:00",
        "text": "Synthetischer Eingang",
        "uid": 101,
        "status_name": "received",
    }
    row.update(overrides)
    return row


def sent(**overrides):
    row = {
        "receiver": "+491700000000",
        "answerable": True,
        "status": 0,
        "date": "2026-09-29T10:01:00Z",
        "text": "Synthetischer Ausgang",
        "uid": 102,
        "ref": 1,
        "status_name": "sent",
    }
    row.update(overrides)
    return row


class SmsJournalParserTest(unittest.TestCase):
    maxDiff = None

    def parse(self, response):
        process = subprocess.run(
            ["php", "-r", PHP_RUNNER],
            cwd=ROOT,
            input=json.dumps(response, ensure_ascii=False),
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(process.returncode, 0, process.stderr)
        self.assertEqual(process.stderr, "")
        return json.loads(process.stdout)

    def test_observed_received_and_sent_rows(self):
        rows = [received(), sent()]
        output = self.parse(envelope(rows))["result"]

        self.assertEqual([item["kind"] for item in output], ["received", "sent"])
        self.assertEqual([item["uid"] for item in output], ["101", "102"])
        self.assertEqual([item["phone"] for item in output], [
            "+491700000000", "+491700000000"
        ])
        self.assertEqual([item["problem"] for item in output], [None, None])
        self.assertEqual([item["raw"] for item in output], rows)
        self.assertEqual(output[1]["raw"]["ref"], 1)

    def test_empty_journal_is_valid(self):
        self.assertEqual(self.parse(envelope([]))["result"], [])

    def test_unverified_segment_metadata_is_held_instead_of_treated_as_complete(self):
        rows = [received(**{key: 2}) for key in ("part", "totalParts", "segment", "concat", "udh", "pdu")]
        output = self.parse(envelope(rows))["result"]
        self.assertTrue(all(item["kind"] == "unknown" for item in output))
        self.assertTrue(all(item["problem"] == "multipart_metadata_unverified" for item in output))
        self.assertEqual([item["raw"] for item in output], rows)

    def test_unknown_pagination_cannot_be_used_to_prove_absence(self):
        data = envelope([received()])
        data["data"]["smsListData"]["hasMore"] = True
        output = self.parse(data)
        self.assertEqual(output["exception"], "RuntimeException")
        self.assertEqual(output["message"], "SMS-Journal-Antwort hat ein unbestaetigtes Listenformat.")

    def test_unicode_long_text_and_whitespace_are_preserved(self):
        long_text = "  Grüße 🚑 — " + ("lang und unverändert " * 20) + "\n  "
        row = received(text=long_text, sender="Praxis-Info")
        item = self.parse(envelope([row]))["result"][0]

        self.assertEqual(item["kind"], "received")
        self.assertEqual(item["text"], long_text)
        self.assertEqual(item["phone"], "Praxis-Info")

    def test_duplicate_rows_and_order_are_preserved(self):
        first = received(uid="0000000000000000101", text="gleich")
        second = sent(uid="0000000000000000102")
        output = self.parse(envelope([first, first, second, first]))["result"]

        self.assertEqual(len(output), 4)
        self.assertEqual([item["uid"] for item in output], [
            "0000000000000000101",
            "0000000000000000101",
            "0000000000000000102",
            "0000000000000000101",
        ])
        self.assertEqual([item["kind"] for item in output], [
            "received", "received", "sent", "received"
        ])

    def test_missing_required_metadata_keeps_every_problem_row(self):
        cases = [
            ("uid", "invalid_uid", {"uid": None}),
            ("date", "invalid_date", {"date": None}),
            ("sender", "invalid_phone", {"sender": None}),
            ("text", "invalid_text", {"text": None}),
        ]
        rows = [received(**changes) for _, _, changes in cases]
        output = self.parse(envelope(rows))["result"]

        self.assertEqual(len(output), len(rows))
        for item, (field, problem, _) in zip(output, cases):
            with self.subTest(field=field):
                self.assertEqual(item["kind"], "unknown")
                self.assertEqual(item["problem"], problem)

    def test_wrong_types_are_not_cast(self):
        rows = [
            received(uid=True),
            received(uid=12.5),
            received(date=20260929),
            received(sender=491700000000),
            received(text=["kein Text"]),
            received(status="6"),
        ]
        output = self.parse(envelope(rows))["result"]

        self.assertEqual([item["problem"] for item in output], [
            "invalid_uid",
            "invalid_uid",
            "invalid_date",
            "invalid_phone",
            "invalid_text",
            "unknown_kind",
        ])
        self.assertIsNone(output[0]["uid"])
        self.assertIsNone(output[1]["uid"])
        self.assertIsNone(output[2]["date"])
        self.assertIsNone(output[3]["phone"])
        self.assertIsNone(output[4]["text"])
        self.assertIsNone(output[5]["status"])

    def test_uid_accepts_only_nonnegative_int_or_up_to_19_digits(self):
        rows = [
            received(uid=0),
            received(uid="0000000000000000000"),
            received(uid=-1),
            received(uid="12345678901234567890"),
            received(uid="12x"),
        ]
        output = self.parse(envelope(rows))["result"]

        self.assertEqual([item["uid"] for item in output], [
            "0", "0000000000000000000", None, None, None
        ])
        self.assertEqual([item["kind"] for item in output], [
            "received", "received", "unknown", "unknown", "unknown"
        ])

    def test_invalid_calendar_times_and_zones_are_rejected(self):
        invalid_dates = [
            "2025-02-29T12:00:00Z",
            "2026-13-01T12:00:00Z",
            "2026-09-29T24:00:00Z",
            "2026-09-29T12:60:00Z",
            "2026-09-29T12:00:60Z",
            "2026-09-29T12:00:00",
            "2026-09-29T12:00:00+14:01",
            "2026-09-29T12:00:00-15:00",
            "2026-09-29T12:00:00+02",
            "2026-09-29T12:00:00.123Z",
        ]
        output = self.parse(envelope([
            received(date=value) for value in invalid_dates
        ]))["result"]

        self.assertEqual(len(output), len(invalid_dates))
        self.assertTrue(all(item["date"] is None for item in output))
        self.assertTrue(all(item["problem"] == "invalid_date" for item in output))

    def test_valid_leap_day_and_boundary_zones_are_unchanged(self):
        dates = [
            "2024-02-29T00:00:00Z",
            "2026-09-29T23:59:59+14:00",
            "2026-09-29T00:00:00-14:00",
        ]
        output = self.parse(envelope([received(date=value) for value in dates]))["result"]

        self.assertEqual([item["date"] for item in output], dates)
        self.assertTrue(all(item["kind"] == "received" for item in output))

    def test_unknown_status_combinations_remain_unknown(self):
        rows = [
            received(status=5),
            received(status_name="sent"),
            sent(status=1),
            sent(status_name="received"),
            received(status_name="delivered", status=99),
        ]
        output = self.parse(envelope(rows))["result"]

        self.assertEqual([item["status"] for item in output], [5, 6, 1, 0, 99])
        self.assertTrue(all(item["kind"] == "unknown" for item in output))
        self.assertTrue(all(item["problem"] == "unknown_kind" for item in output))

    def test_non_object_rows_are_retained_verbatim(self):
        rows = [None, "sensibler Rohwert", 17, False, ["nested"]]
        output = self.parse(envelope(rows))["result"]

        self.assertEqual(len(output), len(rows))
        self.assertEqual([item["raw"] for item in output], rows)
        self.assertTrue(all(item["kind"] == "unknown" for item in output))
        self.assertTrue(all(item["problem"] == "invalid_row" for item in output))

    def test_extra_fields_and_ref_are_only_preserved_in_raw(self):
        row = received(ref=987, extra={"nested": [1, 2, 3]})
        item = self.parse(envelope([row]))["result"][0]

        self.assertEqual(item["kind"], "received")
        self.assertEqual(item["raw"], row)
        self.assertNotIn("ref", {key: value for key, value in item.items() if key != "raw"})
        self.assertNotIn("extra", {key: value for key, value in item.items() if key != "raw"})

    def test_empty_text_is_valid_but_empty_phone_is_not(self):
        output = self.parse(envelope([
            received(text=""),
            received(sender=""),
            sent(receiver=""),
        ]))["result"]

        self.assertEqual(output[0]["kind"], "received")
        self.assertEqual(output[0]["text"], "")
        self.assertIsNone(output[0]["problem"])
        self.assertEqual([item["problem"] for item in output[1:]], [
            "invalid_phone", "invalid_phone"
        ])

    def test_answerable_is_nullable_and_never_cast(self):
        rows = [
            received(answerable=True),
            received(answerable=False),
            received(answerable=1),
            received(answerable="true"),
            received(answerable=None),
        ]
        output = self.parse(envelope(rows))["result"]

        self.assertEqual([item["answerable"] for item in output], [
            True, False, None, None, None
        ])
        self.assertTrue(all(item["kind"] == "received" for item in output))

    def test_invalid_envelopes_raise_only_a_neutral_exception(self):
        secret = "TOP-SECRET-SMS-CONTENT"
        invalid_envelopes = [
            {},
            {"data": None},
            {"data": {}},
            {"data": {"smsListData": None}},
            {"data": {"smsListData": {}}},
            {"data": {"smsListData": {"messages": secret}}},
            {"data": {"smsListData": {"messages": {"item": received(text=secret)}}}},
        ]

        for invalid in invalid_envelopes:
            with self.subTest(response=invalid):
                output = self.parse(invalid)
                self.assertEqual(output["exception"], "RuntimeException")
                self.assertEqual(
                    output["message"],
                    "SMS-Journal-Antwort hat ein ungueltiges Format.",
                )
                self.assertNotIn(secret, output["message"])

    def test_problem_codes_never_contain_input_values(self):
        secrets = ["SECRET-UID", "SECRET-DATE", "SECRET-PHONE", "SECRET-TEXT"]
        rows = [
            received(uid=secrets[0]),
            received(date=secrets[1]),
            received(sender=""),
            received(text=[secrets[3]]),
        ]
        output = self.parse(envelope(rows))["result"]

        for item in output:
            self.assertIn(item["problem"], {
                "invalid_uid", "invalid_date", "invalid_phone", "invalid_text"
            })
            for secret in secrets:
                self.assertNotIn(secret, item["problem"])


if __name__ == "__main__":
    unittest.main(verbosity=2)

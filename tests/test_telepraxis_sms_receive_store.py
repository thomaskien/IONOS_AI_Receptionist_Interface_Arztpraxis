#!/usr/bin/env python3
"""Integration tests for the local SMS receive store (no real transports)."""

from __future__ import annotations

import json
import os
import sqlite3
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
STORE = ROOT / "telepraxis-sms-receive-store.php"


class ReceiveStoreTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="tp-sms-receive-")
        self.base = Path(self.temp.name)
        self.inbox = self.base / "inbox"
        self.inbox.mkdir(mode=0o770)
        self.db = self.base / "state" / "queue.sqlite"
        self.db.parent.mkdir(mode=0o770)
        self.device = "ab" * 32

    def tearDown(self) -> None:
        self.temp.cleanup()

    def php(self, body: str, *, check: bool = True) -> subprocess.CompletedProcess[str]:
        prelude = f"""<?php
        declare(strict_types=1);
        define('TELEPRAXIS_APP', true);
        require_once {json.dumps(str(STORE))};
        $settings = [
            'queue' => [
                'database_path' => {json.dumps(str(self.db))},
                'delivery_provider' => 'fritz',
                'busy_timeout_ms' => 1000,
            ],
            'sms' => ['max_text_length' => 612],
            'auto_reply' => ['messages' => ['Antwort eins', 'Antwort zwei']],
            'fritzbox' => ['host' => 'fritz.test'],
            'receive' => [
                'enabled' => true,
                'output_mode' => 'target-local',
                'inbox_path' => {json.dumps(str(self.inbox))},
            ],
        ];
        $device = {json.dumps(self.device)};
        """
        result = subprocess.run(
            ["php"],
            input=textwrap.dedent(prelude + body),
            text=True,
            cwd=ROOT,
            capture_output=True,
            timeout=20,
        )
        if check and result.returncode != 0:
            self.fail(
                f"PHP failed ({result.returncode})\nstdout:\n{result.stdout}\nstderr:\n{result.stderr}"
            )
        return result

    @staticmethod
    def php_array(serialized: str) -> str:
        return f"json_decode({json.dumps(serialized)}, true, 512, JSON_THROW_ON_ERROR)"

    @classmethod
    def received(cls, uid: str, text: str = "Bitte zurückrufen 🌍") -> str:
        serialized = json.dumps(
            {
                "uid": uid,
                "kind": "received",
                "status": 0,
                "date": "2026-09-29T10:00:00+02:00",
                "phone": "+491701234567",
                "text": text,
                "answerable": True,
                "raw": {"text": text, "uid": uid},
                "problem": None,
            },
            ensure_ascii=False,
        )
        return cls.php_array(serialized)

    def rows(self, sql: str, parameters: tuple[object, ...] = ()) -> list[sqlite3.Row]:
        connection = sqlite3.connect(self.db)
        connection.row_factory = sqlite3.Row
        try:
            return connection.execute(sql, parameters).fetchall()
        finally:
            connection.close()

    def test_enabled_fingerprint_and_atomic_store_deduplicate_uid_reuse(self) -> None:
        long_text = ("Langer Unicode-Text äöü 🌍 " * 80).rstrip()
        first = self.received("same-uid", long_text)
        reused = self.received("same-uid", "anderer Inhalt")
        unknown_a = self.php_array(json.dumps(
            {
                "uid": "unknown-1",
                "kind": "unknown",
                "status": None,
                "date": None,
                "phone": None,
                "text": None,
                "answerable": None,
                "raw": {"z": [3, 2, 1], "a": {"y": 2, "x": 1}},
                "problem": "invalid_line",
            }
        ))
        unknown_b = self.php_array(json.dumps(
            {
                "uid": "unknown-1",
                "kind": "unknown",
                "raw": {"a": {"x": 1, "y": 2}, "z": [3, 2, 1]},
                "problem": "different_problem_is_not_identity",
            }
        ))
        result = self.php(
            f"""
            $first = {first};
            $reused = {reused};
            $unknownA = {unknown_a};
            $unknownB = {unknown_b};
            $result1 = tp_sms_receive_store($settings, $device, [$first, $reused, $unknownA]);
            $result2 = tp_sms_receive_store($settings, $device, [$first, $unknownB]);
            echo json_encode([
                tp_sms_receive_enabled($settings),
                tp_sms_receive_enabled(['receive' => ['enabled' => 1]]),
                $result1, $result2,
                tp_sms_receive_fingerprint($unknownA) === tp_sms_receive_fingerprint($unknownB),
            ], JSON_THROW_ON_ERROR);
            """
        )
        data = json.loads(result.stdout)
        self.assertEqual(data[:2], [True, False])
        self.assertEqual(data[2], {"stored": 3, "duplicates": 0})
        self.assertEqual(data[3], {"stored": 0, "duplicates": 2})
        self.assertTrue(data[4])
        journal = self.rows(
            "SELECT uid, message_text, raw_json, cleanup_status FROM sms_receive_journal ORDER BY receive_order"
        )
        self.assertEqual(len(journal), 3)
        self.assertEqual(journal[0]["message_text"], long_text)
        self.assertEqual(journal[1]["message_text"], "anderer Inhalt")
        self.assertIn('"z":[3,2,1]', journal[2]["raw_json"])

        # A malformed list must not partially bind or insert anything into a new database.
        other_db = self.base / "atomic" / "queue.sqlite"
        other_db.parent.mkdir(mode=0o770)
        old_db = self.db
        self.db = other_db
        try:
            failed = self.php(
                f"""
                $valid = {first};
                try {{ tp_sms_receive_store($settings, $device, [$valid, 'bad']); }}
                catch (Throwable $e) {{ echo 'caught'; }}
                """
            )
            self.assertEqual(failed.stdout, "caught")
            connection = sqlite3.connect(other_db)
            try:
                tables = connection.execute(
                    "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'sms_receive_%'"
                ).fetchall()
                self.assertEqual(tables, [])
            finally:
                connection.close()
        finally:
            self.db = old_db

    def test_binding_rejects_device_host_and_inbox_changes(self) -> None:
        entry = self.received("bind-1")
        self.php(f"tp_sms_receive_store($settings, $device, [{entry}]);")
        alternate = self.base / "other-inbox"
        alternate.mkdir(mode=0o770)
        result = self.php(
            f"""
            $entry = {entry};
            $out = [];
            foreach (['device', 'host', 'inbox'] as $case) {{
                $changed = $settings;
                $key = $device;
                if ($case === 'device') $key = str_repeat('cd', 32);
                if ($case === 'host') $changed['fritzbox']['host'] = 'other.test';
                if ($case === 'inbox') $changed['receive']['inbox_path'] = {json.dumps(str(alternate))};
                try {{ tp_sms_receive_store($changed, $key, [$entry]); $out[$case] = false; }}
                catch (Throwable $e) {{ $out[$case] = true; }}
            }}
            echo json_encode($out, JSON_THROW_ON_ERROR);
            """
        )
        self.assertEqual(json.loads(result.stdout), {"device": True, "host": True, "inbox": True})
        self.assertEqual(len(self.rows("SELECT * FROM sms_receive_journal")), 1)

    def test_export_exact_payload_app_changes_and_purge_are_not_overwritten(self) -> None:
        text = "Mehrzeilig\nmit Umlauten äöü und Emoji 🌍" * 10
        entry = self.received("export-1", text)
        result = self.php(
            f"""
            tp_sms_receive_store($settings, $device, [{entry}]);
            echo json_encode(tp_sms_receive_export_one($settings), JSON_THROW_ON_ERROR);
            """
        )
        exported = json.loads(result.stdout)
        self.assertEqual(exported["state"], "exported")
        path = self.inbox / f"sms-{exported['record_key']}.json"
        document = json.loads(path.read_text())
        self.assertEqual(path.stat().st_mode & 0o777, 0o660)
        self.assertEqual(list(self.inbox.glob(".sms-*.tmp")), [])
        self.assertEqual(document["received_at"], "2026-09-29T10:00:00+02:00")
        self.assertEqual(document["remote_ip"], "")
        self.assertEqual(document["user_agent"], "FRITZ!Box SMS")
        self.assertEqual(document["payload"]["anliegen"], text)
        self.assertNotIn("name", document["payload"])
        document["status"] = "in_bearbeitung"
        path.write_text(json.dumps(document, ensure_ascii=False))
        again = self.php("var_export(tp_sms_receive_export_one($settings));")
        self.assertEqual(again.stdout, "NULL")
        self.assertEqual(json.loads(path.read_text())["status"], "in_bearbeitung")
        path.unlink()
        after_purge = self.php("var_export(tp_sms_receive_export_one($settings));")
        self.assertEqual(after_purge.stdout, "NULL")
        self.assertFalse(path.exists())

    def test_export_collision_symlink_and_interrupted_recovery(self) -> None:
        entries = [self.received(f"collision-{index}") for index in range(4)]
        self.php(f"tp_sms_receive_store($settings, $device, [{','.join(entries)}]);")
        rows = self.rows("SELECT record_key FROM sms_receive_journal ORDER BY receive_order")
        paths = [self.inbox / f"sms-{row['record_key']}.json" for row in rows]
        paths[0].write_text("{}")
        paths[1].symlink_to(paths[0])
        first = json.loads(self.php("echo json_encode(tp_sms_receive_export_one($settings));").stdout)
        second = json.loads(self.php("echo json_encode(tp_sms_receive_export_one($settings));").stdout)
        self.assertEqual(first["failure_code"], "output_collision")
        self.assertEqual(second["failure_code"], "output_collision")

        connection = sqlite3.connect(self.db)
        try:
            connection.execute(
                "UPDATE sms_receive_journal SET export_status='exporting' WHERE record_key=?",
                (rows[2]["record_key"],),
            )
            connection.commit()
        finally:
            connection.close()
        missing = json.loads(self.php("echo json_encode(tp_sms_receive_export_one($settings));").stdout)
        self.assertEqual(missing["failure_code"], "output_missing_after_interruption")

        key = rows[3]["record_key"]
        existing = {
            "sms": {"received_key": key, "device_key": self.device},
            "status": "abgeschlossen",
        }
        paths[3].write_text(json.dumps(existing))
        connection = sqlite3.connect(self.db)
        try:
            connection.execute(
                "UPDATE sms_receive_journal SET export_status='exporting' WHERE record_key=?", (key,)
            )
            connection.commit()
        finally:
            connection.close()
        recovered = json.loads(self.php("echo json_encode(tp_sms_receive_export_one($settings));").stdout)
        self.assertEqual(recovered["state"], "exported")
        self.assertEqual(json.loads(paths[3].read_text())["status"], "abgeschlossen")

    def test_disabled_reply_is_frozen_and_phone_is_strict(self) -> None:
        entry = self.received("disabled-1")
        result = self.php(
            f"""
            $settings['auto_reply']['messages'] = [];
            tp_sms_receive_store($settings, $device, [{entry}]);
            tp_sms_receive_export_one($settings);
            $settings['auto_reply']['messages'] = ['spaeter aktiviert'];
            echo json_encode([
                tp_sms_receive_reply_one($settings),
                tp_sms_receive_normalize_phone('+491701234567'),
                tp_sms_receive_normalize_phone('00491701234567'),
                tp_sms_receive_normalize_phone('01701234567'),
                tp_sms_receive_normalize_phone('Absender +491701234567'),
                tp_sms_receive_normalize_phone('12345'),
            ], JSON_THROW_ON_ERROR);
            """
        )
        data = json.loads(result.stdout)
        self.assertEqual(data[0]["reply_status"], "disabled")
        self.assertEqual(data[1:4], ["+491701234567"] * 3)
        self.assertEqual(data[4:], [None, None])
        self.assertEqual(self.rows("SELECT COUNT(*) AS count FROM sms_queue")[0]["count"], 0)

    def test_reply_batch_is_idempotent_after_simulated_status_interruption(self) -> None:
        entry = self.received("reply-1")
        result = self.php(
            f"""
            tp_sms_receive_store($settings, $device, [{entry}]);
            $export = tp_sms_receive_export_one($settings);
            $row = tp_sms_receive_open($settings)->query(
                "SELECT record_key, phone, reply_snapshot_json FROM sms_receive_journal LIMIT 1"
            )->fetch();
            $frozen = tp_sms_receive_frozen_reply_settings($settings, $row['reply_snapshot_json']);
            // Simuliert: Queue-Batch committed, Prozess vor Receive-Statusupdate beendet.
            tp_sms_queue_auto_reply($frozen['settings'], $row['phone'], $row['record_key'], [
                'source_file' => 'sms-' . $row['record_key'] . '.json', 'workplace' => 'SMS'
            ]);
            $settings['auto_reply']['messages'] = ['geaendert'];
            $settings['queue']['delivery_provider'] = 'seven';
            echo json_encode(tp_sms_receive_reply_one($settings), JSON_THROW_ON_ERROR);
            """
        )
        reply = json.loads(result.stdout)
        self.assertEqual(reply["reply_status"], "queued")
        jobs = self.rows(
            "SELECT delivery_provider, message, workplace FROM sms_queue ORDER BY enqueue_order"
        )
        self.assertEqual([row["message"] for row in jobs], ["Antwort eins", "Antwort zwei"])
        self.assertEqual([row["delivery_provider"] for row in jobs], ["fritz", "fritz"])
        self.assertEqual([row["workplace"] for row in jobs], ["SMS", "SMS"])

    def test_reply_recipient_allows_only_full_german_numbers(self) -> None:
        result = self.php("""
            $numbers = ['+491701234567', '00491701234567', '01701234567', '030123456',
                '2202', '123456', '01234', '+492202', '+49123456', '+4901701234567',
                'Netzbetreiber', 'Info +491701234567', '+436641234567', '00436641234567',
                '+41791234567', '+447700900123', '+12025550123', null];
            echo json_encode(array_map('tp_sms_receive_reply_recipient', $numbers));
        """)
        self.assertEqual(json.loads(result.stdout),
                         ['+491701234567'] * 3 + ['+4930123456'] + [None] * 14)

    def test_foreign_and_short_senders_export_without_any_reply_jobs(self) -> None:
        result = self.php(f"""
            $template = {self.received('101')};
            $entries=[];
            foreach (['+436641234567', '0041791234567', '2202', '+49123456', 'Netzbetreiber'] as $i=>$phone) {{
                $entry=$template;
                $entry['uid']=(string)(101+$i);
                $entry['phone']=$phone;
                $entry['raw']['uid']=$entry['uid'];
                $entry['raw']['sender']=$phone;
                $entries[]=$entry;
            }}
            tp_sms_receive_store($settings,$device,$entries);
            $results=[];
            foreach ($entries as $unused) {{
                $results[]=[tp_sms_receive_export_one($settings),tp_sms_receive_reply_one($settings)];
            }}
            echo json_encode($results);
        """)
        for exported, reply in json.loads(result.stdout):
            self.assertEqual(exported['state'], 'exported')
            self.assertEqual(reply['reply_status'], 'skipped')
        self.assertEqual(len(list(self.inbox.glob('sms-*.json'))), 5)
        self.assertEqual(self.rows('SELECT COUNT(*) AS n FROM sms_queue')[0]['n'], 0)
        again = self.php('echo json_encode(tp_sms_receive_reply_one($settings));')
        self.assertIsNone(json.loads(again.stdout))

    def test_invalid_reply_is_error_and_enqueue_failure_has_backoff(self) -> None:
        invalid_entry = self.received("reply-invalid")
        invalid = self.php(
            f"""
            $settings['auto_reply']['messages'] = [str_repeat('x', 71)];
            $settings['sms']['max_text_length'] = 100;
            tp_sms_receive_store($settings, $device, [{invalid_entry}]);
            tp_sms_receive_export_one($settings);
            echo json_encode(tp_sms_receive_reply_one($settings), JSON_THROW_ON_ERROR);
            """
        )
        invalid_reply = json.loads(invalid.stdout)
        self.assertEqual(invalid_reply["reply_status"], "error")
        self.assertEqual(invalid_reply["failure_code"], "invalid_reply_configuration")

        retry_entry = self.received("reply-retry")
        self.php(
            f"""
            $retry = {retry_entry};
            $retry['phone'] = '+491709999999';
            $retry['raw']['sender'] = $retry['phone'];
            tp_sms_receive_store($settings, $device, [$retry]);
            tp_sms_receive_export_one($settings);
            """
        )
        connection = sqlite3.connect(self.db)
        try:
            connection.execute(
                """CREATE TRIGGER reject_reply_queue BEFORE INSERT ON sms_queue
                   BEGIN SELECT RAISE(ABORT, 'synthetic queue failure'); END"""
            )
            connection.commit()
        finally:
            connection.close()
        result = self.php(
            """
            $first = tp_sms_receive_reply_one($settings);
            $second = tp_sms_receive_reply_one($settings);
            echo json_encode([$first, $second], JSON_THROW_ON_ERROR);
            """
        )
        first, second = json.loads(result.stdout)
        self.assertEqual(first["reply_status"], "pending")
        self.assertEqual(first["failure_code"], "reply_enqueue_failed")
        self.assertIsNone(second)
        row = self.rows(
            "SELECT reply_attempts, reply_next_attempt_at, created_at FROM sms_receive_journal WHERE reply_status='pending'"
        )[0]
        self.assertEqual(row["reply_attempts"], 1)
        self.assertGreater(row["reply_next_attempt_at"], row["created_at"])
        self.assertEqual(self.rows("SELECT COUNT(*) AS count FROM sms_queue")[0]["count"], 0)

    def test_cleanup_callback_runs_without_transaction_and_retries_without_reply(self) -> None:
        entry = self.received("cleanup-1")
        initial = self.php(
            f"""
            tp_sms_receive_store($settings, $device, [{entry}]);
            tp_sms_receive_export_one($settings);
            tp_sms_receive_reply_one($settings);
            $result = tp_sms_receive_cleanup_one($settings, $device, function($s, $d, $uid, $fingerprint) {{
                // This needs its own write transaction and would fail if receive held one.
                tp_sms_queue_enqueue($s, '+491701111111', 'Callback-Test', [
                    'request_id' => hash('sha256', 'callback-write'),
                    'source_file' => 'callback.json', 'workplace' => 'Test'
                ]);
                return ['deleted' => false];
            }});
            echo json_encode($result, JSON_THROW_ON_ERROR);
            """
        )
        cleanup = json.loads(initial.stdout)
        self.assertEqual(cleanup["cleanup_status"], "pending")
        self.assertEqual(cleanup["failure_code"], "delete_not_confirmed")
        before = self.rows("SELECT COUNT(*) AS count FROM sms_queue")[0]["count"]
        second = self.php(
            """
            $called = false;
            $result = tp_sms_receive_cleanup_one($settings, $device, function() use (&$called) {
                $called = true; return ['deleted' => true];
            });
            echo json_encode([$result, $called], JSON_THROW_ON_ERROR);
            """
        )
        self.assertEqual(json.loads(second.stdout), [None, False])
        self.assertEqual(self.rows("SELECT COUNT(*) AS count FROM sms_queue")[0]["count"], before)

    def test_wrong_device_and_database_failure_never_call_deleter(self) -> None:
        entry = self.received("safe-delete")
        self.php(f"tp_sms_receive_store($settings, $device, [{entry}]);")
        marker = self.base / "callback-called"
        wrong = self.php(
            f"""
            try {{
                tp_sms_receive_cleanup_one($settings, str_repeat('cd', 32), function() {{
                    file_put_contents({json.dumps(str(marker))}, 'called');
                    return ['deleted' => true];
                }});
            }} catch (Throwable $e) {{ echo 'rejected'; }}
            """
        )
        self.assertEqual(wrong.stdout, "rejected")
        self.assertFalse(marker.exists())
        self.db.write_bytes(b"not a sqlite database")
        broken = self.php(
            f"""
            try {{
                tp_sms_receive_cleanup_one($settings, $device, function() {{
                    file_put_contents({json.dumps(str(marker))}, 'called');
                    return ['deleted' => true];
                }});
            }} catch (Throwable $e) {{ echo 'db-error'; }}
            """
        )
        self.assertEqual(broken.stdout, "db-error")
        self.assertFalse(marker.exists())

    def test_cleanup_marks_old_queue_cleanup_and_reconcile_absent(self) -> None:
        observed = self.received("old-observed")
        result = self.php(
            f"""
            $job1 = tp_sms_queue_enqueue($settings, '+491701111111', 'Alt eins', [
                'request_id' => hash('sha256', 'old-1'), 'source_file' => 'old1.json', 'workplace' => 'Test'
            ]);
            $job2 = tp_sms_queue_enqueue($settings, '+491701111111', 'Alt zwei', [
                'request_id' => hash('sha256', 'old-2'), 'source_file' => 'old2.json', 'workplace' => 'Test'
            ]);
            tp_sms_queue_record_cleanup($settings, $job1['job_id'], 'old-observed');
            tp_sms_queue_record_cleanup($settings, $job2['job_id'], 'old-absent');
            $entry = {observed};
            tp_sms_receive_store($settings, $device, [$entry]);
            tp_sms_receive_reconcile_absent($settings, $device, [$entry]);
            $cleanup = tp_sms_receive_cleanup_one($settings, $device, function($s, $d, $uid, $fingerprint) {{
                return ['absent' => true];
            }});
            echo json_encode($cleanup, JSON_THROW_ON_ERROR);
            """
        )
        cleanup = json.loads(result.stdout)
        self.assertEqual(cleanup["cleanup_status"], "done")
        statuses = self.rows(
            "SELECT message_uid, status FROM sms_queue_cleanup ORDER BY message_uid"
        )
        self.assertEqual({row["message_uid"]: row["status"] for row in statuses}, {
            "old-absent": "done",
            "old-observed": "done",
        })

    def test_unknown_without_uid_is_held_and_counts_are_read_only(self) -> None:
        # A Queue-only database must not gain receive tables through the status function.
        self.php(
            """
            tp_sms_queue_open($settings, true);
            echo json_encode(tp_sms_receive_status_counts($settings), JSON_THROW_ON_ERROR);
            """
        )
        tables = self.rows(
            "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'sms_receive_%'"
        )
        self.assertEqual(tables, [])

        unknown = self.php_array(json.dumps(
            {
                "uid": None,
                "kind": "unknown",
                "status": None,
                "date": None,
                "phone": None,
                "text": None,
                "answerable": None,
                "raw": {"private": "vollständig erhalten", "nested": [1, {"b": 2}]},
                "problem": "malformed_entry",
            },
            ensure_ascii=False,
        ))
        result = self.php(
            f"""
            tp_sms_receive_store($settings, $device, [{unknown}]);
            echo json_encode(tp_sms_receive_status_counts($settings), JSON_THROW_ON_ERROR);
            """
        )
        counts = json.loads(result.stdout)
        self.assertEqual(counts["journal_cleanup_pending"], 0)
        self.assertEqual(counts["journal_unclassified"], 1)
        row = self.rows(
            "SELECT raw_json, problem, cleanup_status, cleanup_failure_code FROM sms_receive_journal"
        )[0]
        self.assertEqual(json.loads(row["raw_json"])["private"], "vollständig erhalten")
        self.assertEqual(row["problem"], "malformed_entry")
        self.assertEqual(row["cleanup_status"], "held")
        self.assertEqual(row["cleanup_failure_code"], "missing_uid")

    def test_reappearing_router_copy_only_restarts_cleanup(self) -> None:
        entry = self.received("101")
        result = self.php(f"""
            $entry = {entry};
            tp_sms_receive_store($settings, $device, [$entry]);
            tp_sms_receive_export_one($settings);
            tp_sms_receive_reply_one($settings);
            tp_sms_receive_cleanup_one($settings, $device, fn() => ['deleted' => true]);
            $before = tp_sms_receive_status_counts($settings);
            $saved = tp_sms_receive_store($settings, $device, [$entry]);
            echo json_encode([$before, $saved, tp_sms_receive_export_one($settings),
                tp_sms_receive_reply_one($settings), tp_sms_receive_status_counts($settings)]);
        """)
        before, saved, exported, reply, after = json.loads(result.stdout)
        self.assertEqual(before["journal_cleanup_pending"], 0)
        self.assertEqual(saved, {"stored": 0, "duplicates": 1})
        self.assertIsNone(exported)
        self.assertIsNone(reply)
        self.assertEqual(after["journal_cleanup_pending"], 1)
        self.assertEqual(len(list(self.inbox.glob("sms-*.json"))), 1)
        self.assertEqual(self.rows("SELECT COUNT(*) AS n FROM sms_queue")[0]["n"], 2)

    def test_unknown_uid_does_not_prove_old_cleanup_absent(self) -> None:
        result = self.php("""
            $job = tp_sms_queue_enqueue($settings, '+491700000000', 'Test', [
                'request_id' => hash('sha256', 'unknown-uid'), 'source_file' => 'test.json', 'workplace' => 'Test'
            ]);
            tp_sms_queue_record_cleanup($settings, $job['job_id'], '101');
            $unknown = ['uid' => null, 'kind' => 'unknown', 'raw' => ['unrecognized' => true]];
            tp_sms_receive_store($settings, $device, [$unknown]);
            tp_sms_receive_reconcile_absent($settings, $device, [$unknown]);
            echo json_encode(tp_sms_queue_cleanup_pending_count($settings));
        """)
        self.assertEqual(json.loads(result.stdout), 1)

    def test_cleanup_failure_is_visible_in_status_counts(self) -> None:
        result = self.php(f"""
            tp_sms_receive_store($settings, $device, [{self.received('101')}]);
            tp_sms_receive_cleanup_one($settings, $device, fn() => ['deleted' => false]);
            echo json_encode(tp_sms_receive_status_counts($settings));
        """)
        self.assertEqual(json.loads(result.stdout)["journal_cleanup_failed"], 1)

    def test_unlimited_general_text_limit_keeps_fritz_limit(self) -> None:
        result = self.php("""
            echo json_encode([
                tp_sms_receive_frozen_reply_settings($settings, json_encode([
                    'messages' => ['Antwort'], 'provider' => 'fritz', 'max_text_length' => 0
                ])) !== null,
                tp_sms_receive_frozen_reply_settings($settings, json_encode([
                    'messages' => [str_repeat('x', 71)], 'provider' => 'fritz', 'max_text_length' => 0
                ])) === null
            ]);
        """)
        self.assertEqual(json.loads(result.stdout), [True, True])

    def test_inbox_security_rejects_symlink_and_world_writable(self) -> None:
        real = self.base / "real-inbox"
        real.mkdir(mode=0o770)
        link = self.base / "linked-inbox"
        link.symlink_to(real, target_is_directory=True)
        result = self.php(
            f"""
            $entry = {self.received('secure-path')};
            $out = [];
            $settings['receive']['inbox_path'] = {json.dumps(str(link))};
            try {{ tp_sms_receive_store($settings, $device, [$entry]); $out[] = false; }}
            catch (Throwable $e) {{ $out[] = true; }}
            chmod({json.dumps(str(real))}, 0777);
            $settings['receive']['inbox_path'] = {json.dumps(str(real))};
            try {{ tp_sms_receive_store($settings, $device, [$entry]); $out[] = false; }}
            catch (Throwable $e) {{ $out[] = true; }}
            echo json_encode($out, JSON_THROW_ON_ERROR);
            """
        )
        self.assertEqual(json.loads(result.stdout), [True, True])


if __name__ == "__main__":
    unittest.main(verbosity=2)

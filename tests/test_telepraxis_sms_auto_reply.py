"""Configurable reply sequences and atomic queue insertion, without network access."""

from __future__ import annotations

import json
from pathlib import Path
import shutil
import sqlite3
import subprocess
import tempfile
import unittest


ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which("php")
PREFIX = """
define('TELEPRAXIS_SMS_CONFIG', true);
require $argv[1] . '/telepraxis-sms.php';
require $argv[1] . '/telepraxis-sms-queue.php';
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$settings = tp_sms_merge_settings(tp_sms_default_settings(), $input);
"""


@unittest.skipUnless(PHP, "PHP CLI required")
class SmsAutoReplyTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.database = Path(self.temp.name).resolve() / "queue.sqlite"
        self.settings = {
            "queue": {"database_path": str(self.database), "delivery_provider": "fritz"},
            "fritzbox": {"host": "http://example.invalid", "username": "dummy", "password": "dummy"},
        }

    def php(self, body, settings=None):
        result = subprocess.run(
            [PHP, "-d", "display_errors=stderr", "-r", PREFIX + body, str(ROOT)],
            input=json.dumps(self.settings if settings is None else settings),
            text=True, capture_output=True, timeout=10,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def reply(self, key="device-a:received-001"):
        return self.php(
            "echo json_encode(tp_sms_queue_auto_reply($settings,'+491700000000',"
            + json.dumps(key) + ",['source_file'=>'incoming.json']));"
        )

    def test_default_is_exactly_the_two_agreed_messages(self):
        messages = self.php("echo json_encode(tp_sms_auto_reply_messages($settings));")
        self.assertEqual(messages, [
            "1/2 SMS an die Praxis weitergeleitet. Testbetrieb! Vielen Dank!",
            "2/2 Fehlen persönliche Daten, bitte eine neue vollständige SMS senden.",
        ])
        self.assertEqual([len(message.encode("utf-16-le")) // 2 for message in messages], [63, 70])
        example = json.loads((ROOT / "config/sms-queue-worker.example.json").read_text())
        self.assertEqual(example["auto_reply"]["messages"], messages)

    def test_override_replaces_entire_list_including_empty_disable(self):
        for messages in (["Eigene kurze Antwort"], ["Eins", "Zwei", "Drei"], []):
            with self.subTest(messages=messages):
                settings = {**self.settings, "auto_reply": {"messages": messages}}
                actual = self.php("echo json_encode(tp_sms_auto_reply_messages($settings));", settings)
                self.assertEqual(actual, messages)
        self.settings["auto_reply"] = {"messages": []}
        result = self.reply()
        self.assertFalse(result["queued"])
        self.assertEqual(result["jobs"], [])
        self.assertFalse(self.database.exists())

    def test_invalid_second_message_does_not_enqueue_the_first(self):
        for messages in (["OK", "x" * 71], ["OK", ""], ["OK", 123], "Ein Text", {"part": "OK"}):
            with self.subTest(messages=messages):
                settings = {**self.settings, "auto_reply": {"messages": messages}}
                result = self.php(
                    "try { tp_sms_queue_auto_reply($settings,'+491700000000','incoming-key'); echo 'false'; } "
                    "catch(Throwable $e) { echo 'true'; }", settings,
                )
                self.assertTrue(result)
                self.assertFalse(self.database.exists())

    def test_invalid_reply_config_does_not_silently_enable_defaults(self):
        for config in (None, False, "text"):
            with self.subTest(config=config):
                settings = {**self.settings, "auto_reply": config}
                result = self.php(
                    "try { tp_sms_queue_auto_reply($settings,'+491700000000','key'); echo 'false'; } "
                    "catch(Throwable $e) { echo 'true'; }", settings,
                )
                self.assertTrue(result)
                self.assertFalse(self.database.exists())

    def test_persistence_retries_and_new_received_messages(self):
        first = self.reply()
        second = self.reply()
        self.assertFalse(first["duplicate"])
        self.assertTrue(second["duplicate"])
        self.assertEqual([j["job_id"] for j in first["jobs"]], [j["job_id"] for j in second["jobs"]])
        self.assertEqual(len(first["jobs"]), 2)
        third = self.reply("device-a:received-002")
        self.assertNotEqual(first["batch_id"], third["batch_id"])
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 4)

    def test_changed_reply_for_same_incoming_message_does_not_send_again(self):
        self.reply()
        self.settings["auto_reply"] = {"messages": ["Geänderter Text"]}
        result = self.php(
            "try { tp_sms_queue_auto_reply($settings,'+491700000000','device-a:received-001',"
            "['source_file'=>'incoming.json']); echo 'false'; } catch(Throwable $e) { echo 'true'; }"
        )
        self.assertTrue(result)
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 2)

    def test_send_order_and_restart_do_not_repeat_accepted_parts(self):
        self.reply()
        first = self.php(
            "$sent=[]; $sender=static function($s,$p,$r,$m) use (&$sent) { $sent[]=$m; "
            "return ['ok'=>true,'details'=>['message_uid'=>'test']]; }; "
            "tp_sms_queue_process_one($settings,$sender); echo json_encode($sent);"
        )
        self.reply()  # Repeated receive poll after a process restart.
        rest = self.php(
            "$sent=[]; $sender=static function($s,$p,$r,$m) use (&$sent) { $sent[]=$m; "
            "return ['ok'=>true,'details'=>['message_uid'=>'test']]; }; "
            "tp_sms_queue_process_one($settings,$sender); tp_sms_queue_process_one($settings,$sender); "
            "echo json_encode($sent);"
        )
        self.assertEqual(len(first), 1)
        self.assertEqual(len(rest), 1)
        self.assertTrue(first[0].startswith("1/2 "))
        self.assertTrue(rest[0].startswith("2/2 "))

    def test_database_error_rolls_back_all_parts(self):
        self.php("tp_sms_queue_open($settings,true); echo 'true';")
        with sqlite3.connect(self.database) as db:
            db.execute("CREATE TRIGGER reject_second BEFORE INSERT ON sms_queue "
                       "WHEN NEW.message='zweite' BEGIN SELECT RAISE(ABORT,'internal-details'); END")
        self.settings["auto_reply"] = {"messages": ["erste", "zweite"]}
        result = self.php(
            "try { tp_sms_queue_auto_reply($settings,'+491700000000','key'); echo 'null'; } "
            "catch(Throwable $e) { echo json_encode($e->getMessage()); }"
        )
        self.assertNotIn("internal-details", result)
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 0)
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue_batches").fetchone()[0], 0)

    def test_old_database_migrates_without_losing_existing_jobs(self):
        self.php("tp_sms_queue_enqueue($settings,'+491700000000','Bisheriger Auftrag'); echo 'true';")
        with sqlite3.connect(self.database) as db:
            db.execute("DROP TABLE sms_queue_batches")
            db.execute("PRAGMA user_version=1")
        result = self.reply()
        self.assertEqual(len(result["jobs"]), 2)
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("PRAGMA user_version").fetchone()[0], 3)
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 3)
            self.assertEqual(db.execute("SELECT message FROM sms_queue ORDER BY enqueue_order LIMIT 1").fetchone()[0],
                             "Bisheriger Auftrag")

    def test_concurrent_reply_calls_create_one_sequence(self):
        body = PREFIX + "echo json_encode(tp_sms_queue_auto_reply($settings,'+491700000000','concurrent-key'));"
        processes = [subprocess.Popen(
            [PHP, "-r", body, str(ROOT)], stdin=subprocess.PIPE,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
        ) for _ in range(4)]
        # Queue the input for each producer before waiting for any of them.
        for process in processes:
            process.stdin.write(json.dumps(self.settings))
            process.stdin.close()
            process.stdin = None
        results = [process.communicate(timeout=10) + (process.returncode,) for process in processes]
        self.assertTrue(all(code == 0 and not err for out, err, code in results), results)
        payloads = [json.loads(out) for out, err, code in results]
        self.assertEqual(sum(not result["duplicate"] for result in payloads), 1)
        self.assertEqual(len({result["batch_id"] for result in payloads}), 1)
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 2)


if __name__ == "__main__":
    unittest.main()

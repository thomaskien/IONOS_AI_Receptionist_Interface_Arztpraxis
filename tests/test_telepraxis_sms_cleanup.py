"""Persistent FRITZ!Box cleanup jobs; all transport and deletion calls are injected."""

from __future__ import annotations

import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import time
import unittest


ROOT = Path(__file__).resolve().parents[1]
SMS = ROOT / "telepraxis-sms.php"
QUEUE = ROOT / "telepraxis-sms-queue.php"
WORKER = ROOT / "telepraxis-sms-worker.php"
PHP_PREFIX = f"""
define('TELEPRAXIS_SMS_CONFIG', true);
require {json.dumps(str(SMS))};
require {json.dumps(str(QUEUE))};
"""


class SmsCleanupTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="tp-sms-cleanup-")
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name).resolve()
        os.chmod(self.directory, 0o770)
        self.database = self.directory / "queue.sqlite"
        self.settings = {
            "queue": {
                "database_path": str(self.database),
                "delivery_provider": "fritz",
                "busy_timeout_ms": 2000,
            },
            "fritzbox": {
                "host": "fritz.test",
                "username": "worker",
                "password": "secret",
                "delete_after_send": True,
            },
        }

    def php(self, body: str, *args: str, check: bool = True) -> subprocess.CompletedProcess[str]:
        result = subprocess.run(
            ["php", "-d", "display_errors=1", "-r", PHP_PREFIX + body, *args],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=15,
        )
        if check and result.returncode != 0:
            self.fail(f"PHP failed ({result.returncode}): {result.stderr}\n{result.stdout}")
        return result

    def enqueue(self, request_id: str = "a" * 32) -> str:
        result = self.php(
            "$s=json_decode($argv[1],true); $r=tp_sms_queue_enqueue($s,'+49123','Test',"
            "['request_id'=>$argv[2]]); echo json_encode($r);",
            json.dumps(self.settings),
            request_id,
        )
        return json.loads(result.stdout)["job_id"]

    def due_now(self) -> None:
        with sqlite3.connect(self.database) as db:
            db.execute(
                "UPDATE sms_queue_cleanup SET next_attempt_at='1970-01-01T00:00:00.000000Z' "
                "WHERE status='pending'"
            )

    def cleanup_rows(self) -> list[tuple]:
        with sqlite3.connect(self.database) as db:
            return db.execute(
                "SELECT job_id,message_uid,fritzbox_host,status,attempts,failure_code "
                "FROM sms_queue_cleanup ORDER BY created_at,id"
            ).fetchall()

    def test_accepted_send_records_uid_idempotently_and_cleanup_finishes(self) -> None:
        job_id = self.enqueue()
        sent = self.php(
            "$s=json_decode($argv[1],true); $sender=static function($a,$b,$c,$d,$context) {"
            "$context['on_fritz_message_created']('uid-accepted');"
            "return ['ok'=>true,'details'=>['message_uid'=>'uid-accepted']]; };"
            "echo json_encode(tp_sms_queue_process_one($s,$sender));",
            json.dumps(self.settings),
        )
        self.assertEqual(json.loads(sent.stdout), {"job_id": job_id, "status": "accepted"})
        self.assertEqual(
            self.cleanup_rows(),
            [(job_id, "uid-accepted", "fritz.test", "pending", 0, None)],
        )

        deleted = self.php(
            "$s=json_decode($argv[1],true); $deleter=static fn($settings,$uid)=>"
            "['deleted'=>$uid==='uid-accepted'];"
            "echo json_encode(tp_sms_queue_process_cleanup_one($s,$deleter));",
            json.dumps(self.settings),
        )
        self.assertEqual(json.loads(deleted.stdout)["status"], "done")
        self.assertEqual(self.cleanup_rows()[0][3:], ("done", 0, None))

    def test_delete_failure_retries_without_sending_again(self) -> None:
        job_id = self.enqueue()
        marker = self.directory / "sent"
        self.php(
            "$s=json_decode($argv[1],true); $sender=static function($a,$b,$c,$d,$context) use ($argv) {"
            "file_put_contents($argv[2],'sent'); $context['on_fritz_message_created']('uid-retry');"
            "return ['ok'=>true,'details'=>['message_uid'=>'uid-retry']]; };"
            "echo json_encode(tp_sms_queue_process_one($s,$sender));",
            json.dumps(self.settings),
            str(marker),
        )
        failed = self.php(
            "$s=json_decode($argv[1],true); echo json_encode(tp_sms_queue_process_cleanup_one($s,"
            "static fn()=>['deleted'=>false]));",
            json.dumps(self.settings),
        )
        self.assertEqual(json.loads(failed.stdout)["failure_code"], "delete_not_confirmed")
        self.assertEqual(self.cleanup_rows()[0][3:], ("pending", 1, "delete_not_confirmed"))

        self.due_now()
        retried = self.php(
            "$s=json_decode($argv[1],true); $send=tp_sms_queue_process_one($s,static function(){exit(19);});"
            "$cleanup=tp_sms_queue_process_cleanup_one($s,static fn()=>['deleted'=>true]);"
            "echo json_encode([$send,$cleanup]);",
            json.dumps(self.settings),
        )
        send_result, cleanup_result = json.loads(retried.stdout)
        self.assertIsNone(send_result)
        self.assertEqual(cleanup_result["status"], "done")
        self.assertEqual(marker.read_text(), "sent")
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("SELECT status FROM sms_queue WHERE id=?", (job_id,)).fetchone()[0],
                             "accepted")

    def test_callback_survives_later_sender_exception_and_waits_until_not_sending(self) -> None:
        job_id = self.enqueue()
        result = self.php(
            "$s=json_decode($argv[1],true); $sender=static function($a,$b,$c,$d,$context) {"
            "$context['on_fritz_message_created']('uid-before-error');"
            "throw new RuntimeException('private transport detail'); };"
            "echo json_encode(tp_sms_queue_process_one($s,$sender));",
            json.dumps(self.settings),
        )
        self.assertEqual(json.loads(result.stdout), {"job_id": job_id, "status": "uncertain"})
        self.assertEqual(self.cleanup_rows()[0][1:4], ("uid-before-error", "fritz.test", "pending"))

        with sqlite3.connect(self.database) as db:
            db.execute("UPDATE sms_queue SET status='sending' WHERE id=?", (job_id,))
        blocked = self.php(
            "$s=json_decode($argv[1],true); echo json_encode(tp_sms_queue_process_cleanup_one($s,"
            "static function(){exit(21);}));",
            json.dumps(self.settings),
        )
        self.assertIsNone(json.loads(blocked.stdout))
        with sqlite3.connect(self.database) as db:
            db.execute("UPDATE sms_queue SET status='uncertain' WHERE id=?", (job_id,))
        self.due_now()
        allowed = self.php(
            "$s=json_decode($argv[1],true); echo json_encode(tp_sms_queue_process_cleanup_one($s,"
            "static fn()=>['deleted'=>true]));",
            json.dumps(self.settings),
        )
        self.assertEqual(json.loads(allowed.stdout)["status"], "done")

    def test_callback_persists_uid_across_worker_crash_without_resending(self) -> None:
        job_id = self.enqueue()
        crashed = self.php(
            "$s=json_decode($argv[1],true); tp_sms_queue_process_one($s,"
            "static function($a,$b,$c,$d,$context) {"
            "$context['on_fritz_message_created']('uid-before-crash'); exit(17); });",
            json.dumps(self.settings),
            check=False,
        )
        self.assertEqual(crashed.returncode, 17)
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("SELECT status FROM sms_queue WHERE id=?", (job_id,)).fetchone()[0],
                             "sending")
        self.assertEqual(self.cleanup_rows()[0][1], "uid-before-crash")

        recovered = self.php(
            "$s=json_decode($argv[1],true); $send=tp_sms_queue_process_one($s,static function(){exit(18);});"
            "$cleanup=tp_sms_queue_process_cleanup_one($s,static fn()=>['deleted'=>true]);"
            "echo json_encode([$send,$cleanup]);",
            json.dumps(self.settings),
        )
        send_result, cleanup_result = json.loads(recovered.stdout)
        self.assertIsNone(send_result)
        self.assertEqual(cleanup_result["status"], "done")
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("SELECT status FROM sms_queue WHERE id=?", (job_id,)).fetchone()[0],
                             "uncertain")

    def test_host_change_and_missing_credentials_never_call_deleter(self) -> None:
        job_id = self.enqueue()
        with sqlite3.connect(self.database) as db:
            db.execute("UPDATE sms_queue SET status='accepted' WHERE id=?", (job_id,))
        self.php(
            "$s=json_decode($argv[1],true); tp_sms_queue_record_cleanup($s,$argv[2],'uid-host');",
            json.dumps(self.settings),
            job_id,
        )
        changed = json.loads(json.dumps(self.settings))
        changed["fritzbox"]["host"] = "other-router.test"
        result = self.php(
            "$s=json_decode($argv[1],true); echo json_encode(tp_sms_queue_process_cleanup_one($s,"
            "static function(){exit(22);}));",
            json.dumps(changed),
        )
        self.assertEqual(json.loads(result.stdout)["failure_code"], "target_host_changed")

        self.due_now()
        missing = json.loads(json.dumps(self.settings))
        missing["fritzbox"]["password"] = ""
        result = self.php(
            "$s=json_decode($argv[1],true); echo json_encode(tp_sms_queue_process_cleanup_one($s,"
            "static function(){exit(23);}));",
            json.dumps(missing),
        )
        self.assertEqual(json.loads(result.stdout)["failure_code"], "invalid_configuration")
        self.assertEqual(self.cleanup_rows()[0][4], 2)

    def test_cleanup_storage_failure_stops_sender_and_is_not_retried_as_send(self) -> None:
        job_id = self.enqueue()
        with sqlite3.connect(self.database) as db:
            db.execute("CREATE TRIGGER reject_cleanup BEFORE INSERT ON sms_queue_cleanup "
                       "BEGIN SELECT RAISE(ABORT, 'private database detail'); END")
        result = self.php(
            "$s=json_decode($argv[1],true); $send=tp_sms_queue_process_one($s,"
            "static function($a,$b,$c,$d,$context) {"
            "$context['on_fritz_message_created']('uid-not-persisted'); exit(25); });"
            "$retry=tp_sms_queue_process_one($s,static function(){exit(26);});"
            "echo json_encode([$send,$retry]);",
            json.dumps(self.settings),
        )
        self.assertEqual(json.loads(result.stdout), [{"job_id": job_id, "status": "uncertain"}, None])
        self.assertNotIn("private database detail", result.stdout + result.stderr)
        self.assertEqual(self.cleanup_rows(), [])
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("SELECT failure_code FROM sms_queue WHERE id=?", (job_id,))
                             .fetchone()[0], "cleanup_persistence_failed")

    def test_uid_capture_is_idempotent_and_schema_1_and_2_migrations_preserve_jobs(self) -> None:
        job_id = self.enqueue()
        with sqlite3.connect(self.database) as db:
            db.execute("DROP TABLE sms_queue_cleanup")
            db.execute("DROP TABLE sms_queue_batches")
            db.execute("PRAGMA user_version=1")
        self.php(
            "$s=json_decode($argv[1],true); tp_sms_queue_open($s,true); echo 'ok';",
            json.dumps(self.settings),
        )
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("PRAGMA user_version").fetchone()[0], 3)
            self.assertEqual(db.execute("SELECT id FROM sms_queue").fetchone()[0], job_id)
            db.execute("DROP TABLE sms_queue_cleanup")
            db.execute("PRAGMA user_version=2")
        self.php(
            "$s=json_decode($argv[1],true); tp_sms_queue_open($s,true); echo 'ok';",
            json.dumps(self.settings),
        )
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("PRAGMA user_version").fetchone()[0], 3)
            self.assertEqual(db.execute("SELECT id FROM sms_queue").fetchone()[0], job_id)
        for _ in range(3):
            self.php(
                "$s=json_decode($argv[1],true); tp_sms_queue_record_cleanup($s,$argv[2],'uid-once');",
                json.dumps(self.settings),
                job_id,
            )
        self.assertEqual(len(self.cleanup_rows()), 1)

    def test_concurrent_cleanup_is_exclusive_and_database_writable_during_io(self) -> None:
        job_id = self.enqueue()
        with sqlite3.connect(self.database) as db:
            db.execute("UPDATE sms_queue SET status='accepted' WHERE id=?", (job_id,))
        self.php(
            "$s=json_decode($argv[1],true); tp_sms_queue_record_cleanup($s,$argv[2],'uid-concurrent');",
            json.dumps(self.settings),
            job_id,
        )
        started = self.directory / "started"
        marker = self.directory / "deleted"
        code = PHP_PREFIX + (
            "$s=json_decode($argv[1],true); $deleter=static function($settings,$uid) use ($argv) {"
            "file_put_contents($argv[2],'yes');"
            "tp_sms_queue_enqueue($settings,'+49999','Write during delete',"
            "['request_id'=>str_repeat('b',32)]);"
            "file_put_contents($argv[3],$uid.PHP_EOL,FILE_APPEND|LOCK_EX); usleep(500000);"
            "return ['deleted'=>true]; };"
            "echo json_encode(tp_sms_queue_process_cleanup_one($s,$deleter));"
        )
        first = subprocess.Popen(
            ["php", "-r", code, json.dumps(self.settings), str(started), str(marker)],
            cwd=ROOT, text=True, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        )
        deadline = time.monotonic() + 5
        while not started.exists() and time.monotonic() < deadline:
            time.sleep(0.02)
        self.assertTrue(started.exists(), "first cleanup worker did not enter deleter")
        second = self.php(
            "$s=json_decode($argv[1],true); echo json_encode(tp_sms_queue_process_cleanup_one($s,"
            "static function(){exit(24);}));",
            json.dumps(self.settings),
        )
        first_stdout, first_stderr = first.communicate(timeout=5)
        self.assertEqual(first.returncode, 0, first_stderr)
        self.assertEqual(json.loads(first_stdout)["status"], "done")
        self.assertIsNone(json.loads(second.stdout))
        self.assertEqual(marker.read_text().splitlines(), ["uid-concurrent"])
        with sqlite3.connect(self.database) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 2)

    def test_once_reports_delayed_cleanup_failure_without_network_access(self) -> None:
        job_id = self.enqueue()
        with sqlite3.connect(self.database) as db:
            db.execute("UPDATE sms_queue SET status='accepted' WHERE id=?", (job_id,))
        self.php(
            "$s=json_decode($argv[1],true); tp_sms_queue_record_cleanup($s,$argv[2],'uid-cli');"
            "tp_sms_queue_process_cleanup_one($s,static fn()=>['deleted'=>false]);",
            json.dumps(self.settings),
            job_id,
        )
        config = self.directory / "worker.json"
        config.write_text(json.dumps(self.settings))
        result = subprocess.run(
            ["php", str(WORKER), "--config", str(config), "--once"],
            cwd=ROOT, text=True, capture_output=True, timeout=5,
        )
        self.assertEqual(result.returncode, 1, result.stderr)
        self.assertIn("Ausstehende Löschfehler: 1", result.stdout)
        self.assertNotIn("uid-cli", result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()

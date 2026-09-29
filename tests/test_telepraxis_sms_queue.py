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


class SmsQueueTests(unittest.TestCase):
    def setUp(self) -> None:
        self.temp = tempfile.TemporaryDirectory(prefix="tp-sms-queue-")
        self.directory = Path(self.temp.name).resolve()
        os.chmod(self.directory, 0o770)
        self.database = self.directory / "queue.sqlite"

    def tearDown(self) -> None:
        self.temp.cleanup()

    def settings(self, provider: str = "seven", valid_credentials: bool = True) -> dict:
        return {
            "queue": {
                "database_path": str(self.database),
                "delivery_provider": provider,
                "busy_timeout_ms": 2000,
                "poll_interval_seconds": 0.1,
            },
            "seven": {"api_key": "test-key" if valid_credentials else ""},
            "fritzbox": {
                "host": "fritz.test",
                "username": "worker",
                "password": "secret",
                "totp_secret": "JBSWY3DPEHPK3PXP",
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

    def enqueue(
        self,
        request_id: str,
        recipient: str = "+491234567",
        message: str = "Vertrauliche Nachricht",
        source_file: str = "vorgang.json",
        provider: str = "seven",
    ) -> dict:
        result = self.php(
            "$s=json_decode($argv[1],true); $r=tp_sms_queue_enqueue($s,$argv[3],$argv[4],"
            "['request_id'=>$argv[2],'source_file'=>$argv[5],'workplace'=>'Anmeldung']);"
            "echo json_encode($r);",
            json.dumps(self.settings(provider)),
            request_id,
            recipient,
            message,
            source_file,
        )
        return json.loads(result.stdout)

    def test_enqueue_persists_is_idempotent_and_source_query_reads_only(self) -> None:
        first = self.enqueue("a" * 32)
        second = self.enqueue("a" * 32)
        self.assertFalse(first["duplicate"])
        self.assertTrue(second["duplicate"])
        self.assertEqual(first["job_id"], second["job_id"])
        self.assertEqual(first["provider"], "queue")
        self.assertEqual(self.database.stat().st_mode & 0o777, 0o660)

        result = self.php(
            "$s=json_decode($argv[1],true); echo json_encode(tp_sms_queue_for_source($s,$argv[2]));",
            json.dumps(self.settings()),
            "vorgang.json",
        )
        rows = json.loads(result.stdout)
        self.assertEqual(len(rows), 1)
        self.assertEqual(rows[0]["recipient"], "+491234567")
        self.assertEqual(rows[0]["message"], "Vertrauliche Nachricht")
        self.assertEqual(rows[0]["status"], "pending")
        self.assertEqual(rows[0]["delivery_provider"], "seven")

        missing = self.directory / "missing.sqlite"
        settings = self.settings()
        settings["queue"]["database_path"] = str(missing)
        result = self.php(
            "$s=json_decode($argv[1],true); echo json_encode(tp_sms_queue_for_source($s,'none.json'));",
            json.dumps(settings),
        )
        self.assertEqual(json.loads(result.stdout), [])
        self.assertFalse(missing.exists())

    def test_request_id_conflict_is_rejected_without_payload_in_error(self) -> None:
        self.enqueue("b" * 32)
        result = self.php(
            "$s=json_decode($argv[1],true); try { tp_sms_queue_enqueue($s,'+49999','Andere Nachricht',"
            "['request_id'=>str_repeat('b',32),'source_file'=>'vorgang.json','workplace'=>'Anmeldung']); }"
            "catch(Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(7); }",
            json.dumps(self.settings()),
            check=False,
        )
        self.assertEqual(result.returncode, 7)
        self.assertNotIn("+49999", result.stderr)
        self.assertNotIn("Andere Nachricht", result.stderr)

    def test_concurrent_idempotent_enqueue_creates_one_job(self) -> None:
        code = (
            PHP_PREFIX
            + "$s=json_decode($argv[1],true); $r=tp_sms_queue_enqueue($s,'+49123','Parallel',"
            "['request_id'=>str_repeat('c',32),'source_file'=>'parallel.json']); echo json_encode($r);"
        )
        processes = [
            subprocess.Popen(
                ["php", "-r", code, json.dumps(self.settings())],
                cwd=ROOT,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            for _ in range(8)
        ]
        results = [process.communicate(timeout=15) + (process.returncode,) for process in processes]
        self.assertTrue(all(returncode == 0 for _, _, returncode in results), results)
        payloads = [json.loads(stdout) for stdout, _, _ in results]
        self.assertEqual(len({payload["job_id"] for payload in payloads}), 1)
        self.assertEqual(sum(not payload["duplicate"] for payload in payloads), 1)
        with sqlite3.connect(self.database) as connection:
            self.assertEqual(connection.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 1)

    def test_sender_runs_without_database_transaction_and_status_is_accepted(self) -> None:
        first = self.enqueue("d" * 32)
        result = self.php(
            "$s=json_decode($argv[1],true); $sender=function($settings,$provider,$recipient,$message) use ($s) {"
            "tp_sms_queue_enqueue($s,'+49222','Producer waehrend Versand',"
            "['request_id'=>str_repeat('e',32),'source_file'=>'zweiter.json']); return ['ok'=>true]; };"
            "echo json_encode(tp_sms_queue_process_one($s,$sender));",
            json.dumps(self.settings()),
        )
        self.assertEqual(json.loads(result.stdout), {"job_id": first["job_id"], "status": "accepted"})
        with sqlite3.connect(self.database) as connection:
            statuses = connection.execute(
                "SELECT status FROM sms_queue ORDER BY enqueue_order"
            ).fetchall()
        self.assertEqual(statuses, [("accepted",), ("pending",)])

    def test_two_consumers_do_not_send_the_same_job(self) -> None:
        self.enqueue("f" * 32)
        marker = self.directory / "sent.log"
        started = self.directory / "started"
        code = (
            PHP_PREFIX
            + "$s=json_decode($argv[1],true); $sender=function() use ($argv) {"
            "file_put_contents($argv[2],'started'); file_put_contents($argv[3],\"send\\n\",FILE_APPEND|LOCK_EX);"
            "usleep(600000); return ['ok'=>true]; }; echo json_encode(tp_sms_queue_process_one($s,$sender));"
        )
        first = subprocess.Popen(
            ["php", "-r", code, json.dumps(self.settings()), str(started), str(marker)],
            cwd=ROOT,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        deadline = time.monotonic() + 5
        while not started.exists() and time.monotonic() < deadline:
            time.sleep(0.02)
        self.assertTrue(started.exists(), "first worker did not enter sender")
        second = subprocess.run(
            ["php", "-r", code, json.dumps(self.settings()), str(started), str(marker)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=5,
        )
        first_stdout, first_stderr = first.communicate(timeout=5)
        self.assertEqual(first.returncode, 0, first_stderr)
        self.assertEqual(second.returncode, 0, second.stderr)
        self.assertIsNone(json.loads(second.stdout))
        self.assertEqual(marker.read_text().splitlines(), ["send"])
        self.assertEqual(json.loads(first_stdout)["status"], "accepted")

    def test_recovery_marks_sending_uncertain_without_retry(self) -> None:
        crashed = self.enqueue("1" * 32)
        pending = self.enqueue("2" * 32, message="Naechster Auftrag")
        with sqlite3.connect(self.database) as connection:
            connection.execute("UPDATE sms_queue SET status='sending' WHERE id=?", (crashed["job_id"],))
            connection.commit()
        marker = self.directory / "sent.log"
        result = self.php(
            "$s=json_decode($argv[1],true); $sender=function() use ($argv) {"
            "file_put_contents($argv[2],\"send\\n\",FILE_APPEND); return ['ok'=>true]; };"
            "echo json_encode(tp_sms_queue_process_one($s,$sender));",
            json.dumps(self.settings()),
            str(marker),
        )
        self.assertEqual(json.loads(result.stdout), {"job_id": pending["job_id"], "status": "accepted"})
        self.assertEqual(marker.read_text().splitlines(), ["send"])
        with sqlite3.connect(self.database) as connection:
            statuses = dict(connection.execute("SELECT id,status FROM sms_queue"))
        self.assertEqual(statuses[crashed["job_id"]], "uncertain")

    def test_transport_exception_and_negative_confirmation_are_uncertain(self) -> None:
        first = self.enqueue("3" * 32)
        exception_result = self.php(
            "$s=json_decode($argv[1],true); $sender=function() { throw new RuntimeException('private'); };"
            "echo json_encode(tp_sms_queue_process_one($s,$sender));",
            json.dumps(self.settings()),
        )
        self.assertEqual(json.loads(exception_result.stdout), {"job_id": first["job_id"], "status": "uncertain"})

        second = self.enqueue("4" * 32)
        negative_result = self.php(
            "$s=json_decode($argv[1],true); echo json_encode(tp_sms_queue_process_one($s,"
            "fn()=>['ok'=>false]));",
            json.dumps(self.settings()),
        )
        self.assertEqual(json.loads(negative_result.stdout), {"job_id": second["job_id"], "status": "uncertain"})

    def test_fritz_requires_uid_and_forces_delete_off(self) -> None:
        first = self.enqueue("5" * 32, provider="fritz")
        unclear = self.php(
            "$s=json_decode($argv[1],true); $sender=function($settings) {"
            "if ($settings['fritzbox']['delete_after_send'] !== false) throw new Exception('delete enabled');"
            "return ['ok'=>true,'details'=>[]]; }; echo json_encode(tp_sms_queue_process_one($s,$sender));",
            json.dumps(self.settings("fritz")),
        )
        self.assertEqual(json.loads(unclear.stdout), {"job_id": first["job_id"], "status": "uncertain"})

        second = self.enqueue("6" * 32, provider="fritz")
        accepted = self.php(
            "$s=json_decode($argv[1],true); $sender=function($settings) {"
            "if ($settings['fritzbox']['delete_after_send'] !== false) throw new Exception('delete enabled');"
            "return ['ok'=>true,'details'=>['message_uid'=>'uid-1']]; };"
            "echo json_encode(tp_sms_queue_process_one($s,$sender));",
            json.dumps(self.settings("fritz")),
        )
        self.assertEqual(json.loads(accepted.stdout), {"job_id": second["job_id"], "status": "accepted"})
        with sqlite3.connect(self.database) as connection:
            cleanup = connection.execute(
                "SELECT job_id,message_uid,status FROM sms_queue_cleanup"
            ).fetchall()
        self.assertEqual(cleanup, [(second["job_id"], "uid-1", "pending")])

    def test_invalid_provider_and_pretransport_config_fail_safely(self) -> None:
        for provider in ("queue", "none"):
            invalid_settings = self.settings()
            invalid_settings["queue"]["delivery_provider"] = provider
            result = self.php(
                "$s=json_decode($argv[1],true); tp_sms_queue_enqueue($s,'+49123','Text');",
                json.dumps(invalid_settings),
                check=False,
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse(self.database.exists())

        job = self.enqueue("7" * 32)
        called = self.directory / "called"
        result = self.php(
            "$s=json_decode($argv[1],true); $sender=function() use ($argv) { file_put_contents($argv[2],'x'); };"
            "echo json_encode(tp_sms_queue_process_one($s,$sender));",
            json.dumps(self.settings(valid_credentials=False)),
            str(called),
        )
        self.assertEqual(json.loads(result.stdout), {"job_id": job["job_id"], "status": "failed"})
        self.assertFalse(called.exists())

    def test_path_validation_rejects_relative_webroot_and_symlink(self) -> None:
        for path in ("relative.sqlite", str(ROOT / "queue.sqlite")):
            settings = self.settings()
            settings["queue"]["database_path"] = path
            result = self.php(
                "$s=json_decode($argv[1],true); tp_sms_queue_enqueue($s,'+49123','Text');",
                json.dumps(settings),
                check=False,
            )
            self.assertNotEqual(result.returncode, 0)

        target = self.directory / "target"
        target.write_bytes(b"")
        self.database.symlink_to(target)
        result = self.php(
            "$s=json_decode($argv[1],true); tp_sms_queue_enqueue($s,'+49123','Text');",
            json.dumps(self.settings()),
            check=False,
        )
        self.assertNotEqual(result.returncode, 0)

        protected = self.directory / "protected"
        protected.mkdir(mode=0o700)
        os.chmod(protected, 0o500)
        try:
            settings = self.settings()
            settings["queue"]["database_path"] = str(protected / "queue.sqlite")
            result = self.php(
                "$s=json_decode($argv[1],true); tp_sms_queue_enqueue($s,'+49123','Text');",
                json.dumps(settings),
                check=False,
            )
            self.assertNotEqual(result.returncode, 0)
            self.assertFalse((protected / "queue.sqlite").exists())
        finally:
            os.chmod(protected, 0o700)

    def test_preexisting_oversized_fritz_job_fails_without_calling_sender(self) -> None:
        job = self.enqueue("9" * 32, provider="fritz", message="Kurze SMS")
        with sqlite3.connect(self.database) as connection:
            connection.execute("UPDATE sms_queue SET message=? WHERE id=?", ("ö" * 71, job["job_id"]))
        result = self.php(
            "$s=json_decode($argv[1],true); "
            "echo json_encode(tp_sms_queue_process_one($s,static function () { exit(17); }));",
            json.dumps(self.settings("fritz")),
        )
        self.assertEqual(json.loads(result.stdout)["status"], "failed")

    def test_database_under_document_root_is_rejected_even_outside_library(self) -> None:
        result = self.php(
            "$_SERVER['DOCUMENT_ROOT']=$argv[2]; $s=json_decode($argv[1],true); "
            "try { tp_sms_queue_enqueue($s,'+49123','Text'); } "
            "catch(Throwable $e) { fwrite(STDERR,$e->getMessage()); exit(7); }",
            json.dumps(self.settings()), str(self.directory), check=False,
        )
        self.assertEqual(result.returncode, 7)
        self.assertIn("Webroot", result.stderr)
        self.assertFalse(self.database.exists())

    def test_request_id_with_trailing_newline_is_rejected(self) -> None:
        result = self.php(
            "$s=json_decode($argv[1],true); "
            "try { tp_sms_queue_enqueue($s,'+49123','Text',['request_id'=>str_repeat('a',32).\"\\n\"]); } "
            "catch(Throwable $e) { exit(7); }",
            json.dumps(self.settings()), check=False,
        )
        self.assertEqual(result.returncode, 7)
        self.assertFalse(self.database.exists())

    def test_cli_help_init_status_and_once_are_private(self) -> None:
        help_result = subprocess.run(
            ["php", str(WORKER), "--help"], cwd=ROOT, text=True, capture_output=True, timeout=5
        )
        self.assertEqual(help_result.returncode, 0)
        self.assertIn("--config", help_result.stdout)

        config = self.directory / "sms-config.json"
        config.write_text(json.dumps(self.settings(valid_credentials=False)))
        os.chmod(config, 0o600)
        init = subprocess.run(
            ["php", str(WORKER), "--config", str(config), "--init"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=5,
        )
        self.assertEqual(init.returncode, 0, init.stderr)
        status = subprocess.run(
            ["php", str(WORKER), "--config", str(config), "--status"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=5,
        )
        self.assertEqual(status.returncode, 0, status.stderr)
        self.assertEqual(status.stdout.splitlines(), [
            "pending: 0", "sending: 0", "accepted: 0", "failed: 0", "uncertain: 0",
            "cleanup_pending: 0",
        ])
        empty = subprocess.run(
            ["php", str(WORKER), "--config", str(config), "--once"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=5,
        )
        self.assertEqual(empty.returncode, 0, empty.stderr)

        private_recipient = "+499991234"
        private_message = "Geheime Nachricht"
        self.enqueue("8" * 32, recipient=private_recipient, message=private_message)
        failed = subprocess.run(
            ["php", str(WORKER), "--config", str(config), "--once"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            timeout=5,
        )
        self.assertNotEqual(failed.returncode, 0)
        combined = failed.stdout + failed.stderr
        self.assertNotIn(private_recipient, combined)
        self.assertNotIn(private_message, combined)


if __name__ == "__main__":
    unittest.main()

"""Receive transport/integration tests: synthetic loopback router, never real SMS."""

from contextlib import closing, contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import hashlib
import json
import os
from pathlib import Path
import sqlite3
import subprocess
import tempfile
import threading
import unittest
from urllib.parse import parse_qs, urlsplit

from test_telepraxis_sms_journal import envelope, received, sent


ROOT = Path(__file__).resolve().parents[1]
SERIAL = "synthetic-device-a"
DEVICE = hashlib.sha256(("fritz-serial:" + SERIAL).encode()).hexdigest()
PRIVATE = "Private synthetic router detail"
LOGIN_SID = "1111111111111111"
JOURNAL_SID = "2222222222222222"
# Exact public test text used for the live 405-character comparison; identities stay synthetic.
LONG_MESSAGE = (
    "START TEST 1. Dies ist eine lange Testnachricht ohne Patientendaten. Der gesamte Text soll "
    "unveraendert als ein Vorgang ankommen. TEST 2. Dieser mittlere Abschnitt dient der Pruefung "
    "mehrerer SMS-Teile. Kein Abschnitt darf fehlen oder doppelt erscheinen. TEST 3. Auch nach "
    "einem Neustart darf nur eine automatische Antwortliste entstehen. Die Reihenfolge aller "
    "Abschnitte muss erhalten bleiben. ENDE TEST."
)


@contextmanager
def receiver_router(rows, *, serials=None, journal=None, delete_ok=True):
    state = {"rows": list(rows), "requests": [], "violations": [], "identities": 0, "deletes": []}

    class Handler(BaseHTTPRequestHandler):
        def log_message(self, *_args):
            pass

        def do_GET(self):
            self.respond()

        def do_POST(self):
            self.respond()

        def respond(self):
            url = urlsplit(self.path)
            raw = self.rfile.read(int(self.headers.get("Content-Length", "0"))).decode()
            fields = parse_qs(raw if self.command == "POST" else url.query, keep_blank_values=True)
            state["requests"].append((self.command, url.path, fields))
            if url.path == "/jason_boxinfo.xml":
                available = serials or [SERIAL]
                serial = available[min(state["identities"], len(available) - 1)]
                state["identities"] += 1
                body = f'<j:BoxInfo xmlns:j="urn:synthetic"><j:Serial>{serial}</j:Serial></j:BoxInfo>'
            elif url.path == "/login_sid.lua":
                body = f"<SessionInfo><Challenge>test-challenge</Challenge><SID>{LOGIN_SID}</SID></SessionInfo>"
            elif url.path == "/data.lua":
                if "session_cookie=present" not in self.headers.get("Cookie", ""):
                    state["violations"].append("missing cookie")
                if "delete" in fields:
                    if set(fields) != {"sid", "page", "messageId", "delete"} or fields.get("sid") != [JOURNAL_SID]:
                        state["violations"].append("invalid delete fields or stale SID")
                    uid = fields.get("messageId", [""])[0]
                    state["deletes"].append(uid)
                    if delete_ok:
                        state["rows"] = [row for row in state["rows"] if str(row.get("uid")) != uid]
                    body = {"sid": JOURNAL_SID, "data": {"delete": "ok" if delete_ok else "failed", "detail": PRIVATE}}
                else:
                    if fields != {"sid": [LOGIN_SID], "page": ["smsList"], "xhr": ["1"]}:
                        state["violations"].append("unexpected action")
                    body = journal if journal is not None else {**envelope(state["rows"]), "sid": JOURNAL_SID}
            else:
                state["violations"].append("unexpected endpoint")
                body = {}
            self.send_response(200)
            self.send_header("Set-Cookie", "session_cookie=present; Path=/")
            self.end_headers()
            self.wfile.write((body if isinstance(body, str) else json.dumps(body)).encode())

    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=lambda: server.serve_forever(poll_interval=0.01), daemon=True)
    thread.start()
    try:
        yield f"http://127.0.0.1:{server.server_port}", state
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2)


class SmsReceiveTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="tp-sms-receive-test-")
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name).resolve()
        self.inbox = self.directory / "inbox"
        self.inbox.mkdir(mode=0o770)
        self.database = self.directory / "queue.sqlite"

    def settings(self, host):
        return {
            "receive": {"enabled": True, "format": "fritz-sms-list-assembled", "output_mode": "target-local",
                        "inbox_path": str(self.inbox)},
            "queue": {"database_path": str(self.database), "delivery_provider": "seven"},
            "auto_reply": {"messages": ["Antwort 1", "Antwort 2"]},
            "fritzbox": {"host": host, "username": "test-user", "password": "test-password", "timeout_seconds": 1},
            "seven": {"api_key": "synthetic-key"},
        }

    def php(self, code, settings, **extra):
        runner = """
define('TELEPRAXIS_SMS_CONFIG',true);
require 'telepraxis-sms-receive.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$s=tp_sms_merge_settings(tp_sms_default_settings(),$input['settings']);
try {
""" + code + """
} catch(Throwable $e) {echo json_encode(['error'=>$e->getMessage()]);}
"""
        result = subprocess.run(
            ["php", "-d", "error_reporting=24575", "-d", "display_errors=stderr", "-r", runner],
            cwd=ROOT, input=json.dumps({"settings": settings, **extra}), text=True, capture_output=True, timeout=20,
            env={**os.environ, "TMPDIR": str(self.directory), "NO_PROXY": "127.0.0.1", "no_proxy": "127.0.0.1"},
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        self.assertEqual(list(self.directory.glob("tp-sms-receive-*")), [])
        return json.loads(result.stdout)

    def test_snapshot_reads_identity_and_preserves_full_long_message(self):
        text = "  Ein langer Text ä😀 " * 30
        with receiver_router([received(text=text), sent()]) as (host, state):
            result = self.php("echo json_encode(tp_sms_receive_read_snapshot($s));", self.settings(host))
        self.assertEqual(result["device_key"], DEVICE)
        self.assertEqual(result["entries"][0]["text"], text)
        self.assertEqual(state["identities"], 2)
        self.assertEqual(state["deletes"], [])
        self.assertEqual(state["violations"], [])
        self.assertEqual(state["requests"][-1][2], {"sid": [JOURNAL_SID], "logout": ["1"]})

    def delete(self, settings, row=None, device=DEVICE):
        return self.php("""
$entry=tp_sms_journal_parse(['data'=>['smsListData'=>['messages'=>[$input['row']]]]])[0];
echo json_encode(tp_sms_receive_delete_checked($s,$input['device'],$entry['uid'],tp_sms_receive_fingerprint($entry)));
""", settings, row=row or received(), device=device)

    def test_checked_delete_requires_same_device_and_fingerprint(self):
        with receiver_router([received()]) as (host, state):
            result = self.delete(self.settings(host))
        self.assertTrue(result["deleted"])
        self.assertEqual(state["deletes"], ["101"])
        self.assertEqual(state["violations"], [])

    def test_absent_or_reused_uid_does_not_delete_another_message(self):
        for rows in ([], [received(text="A different incoming message")]):
            with self.subTest(rows=rows), receiver_router(rows) as (host, state):
                result = self.delete(self.settings(host))
                self.assertTrue(result["absent"])
                self.assertEqual(state["deletes"], [])

    def test_ambiguous_uid_wrong_device_and_device_change_never_delete(self):
        cases = [([received(), received(text="Different")], None),
                 ([received()], ["replacement-device"]),
                 ([received()], [SERIAL, "replacement-device"])]
        for rows, serials in cases:
            with self.subTest(serials=serials), receiver_router(rows, serials=serials) as (host, state):
                result = self.delete(self.settings(host))
                self.assertFalse(result.get("deleted", False))
                self.assertEqual(state["deletes"], [])

    def test_bad_journal_and_delete_error_do_not_leak_provider_details(self):
        for kwargs in ({"journal": PRIVATE}, {"journal": {"data": {"error": PRIVATE}}}, {"delete_ok": False}):
            with self.subTest(kwargs=kwargs), receiver_router([received()], **kwargs) as (host, state):
                result = self.delete(self.settings(host))
                self.assertEqual(result, {"error": "SMS-Journalzugriff fehlgeschlagen."})
                self.assertNotIn(PRIVATE, json.dumps(result))
                self.assertEqual(state["violations"], [])

    def test_unidentified_uid_and_unverified_remote_status_prevent_deletion(self):
        for rows, target in [([received(), received(uid=None)], received()),
                             ([sent(status=1, status_name="sending")], sent(status=1, status_name="sending"))]:
            with self.subTest(rows=rows), receiver_router(rows) as (host, state):
                result = self.delete(self.settings(host), row=target)
                self.assertFalse(result.get("deleted", False))
                self.assertFalse(result.get("absent", False))
                self.assertEqual(state["deletes"], [])

    def test_existing_worker_lock_prevents_receive_router_access(self):
        with receiver_router([received()]) as (host, state):
            result = self.php("""
$lock=tp_sms_queue_worker_lock($s);
echo json_encode(tp_sms_receive_process_cycle($s));
flock($lock['handle'],LOCK_UN); fclose($lock['handle']);
""", self.settings(host))
            self.assertIsNone(result)
            self.assertEqual(state["requests"], [])

    def test_cycle_persists_exports_enqueues_once_and_cleans_after_backup(self):
        text = LONG_MESSAGE
        self.assertEqual(len(text), 405)
        with receiver_router([received(text=text)]) as (host, state):
            settings = self.settings(host)
            first = self.php("echo json_encode(tp_sms_receive_process_cycle($s));", settings)
            self.assertTrue(first["ok"], first)
            self.assertEqual(first["stored"], 1)
            self.assertEqual(state["deletes"], ["101"])
            files = list(self.inbox.glob("sms-*.json"))
            self.assertEqual(len(files), 1)
            record = json.loads(files[0].read_text())
            self.assertEqual(record["payload"]["anliegen"], text)
            self.assertEqual(record["typ"], "sonstiges")
            self.assertEqual(record["payload"]["telefon"], "+491700000000")
            second = self.php("echo json_encode(tp_sms_receive_process_cycle($s));", settings)
            self.assertTrue(second["ok"], second)
            with closing(sqlite3.connect(self.database)) as db:
                self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 2)
                self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_receive_journal").fetchone()[0], 1)
            self.assertEqual(len(list(self.inbox.glob("sms-*.json"))), 1)
            self.assertEqual(state["violations"], [])

    def test_invalid_output_or_changed_device_stops_cleanup(self):
        with receiver_router([received()]) as (host, state):
            settings = self.settings(host)
            settings["receive"]["inbox_path"] = str(self.directory / "missing")
            result = self.php("echo json_encode(tp_sms_receive_process_cycle($s));", settings)
            self.assertFalse(result.get("ok", False))
            self.assertEqual(state["deletes"], [])

    def test_disabled_receiver_and_unconfirmed_format_do_not_contact_router(self):
        with receiver_router([received()]) as (host, state):
            settings = self.settings(host)
            settings["receive"]["enabled"] = False
            self.assertIsNone(self.php("echo json_encode(tp_sms_receive_process_cycle($s));", settings))
            settings["receive"]["enabled"] = True
            settings["receive"]["format"] = "unknown"
            result = self.php("echo json_encode(tp_sms_receive_process_cycle($s));", settings)
            self.assertIn("error", result)
            self.assertEqual(state["requests"], [])

    def worker(self, action, settings):
        config = self.directory / "worker.json"
        config.write_text(json.dumps(settings))
        return subprocess.run(
            ["php", str(ROOT / "telepraxis-sms-worker.php"), "--config", str(config), action],
            text=True, capture_output=True, timeout=20,
            env={**os.environ, "TMPDIR": str(self.directory), "NO_PROXY": "127.0.0.1", "no_proxy": "127.0.0.1"},
        )

    def test_worker_init_and_status_never_contact_router(self):
        with receiver_router([received()]) as (host, state):
            settings = self.settings(host)
            for action in ("--status", "--init", "--status"):
                result = self.worker(action, settings)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stderr, "")
            self.assertIn("journal_unclassified: 0", result.stdout)
            self.assertIn("journal_cleanup_failed: 0", result.stdout)
            self.assertEqual(state["requests"], [])

    def test_worker_router_failure_keeps_outgoing_job_pending(self):
        with receiver_router([], journal={"error": PRIVATE}) as (host, state):
            settings = self.settings(host)
            job = self.php("""
echo json_encode(tp_sms_queue_enqueue($s,'+491700000000','Test',[
    'request_id'=>hash('sha256','offline'), 'source_file'=>'test.json', 'workplace'=>'Test'
]));
""", settings)
            self.assertTrue(job["queued"])
            result = self.worker("--once", settings)
            self.assertEqual(result.returncode, 1)
            self.assertNotIn(PRIVATE, result.stdout + result.stderr)
            self.assertEqual(state["deletes"], [])
            with closing(sqlite3.connect(self.database)) as db:
                self.assertEqual(db.execute("SELECT status FROM sms_queue").fetchone()[0], "pending")

    def test_worker_unclassified_row_is_visible_without_export_or_reply(self):
        with receiver_router([received(uid=None)]) as (host, state):
            settings = self.settings(host)
            result = self.worker("--once", settings)
            self.assertEqual(result.returncode, 1, result.stdout + result.stderr)
            status = self.worker("--status", settings)
            self.assertIn("journal_unclassified: 1", status.stdout)
            self.assertEqual(state["deletes"], [])
            self.assertEqual(list(self.inbox.glob("sms-*.json")), [])
            with closing(sqlite3.connect(self.database)) as db:
                self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 0)

    def test_archived_input_is_exported_once_even_while_router_is_offline(self):
        settings = self.settings("http://127.0.0.1:1")
        result = self.php("""
$entries=tp_sms_journal_parse(['data'=>['smsListData'=>['messages'=>[$input['row']]]]]);
tp_sms_receive_store($s,$input['device'],$entries);
$reader=static function() {throw new RuntimeException('offline');};
$deleter=static function() {throw new RuntimeException('must not be called');};
echo json_encode([tp_sms_receive_process_cycle($s,$reader,$deleter),tp_sms_receive_process_cycle($s,$reader,$deleter)]);
""", settings, row=received(), device=DEVICE)
        self.assertFalse(result[0]["ok"])
        self.assertEqual(result[0]["failure_code"], "journal_sync_failed")
        self.assertEqual(result[0]["export"]["state"], "exported")
        self.assertEqual(result[0]["reply"]["reply_status"], "queued")
        self.assertIsNone(result[0]["cleanup"])
        self.assertIsNone(result[1]["export"])
        self.assertIsNone(result[1]["reply"])
        with closing(sqlite3.connect(self.database)) as db:
            self.assertEqual(db.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0], 2)


if __name__ == "__main__":
    unittest.main(verbosity=2)

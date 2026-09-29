"""FRITZ!Box HTTP protocol tests; no real router, credentials or SMS required.

Run directly with: python3 tests/test_telepraxis_sms.py
"""

from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs, urlsplit


SOURCE = Path(__file__).resolve().parents[1] / "telepraxis-sms.php"
PHP = shutil.which("php")
RECIPIENT = "+491700000000"
MESSAGE = "Nur lokale Testnachricht"
SECRET = "JBSWY3DPEHPK3PXP"
UID = "test-message-uid"


class FritzStub:
    def __init__(self, replies: dict | None = None):
        self.replies = replies or {}
        self.requests: list[tuple[str, dict]] = []
        self.violations: list[str] = []
        self.sid = ""
        self.polls = 0
        self.deletes = 0

    def respond(self, method: str, path: str, fields: dict, cookie: str):
        if path == "/login_sid.lua":
            stage = "logout" if "logout" in fields else "login" if method == "POST" else "challenge"
        elif path == "/twofactor.lua":
            stage = next(
                (name for key, name in [("tfa_googleauth_info", "info"),
                                       ("tfa_googleauth", "code"), ("tfa_active", "poll")]
                 if key in fields), "unknown")
        else:
            stage = next(
                (name for key, name in [("apply", "initial"), ("confirmed", "final"),
                                       ("second_apply", "second"), ("delete", "delete")]
                 if key in fields), "unknown")
        self.requests.append((stage, fields))
        if stage not in ("challenge", "login"):
            if fields.get("sid") != [self.sid]:
                self.violations.append(f"{stage}: outdated SID")
            if "test_session=present" not in cookie:
                self.violations.append(f"{stage}: missing session cookie")
        if stage in ("initial", "second", "final"):
            if fields.get("recipient") != [RECIPIENT] or fields.get("newMessage") != [MESSAGE]:
                self.violations.append(f"{stage}: changed SMS")
        if stage in ("second", "final") and fields.get("new_uid") != [UID]:
            self.violations.append(f"{stage}: changed UID")
        if stage == "final":
            for key in ("second_apply", "confirmed", "twofactor"):
                if fields.get(key) != [""]:
                    self.violations.append(f"final: incorrect {key}")
        if stage == "delete" and fields.get("messageId") != [UID]:
            self.violations.append("delete: changed UID")
        if stage == "code":
            code = fields["tfa_googleauth"][0]
            if len(code) != 6 or not code.isdigit():
                self.violations.append("code: invalid TOTP format")

        next_sid = f"{len(self.requests):016x}"
        if stage in ("challenge", "login", "logout"):
            self.sid = next_sid
            body = f"<SessionInfo><Challenge>test-challenge</Challenge><SID>{self.sid}</SID></SessionInfo>"
            return 200, body, "text/xml"
        defaults = {
            "initial": {"data": {"new_uid": UID}},
            "second": {"data": {"second_apply": "twofactor", "twofactor": "googleauth;123"}},
            "info": {"googleauth": {"isConfigured": True, "isAvailable": True}},
            "code": {"err": 0},
            "poll": {"done": True, "active": True},
            "final": {"data": {"second_apply": "ok"}},
            "delete": {"data": {"delete": "ok"}},
        }
        reply = self.replies.get(stage, defaults.get(stage, {}))
        if stage == "poll":
            if isinstance(reply, list):
                reply = reply[min(self.polls, len(reply) - 1)]
            self.polls += 1
        if stage == "delete":
            if isinstance(reply, list):
                reply = reply[min(self.deletes, len(reply) - 1)]
            self.deletes += 1
        if isinstance(reply, tuple):
            return reply[0], reply[1], "application/json"
        self.sid = next_sid
        return 200, json.dumps({**reply, "sid": self.sid}), "application/json"


@unittest.skipUnless(PHP, "PHP CLI required")
class FritzSmsTests(unittest.TestCase):
    def run_sms(self, replies: dict | None = None, *, delete: bool = True,
                operation: str = "send", callback: str = ""):
        stub = FritzStub(replies)

        class Handler(BaseHTTPRequestHandler):
            def log_message(self, *_args):
                pass

            def do_GET(self):
                self.handle_request()

            def do_POST(self):
                self.handle_request()

            def handle_request(self):
                url = urlsplit(self.path)
                raw = self.rfile.read(int(self.headers.get("Content-Length", "0"))).decode()
                fields = parse_qs(raw if self.command == "POST" else url.query, keep_blank_values=True)
                status, body, content_type = stub.respond(
                    self.command, url.path, fields, self.headers.get("Cookie", ""))
                self.send_response(status)
                self.send_header("Content-Type", content_type)
                self.send_header("Set-Cookie", "test_session=present; Path=/")
                self.end_headers()
                self.wfile.write(body.encode())

        server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
        thread = threading.Thread(target=lambda: server.serve_forever(poll_interval=0.01), daemon=True)
        thread.start()
        try:
            with tempfile.TemporaryDirectory() as tmpdir:
                settings = {"fritzbox": {
                    "host": f"http://127.0.0.1:{server.server_port}",
                    "username": "test-user", "password": "dummy-password",
                    "totp_secret": SECRET, "delete_after_send": delete, "timeout_seconds": 1,
                }}
                if operation == "queue":
                    settings["queue"] = {"database_path": str(Path(tmpdir).resolve() / "queue.sqlite"),
                                         "delivery_provider": "fritz"}
                result = subprocess.run(
                    [PHP, "-d", "error_reporting=24575", "-d", "display_errors=stderr", "-r", """
define('TELEPRAXIS_APP', true);
require $argv[1];
$input = json_decode(stream_get_contents(STDIN), true);
$created = [];
$callback = empty($input['callback']) ? null : static function (string $uid) use (&$created, $input): void {
    $created[] = $uid;
    if ($input['callback'] === 'reject') {
        throw new RuntimeException('Lokale Speicherung fehlgeschlagen.');
    }
};
try {
    if ($input['operation'] === 'queue') {
        require dirname($argv[1]) . '/telepraxis-sms-queue.php';
        $settings = $input['settings'];
        tp_sms_queue_enqueue($settings, $input['recipient'], $input['message']);
        $sent = tp_sms_queue_process_one($settings);
        $first = tp_sms_queue_process_cleanup_one($settings);
        $pdo = tp_sms_queue_open($settings, true);
        $pdo->exec("UPDATE sms_queue_cleanup SET next_attempt_at='1970-01-01T00:00:00.000000Z'");
        $again = tp_sms_queue_process_one($settings);
        $second = tp_sms_queue_process_cleanup_one($settings);
        $result = ['ok'=>true, 'sent'=>$sent, 'first'=>$first, 'again'=>$again, 'second'=>$second,
                   'cleanup_pending'=>tp_sms_queue_cleanup_pending_count($settings)];
    } elseif ($input['operation'] === 'delete') {
        $result = ['ok' => true, 'details' => tp_sms_fritz_delete_message($input['settings'], $input['uid'])];
    } else {
        $result = tp_sms_dispatch($input['settings'], 'fritz', $input['recipient'], $input['message'],
            ['on_fritz_message_created' => $callback]);
    }
} catch (Throwable $error) {
    $result = ['ok' => false, 'error' => $error->getMessage()];
}
echo json_encode($result + ['created_uids' => $created]);
""", str(SOURCE)],
                    input=json.dumps({"settings": settings, "recipient": RECIPIENT, "message": MESSAGE,
                                      "operation": operation, "callback": callback, "uid": UID}),
                    text=True, capture_output=True, timeout=8,
                    env={**os.environ, "TMPDIR": tmpdir, "NO_PROXY": "127.0.0.1", "no_proxy": "127.0.0.1"},
                )
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(result.stderr, "")
                self.assertEqual(list(Path(tmpdir).glob("tp-sms-fritz-*")), [])
                output = json.loads(result.stdout)
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=2)
        self.assertEqual(stub.violations, [])
        self.assertEqual(stub.requests[-1][0], "logout")
        if not output["ok"]:
            for private in (RECIPIENT, MESSAGE, SECRET, UID, "dummy-password"):
                self.assertNotIn(private, output["error"])
            for _, fields in stub.requests:
                for sid in fields.get("sid", []):
                    self.assertNotIn(sid, output["error"])
        return output, [stage for stage, _ in stub.requests]

    def test_waits_for_release_then_sends_once_and_deletes(self):
        result, stages = self.run_sms({"poll": [
            {"done": False, "active": True}, {"done": "1", "active": "true"},
        ]})
        self.assertTrue(result["ok"])
        self.assertTrue(result["details"]["twofactor"])
        self.assertEqual(stages, ["challenge", "login", "initial", "second", "info", "code",
                                  "poll", "poll", "final", "delete", "logout"])

    def test_success_without_deletion(self):
        result, stages = self.run_sms(delete=False)
        self.assertTrue(result["ok"])
        self.assertEqual(stages.count("final"), 1)
        self.assertNotIn("delete", stages)

    def test_created_id_is_reported_before_final_send_and_survives_failure(self):
        result, stages = self.run_sms({"final": {"data": {"second_apply": "failed"}}},
                                      delete=False, callback="record")
        self.assertFalse(result["ok"])
        self.assertEqual(result["created_uids"], [UID])
        self.assertEqual(stages.count("final"), 1)

    def test_failed_id_persistence_stops_before_second_apply(self):
        result, stages = self.run_sms(delete=False, callback="reject")
        self.assertFalse(result["ok"])
        self.assertEqual(result["created_uids"], [UID])
        self.assertEqual(stages, ["challenge", "login", "initial", "logout"])

    def test_missing_id_does_not_call_persistence_hook(self):
        result, stages = self.run_sms({"initial": {"data": {}}}, callback="record")
        self.assertEqual(result["created_uids"], [])
        self.assertEqual(stages, ["challenge", "login", "initial", "logout"])

    def test_separate_delete_session_never_sends(self):
        result, stages = self.run_sms(operation="delete")
        self.assertTrue(result["ok"])
        self.assertTrue(result["details"]["deleted"])
        self.assertEqual(stages, ["challenge", "login", "delete", "logout"])

    def test_separate_delete_failure_remains_failure_and_closes_session(self):
        result, stages = self.run_sms({"delete": {"data": {"delete": "error"}}}, operation="delete")
        self.assertFalse(result["ok"])
        self.assertIn("nicht geloescht", result["error"])
        self.assertEqual(stages, ["challenge", "login", "delete", "logout"])

    def test_queue_real_transport_then_cleanup_and_retry_never_resends(self):
        result, stages = self.run_sms({"delete": [
            {"data": {"delete": "error"}}, {"data": {"delete": "ok"}},
        ]}, operation="queue")
        self.assertTrue(result["ok"])
        self.assertEqual(result["sent"]["status"], "accepted")
        self.assertEqual(result["first"]["status"], "pending")
        self.assertIsNone(result["again"])
        self.assertEqual(result["second"]["status"], "done")
        self.assertEqual(result["cleanup_pending"], 0)
        self.assertEqual(stages.count("initial"), 1)
        self.assertEqual(stages.count("final"), 1)
        self.assertEqual(stages.count("delete"), 2)
        self.assertEqual(stages.count("login"), 3)

    def test_queue_cleans_known_id_after_uncertain_send(self):
        result, stages = self.run_sms({"final": {"data": {"second_apply": "failed"}}}, operation="queue")
        self.assertTrue(result["ok"])
        self.assertEqual(result["sent"]["status"], "uncertain")
        self.assertEqual(result["first"]["status"], "done")
        self.assertIsNone(result["again"])
        self.assertIsNone(result["second"])
        self.assertEqual(stages.count("final"), 1)
        self.assertEqual(stages.count("delete"), 1)

    def test_unconfirmed_final_does_not_retry_or_delete(self):
        result, stages = self.run_sms({"final": {"data": {
            "second_apply": "twofactor", "twofactor": "googleauth;123",
            "recipient": RECIPIENT, "newMessage": MESSAGE, "new_uid": UID,
        }}})
        self.assertFalse(result["ok"])
        self.assertIn("nicht bestaetigt", result["error"])
        self.assertEqual(stages.count("final"), 1)
        self.assertEqual(stages.count("code"), 1)
        self.assertNotIn("delete", stages)

    def test_direct_second_apply_success_without_totp(self):
        for delete in (False, True):
            with self.subTest(delete=delete):
                result, stages = self.run_sms({"second": {"data": {"second_apply": "ok"}}}, delete=delete)
                self.assertTrue(result["ok"])
                self.assertFalse(result["details"]["twofactor"])
                self.assertNotIn("code", stages)
                self.assertNotIn("final", stages)
                self.assertEqual("delete" in stages, delete)

    def test_lockout_at_each_send_stage(self):
        for stage in ("initial", "second", "final"):
            with self.subTest(stage=stage):
                result, stages = self.run_sms({stage: {"data": {
                    "second_apply": "twofactor", "twofactor": "starterror;92",
                    "recipient": RECIPIENT, "newMessage": MESSAGE,
                }}})
                self.assertFalse(result["ok"])
                self.assertIn("60 Minuten", result["error"])
                self.assertIn("Fehler 92", result["error"])
                self.assertNotIn("delete", stages)
                self.assertEqual(stages[-2], stage)
                self.assertEqual(stages.count("code"), int(stage == "final"))
                self.assertEqual(stages.count("final"), int(stage == "final"))

    def test_busy_and_unknown_start_error_stop_before_code(self):
        for state, expected in [("starterror;91", "zwei Minuten"), ("starterror;99", "nicht starten")]:
            with self.subTest(state=state):
                result, stages = self.run_sms({"second": {"data": {
                    "second_apply": "twofactor", "twofactor": state,
                }}})
                self.assertFalse(result["ok"])
                self.assertIn(expected, result["error"])
                self.assertEqual(stages[-2], "second")

    def test_rejected_or_invalid_totp_result_does_not_send(self):
        for code_reply in ({"err": 1}, {}, {"err": "invalid"}, {"err": False}):
            with self.subTest(reply=code_reply):
                result, stages = self.run_sms({"code": code_reply})
                self.assertFalse(result["ok"])
                self.assertIn("nicht akzeptiert", result["error"])
                self.assertEqual(stages[-2], "code")

    def test_failed_or_malformed_poll_never_sends(self):
        for reply, expected in [
            ({"done": True, "active": False}, "fehlgeschlagen"),
            ({"done": True}, "gueltigen"),
            ({"active": True}, "gueltigen"),
            ({"done": "maybe", "active": True}, "gueltigen"),
            ({"done": True, "active": []}, "gueltigen"),
            ((500, "failure"), "HTTP 500"),
            ((200, "not json"), "JSON"),
            ({"done": False, "active": True}, "nicht rechtzeitig"),
        ]:
            with self.subTest(reply=reply):
                result, stages = self.run_sms({"poll": reply})
                self.assertFalse(result["ok"])
                self.assertIn(expected, result["error"])
                self.assertEqual(stages[-2], "poll")
                self.assertNotIn("final", stages)
                self.assertNotIn("delete", stages)
                self.assertEqual(stages.count("code"), 1)


if __name__ == "__main__":
    unittest.main(verbosity=2)

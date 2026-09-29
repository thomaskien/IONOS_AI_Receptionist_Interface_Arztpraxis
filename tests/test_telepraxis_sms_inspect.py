"""Read-only journal diagnosis against a synthetic loopback router only."""

from __future__ import annotations

from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import subprocess
import tempfile
import threading
import unittest
from urllib.parse import parse_qs, urlsplit


ROOT = Path(__file__).resolve().parents[1]
INSPECT = ROOT / "telepraxis-sms-inspect.php"
PRIVATE = "Vertrauliche synthetische Testdaten"
PHONE = "+491700000000"
SID_LOGIN = "1111111111111111"
SID_JOURNAL = "2222222222222222"


@contextmanager
def router(body, status=200, *, invalid_login=False):
    requests = []

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
            requests.append((self.command, url.path, fields, self.headers.get("Cookie", "")))
            if url.path == "/login_sid.lua":
                payload = (PRIVATE if invalid_login else
                           f"<SessionInfo><Challenge>test-challenge</Challenge><SID>{SID_LOGIN}</SID></SessionInfo>")
                self.send_response(200)
            else:
                payload = body if isinstance(body, str) else json.dumps(body)
                self.send_response(status)
            self.send_header("Set-Cookie", "probe_cookie=present; Path=/")
            self.end_headers()
            self.wfile.write(payload.encode())

    server = ThreadingHTTPServer(("127.0.0.1", 0), Handler)
    thread = threading.Thread(target=lambda: server.serve_forever(poll_interval=0.01), daemon=True)
    thread.start()
    try:
        yield f"http://127.0.0.1:{server.server_port}", requests
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2)


class SmsInspectTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="tp-sms-inspect-test-")
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name).resolve()
        self.config = self.directory / "config.json"

    def cli(self, *arguments):
        return subprocess.run(
            ["php", "-d", "display_errors=1", str(INSPECT), *arguments],
            text=True, capture_output=True, timeout=10,
            env={**os.environ, "TMPDIR": str(self.directory), "NO_PROXY": "127.0.0.1", "no_proxy": "127.0.0.1"},
        )

    def configure(self, host):
        self.config.write_text(json.dumps({
            "fritzbox": {"host": host, "username": "synthetic-user", "password": "synthetic-password", "timeout_seconds": 1},
            "default_provider": "queue",
            "queue": {"database_path": str(self.directory / "must-not-exist.sqlite")},
        }))

    def assert_private_absent(self, result):
        for value in (PRIVATE, PHONE, SID_LOGIN, SID_JOURNAL, "synthetic-user", "synthetic-password", "probe_cookie"):
            self.assertNotIn(value, result.stdout + result.stderr)
        self.assertEqual(list(self.directory.glob("tp-sms-inspect-*")), [])
        self.assertFalse((self.directory / "must-not-exist.sqlite").exists())

    def test_reads_once_reuses_cookie_updates_sid_and_logs_out_without_actions(self):
        body = {"sid": SID_JOURNAL, "data": {"messages": [
            {"id": "private-id", "sender": PHONE, "text": PRIVATE, "groupId": "group-a", "part": 1, "total": 2},
            {"id": "private-id-2", "sender": PHONE, "text": "B" * 180, "groupId": "group-a", "part": 2, "total": 2},
        ]}}
        with router(body) as (host, requests):
            self.configure(host)
            before = self.config.read_bytes()
            result = self.cli("--config", str(self.config))
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(result.stderr, "")
        self.assertEqual(self.config.read_bytes(), before)
        self.assertEqual([(method, path) for method, path, _, _ in requests], [
            ("GET", "/login_sid.lua"), ("POST", "/login_sid.lua"),
            ("POST", "/data.lua"), ("GET", "/login_sid.lua"),
        ])
        self.assertEqual(requests[2][2], {"sid": [SID_LOGIN], "page": ["smsList"], "xhr": ["1"]})
        self.assertIn("probe_cookie=present", requests[2][3])
        self.assertEqual(requests[3][2], {"sid": [SID_JOURNAL], "logout": ["1"]})
        report = json.loads(result.stdout)
        self.assertFalse(report["multipart_verified"])
        items = report["response"]["fields"]["data"]["fields"]["messages"]["items"]
        self.assertEqual(items[0]["fields"]["sender"]["ref"], items[1]["fields"]["sender"]["ref"])
        self.assertEqual(items[0]["fields"]["groupId"]["ref"], items[1]["fields"]["groupId"]["ref"])
        self.assertNotEqual(items[0]["fields"]["part"]["ref"], items[1]["fields"]["part"]["ref"])
        self.assertEqual(items[1]["fields"]["text"]["utf16_units"], 180)
        self.assert_private_absent(result)

    def test_unknown_keys_nested_values_and_authentication_subtrees_are_hidden(self):
        body = {"data": {PHONE: {PRIVATE: PRIVATE}, "error": PRIVATE,
                         "token": {"text": PRIVATE}, "messages": [{"text": "A😀ä", "udh": PRIVATE}]}}
        with router(body) as (host, _requests):
            self.configure(host)
            result = self.cli("--config=" + str(self.config))
        self.assertEqual(result.returncode, 0, result.stderr)
        fields = json.loads(result.stdout)["response"]["fields"]["data"]["fields"]
        self.assertIn("field-1", fields)
        self.assertNotIn("token", fields)
        self.assertEqual(fields["messages"]["items"][0]["fields"]["text"]["utf16_units"], 4)
        self.assert_private_absent(result)

    def test_preserves_empty_object_and_empty_list_distinction(self):
        with router({"data": {"inbox": [], "outbox": {}}}) as (host, _requests):
            self.configure(host)
            result = self.cli("--config", str(self.config))
        self.assertEqual(result.returncode, 0, result.stderr)
        fields = json.loads(result.stdout)["response"]["fields"]["data"]["fields"]
        self.assertEqual(fields["inbox"], {"type": "array", "count": 0, "items": []})
        self.assertEqual(fields["outbox"], {"type": "object", "fields": {}})

    def test_http_errors_malformed_json_and_wrong_page_fail_without_private_details(self):
        for body, status in [(PRIVATE, 500), (PRIVATE, 200), ([], 200),
                             ({"sid": SID_JOURNAL, "error": PRIVATE}, 200), ({"data": PRIVATE}, 200)]:
            with self.subTest(body=body, status=status), router(body, status) as (host, requests):
                self.configure(host)
                result = self.cli("--config", str(self.config))
                self.assertEqual(result.returncode, 1)
                self.assertEqual(result.stdout, "")
                self.assertIn("Routerabruf", result.stderr)
                self.assertIn("logout", requests[-1][2])
                self.assert_private_absent(result)

    def test_login_failure_makes_no_journal_request_and_removes_cookie_file(self):
        with router({}, invalid_login=True) as (host, requests):
            self.configure(host)
            result = self.cli("--config", str(self.config))
        self.assertEqual(result.returncode, 1)
        self.assertEqual(len(requests), 1)
        self.assert_private_absent(result)

    def test_help_and_invalid_arguments_never_contact_router(self):
        with router({}) as (host, requests):
            self.configure(host)
            result = self.cli("--config", str(self.config), "--help")
            self.assertEqual(result.returncode, 0)
            for args in [(), ("--once",), ("--config",), ("--config", "relative.json"),
                         ("--config", str(self.config), "--delete"),
                         ("--config", str(self.config), "--config", str(self.config))]:
                with self.subTest(args=args):
                    result = self.cli(*args)
                    self.assertEqual(result.returncode, 1)
                    self.assertEqual(result.stdout, "")
                    self.assert_private_absent(result)
            self.assertEqual(requests, [])

    def test_config_requires_object_router_section_and_rejects_symlink(self):
        for body in ["{", "[]", "{}", '{"fritzbox": []}']:
            with self.subTest(body=body):
                self.config.write_text(body)
                result = self.cli("--config", str(self.config))
                self.assertEqual(result.returncode, 1)
                self.assertIn("Konfiguration", result.stderr)
                self.assertEqual(result.stdout, "")
        link = self.directory / "config-link.json"
        link.symlink_to(self.config)
        result = self.cli("--config", str(link))
        self.assertEqual(result.returncode, 1)
        self.assertNotIn(str(link), result.stderr)


if __name__ == "__main__":
    unittest.main(verbosity=2)

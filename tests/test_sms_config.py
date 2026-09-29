#!/usr/bin/env python3
"""Integrationstests fuer die regulaere SMS-Konfigurationsoberflaeche."""

from __future__ import annotations

import html
import json
import os
import re
import secrets
import shutil
import sqlite3
import subprocess
import tempfile
import unittest
from pathlib import Path


INTEGRATION_DIR = Path(__file__).resolve().parents[1]
ADMIN_PASSWORD = "integration-test-password"
DEFAULT_REPLIES = [
    "1/2 SMS an die Praxis weitergeleitet. Testbetrieb! Vielen Dank!",
    "2/2 Fehlen persönliche Daten, bitte eine neue vollständige SMS senden.",
]


def function_block(source: str, start: str, end: str) -> str:
    begin = source.index(f"function {start}")
    finish = source.index(f"function {end}", begin)
    return source[begin:finish]


class SmsConfigWebTest(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory(prefix="sms-config-test-")
        self.root = Path(self.temporary.name)
        self.webroot = self.root / "web"
        self.state_dir = self.root / "state"
        self.session_dir = self.root / "sessions"
        self.webroot.mkdir(mode=0o700)
        self.state_dir.mkdir(mode=0o700)
        self.session_dir.mkdir(mode=0o700)
        self.credentials_path = self.state_dir / "credentials.json"
        self.queue_path = self.state_dir / "queue.sqlite"

        for name in ("sms-config.php", "telepraxis-sms-queue.php"):
            shutil.copy2(INTEGRATION_DIR / name, self.webroot / name)

        config_path = self.webroot / "sms-config.php"
        source = config_path.read_text(encoding="utf-8")
        source = source.replace(
            "const TP_SMS_CREDENTIALS_FILE = 'sms-credentials.json';",
            f"const TP_SMS_CREDENTIALS_FILE = {json.dumps(str(self.credentials_path))};",
            1,
        )
        source = source.replace(
            "const TP_SMS_CONFIG_ADMIN_PASSWORD = 'bitte-aendern';",
            f"const TP_SMS_CONFIG_ADMIN_PASSWORD = {json.dumps(ADMIN_PASSWORD)};",
            1,
        )
        config_path.write_text(source, encoding="utf-8")

        self.runner_path = self.root / "request.php"
        self.runner_path.write_text(
            """<?php
session_save_path((string)getenv('TEST_SESSION_DIR'));
session_id((string)getenv('TEST_SESSION_ID'));
$_SERVER = [
    'REQUEST_METHOD' => (string)getenv('TEST_REQUEST_METHOD'),
    'REMOTE_ADDR' => '127.0.0.1',
    'SCRIPT_NAME' => '/sms-config.php',
    'PHP_SELF' => '/sms-config.php',
    'DOCUMENT_ROOT' => (string)getenv('TEST_DOCUMENT_ROOT'),
];
$post = json_decode((string)getenv('TEST_POST'), true);
$_POST = is_array($post) ? $post : [];
require (string)getenv('TEST_CONFIG');
""",
            encoding="utf-8",
        )

        self.initial_settings = {
            "default_provider": "none",
            "sms": {
                "default_to": "+491701111111",
                "default_text": "Bestehender kienzlefon app Testtext",
                "max_text_length": 612,
                "unknown_sms_setting": "keep-sms",
            },
            "auto_reply": {"messages": list(DEFAULT_REPLIES)},
            "queue": {
                "database_path": str(self.queue_path),
                "delivery_provider": "fritz",
                "poll_interval_seconds": 9,
                "busy_timeout_ms": 2345,
                "unknown_queue_setting": "keep-queue",
            },
            "ui": {
                "local_only": False,
                "allowed_client_ips": ["127.0.0.1", "::1"],
                "require_pin": False,
                "admin_pin": "local-pin-secret",
            },
            "seven": {
                "api_key": "seven-api-secret",
                "from": "Praxis",
                "endpoint": "https://gateway.seven.io/api/sms",
                "timeout_seconds": 15,
            },
            "fritzbox": {
                "host": "fritz.test",
                "username": "fritz-user-secret",
                "password": "fritz-password-secret",
                "totp_secret": "JBSWY3DPEHPK3PXP",
                "totp_digits": 6,
                "totp_period": 30,
                "timeout_seconds": 20,
                "verify_tls": False,
                "delete_after_send": True,
            },
            "unknown_top_level": {"must": "survive"},
        }
        self.write_settings(self.initial_settings)

        self.session_id = secrets.token_hex(16)
        self.login()

    def tearDown(self) -> None:
        self.temporary.cleanup()

    def request(
        self, data: dict[str, str] | None = None, session_id: str | None = None
    ) -> str:
        environment = os.environ.copy()
        environment.update(
            {
                "TEST_SESSION_DIR": str(self.session_dir),
                "TEST_SESSION_ID": session_id or self.session_id,
                "TEST_REQUEST_METHOD": "POST" if data is not None else "GET",
                "TEST_DOCUMENT_ROOT": str(self.webroot),
                "TEST_CONFIG": str(self.webroot / "sms-config.php"),
                "TEST_POST": json.dumps(data or {}, ensure_ascii=False),
            }
        )
        completed = subprocess.run(
            ["php", str(self.runner_path)],
            env=environment,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            check=False,
            timeout=5,
        )
        if completed.returncode != 0:
            self.fail(
                "PHP-CLI-Request fehlgeschlagen: "
                + completed.stderr.decode("utf-8", errors="replace")
            )
        return completed.stdout.decode("utf-8")

    @staticmethod
    def hidden(page: str, name: str) -> str:
        match = re.search(
            rf'<input[^>]+name="{re.escape(name)}"[^>]+value="([^"]*)"', page
        )
        if match is None:
            raise AssertionError(f"Hidden field {name!r} fehlt")
        return html.unescape(match.group(1))

    def login(self) -> str:
        login_page = self.request()
        self.assertIn("Bitte mit dem Adminpasswort anmelden", login_page)
        response = self.request(
            {
                "action": "admin_login",
                "csrf": self.hidden(login_page, "csrf"),
                "admin_password": ADMIN_PASSWORD,
            }
        )
        self.assertEqual("", response)
        return self.request()

    def csrf(self) -> str:
        return self.hidden(self.request(), "csrf")

    def write_settings(self, settings: dict) -> None:
        self.credentials_path.write_text(
            json.dumps(settings, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
        )
        self.credentials_path.chmod(0o660)

    def read_settings(self) -> dict:
        return json.loads(self.credentials_path.read_text(encoding="utf-8"))

    def settings_post(self, **overrides: str) -> dict[str, str]:
        data = {
            "action": "save_settings",
            "csrf": self.csrf(),
            "settings_default_provider": "none",
            "settings_default_to": "+491702222222",
            "settings_default_text": "Neuer kienzlefon app Testtext",
            "settings_max_text_length": "612",
            "settings_allowed_client_ips": "127.0.0.1 ::1",
            "settings_seven_api_key": "",
            "settings_seven_from": "Praxis",
            "settings_seven_endpoint": "https://gateway.seven.io/api/sms",
            "settings_seven_timeout_seconds": "15",
            "settings_fritz_host": "fritz.test",
            "settings_fritz_username": "",
            "settings_fritz_password": "",
            "settings_fritz_totp_secret": "",
            "settings_fritz_totp_digits": "6",
            "settings_fritz_totp_period": "30",
            "settings_fritz_timeout_seconds": "20",
            "settings_fritz_delete_after_send": "1",
            "settings_queue_database_path": str(self.queue_path),
            "settings_queue_delivery_provider": "fritz",
            "settings_auto_reply_messages": "\n".join(DEFAULT_REPLIES),
        }
        data.update(overrides)
        return data

    def test_forms_render_all_providers_without_exposing_credentials(self) -> None:
        page = self.request()
        self.assertIn("kienzlefon app - SMS-Konfiguration", page)
        for provider in ("none", "seven", "fritz", "queue"):
            self.assertIn(f'value="{provider}"', page)
        self.assertIn("Queue (vormerken)", page)
        self.assertIn("Empfang ist noch nicht implementiert", page)
        self.assertIn(DEFAULT_REPLIES[0], page)
        for secret in (
            "seven-api-secret",
            "fritz-user-secret",
            "fritz-password-secret",
            "JBSWY3DPEHPK3PXP",
            "local-pin-secret",
        ):
            self.assertNotIn(secret, page)

    def test_save_replaces_reply_list_and_preserves_secrets_and_unknown_keys(self) -> None:
        page = self.request(
            self.settings_post(settings_auto_reply_messages="Kurze Antwort")
        )
        self.assertIn("SMS-Einstellungen wurden lokal gespeichert", page)
        saved = self.read_settings()
        self.assertEqual(["Kurze Antwort"], saved["auto_reply"]["messages"])
        self.assertEqual("seven-api-secret", saved["seven"]["api_key"])
        self.assertEqual("fritz-user-secret", saved["fritzbox"]["username"])
        self.assertEqual("fritz-password-secret", saved["fritzbox"]["password"])
        self.assertEqual("JBSWY3DPEHPK3PXP", saved["fritzbox"]["totp_secret"])
        self.assertEqual("local-pin-secret", saved["ui"]["admin_pin"])
        self.assertEqual({"must": "survive"}, saved["unknown_top_level"])
        self.assertEqual("keep-sms", saved["sms"]["unknown_sms_setting"])
        self.assertEqual("keep-queue", saved["queue"]["unknown_queue_setting"])
        self.assertEqual(9, saved["queue"]["poll_interval_seconds"])
        self.assertEqual(2345, saved["queue"]["busy_timeout_ms"])
        self.assertFalse(self.queue_path.exists(), "Speichern darf keine Queue-DB anlegen")

    def test_empty_reply_field_disables_auto_reply(self) -> None:
        page = self.request(self.settings_post(settings_auto_reply_messages=" \n\r\n"))
        self.assertIn("SMS-Einstellungen wurden lokal gespeichert", page)
        self.assertEqual([], self.read_settings()["auto_reply"]["messages"])

    def test_invalid_stored_replies_render_as_error_and_can_be_repaired(self) -> None:
        for invalid in (None, "invalid", {"messages": "invalid"}):
            with self.subTest(invalid=invalid):
                settings = self.read_settings()
                settings["auto_reply"] = invalid
                self.write_settings(settings)
                page = self.request()
                self.assertIn('class="message error"', page)
                self.assertNotIn("Fatal error", page)
                page = self.request(self.settings_post(settings_auto_reply_messages="Korrigierte Antwort"))
                self.assertIn("SMS-Einstellungen wurden lokal gespeichert", page)
                self.assertEqual(["Korrigierte Antwort"], self.read_settings()["auto_reply"]["messages"])

    def test_username_is_only_cleared_explicitly(self) -> None:
        self.request(self.settings_post(settings_fritz_username_clear="1"))
        self.assertEqual("", self.read_settings()["fritzbox"]["username"])
        self.assertEqual("fritz-password-secret", self.read_settings()["fritzbox"]["password"])

    def test_failed_queue_attempt_retains_request_id_in_retry_form(self) -> None:
        page = self.request()
        request_id = self.hidden(page, "queue_request_id")
        data = {"action": "send_sms", "provider": "queue", "csrf": self.hidden(page, "csrf"),
                "queue_request_id": request_id, "recipient": "+491700000000", "message": "x" * 71}
        failed = self.request(data)
        self.assertIn("maximal 70 Zeichen", failed)
        self.assertEqual(request_id, self.hidden(failed, "queue_request_id"))
        self.assertFalse(self.queue_path.exists())
        data["message"] = "Kurzer Text"
        success = self.request(data)
        self.assertIn("zum Versand vorgemerkt", success)
        self.assertNotEqual(request_id, self.hidden(success, "queue_request_id"))

    def test_old_post_without_new_fields_preserves_queue_and_replies(self) -> None:
        data = self.settings_post()
        del data["settings_queue_database_path"]
        del data["settings_queue_delivery_provider"]
        del data["settings_auto_reply_messages"]
        page = self.request(data)
        self.assertIn("SMS-Einstellungen wurden lokal gespeichert", page)
        saved = self.read_settings()
        self.assertEqual(self.initial_settings["queue"], saved["queue"])
        self.assertEqual(DEFAULT_REPLIES, saved["auto_reply"]["messages"])

    def test_long_fritz_reply_rejects_entire_save(self) -> None:
        before = self.credentials_path.read_bytes()
        # 36 Supplementary-Plane-Zeichen belegen 72 UTF-16-Einheiten.
        page = self.request(self.settings_post(settings_auto_reply_messages="😀" * 36))
        self.assertIn("maximal 70 Zeichen", page)
        self.assertEqual(before, self.credentials_path.read_bytes())

    def test_queue_validation_errors_do_not_save_or_create_database(self) -> None:
        before = self.credentials_path.read_bytes()
        page = self.request(
            self.settings_post(
                settings_default_provider="queue",
                settings_queue_database_path="relative/queue.sqlite",
            )
        )
        self.assertIn("absoluter Datenbankpfad", page)
        self.assertEqual(before, self.credentials_path.read_bytes())
        self.assertFalse(self.queue_path.exists())

        page = self.request(
            self.settings_post(
                settings_default_provider="queue",
                settings_queue_delivery_provider="smtp",
            )
        )
        self.assertIn("Queue-Zustellprovider", page)
        self.assertEqual(before, self.credentials_path.read_bytes())
        self.assertFalse(self.queue_path.exists())

    def test_queue_send_is_durable_idempotent_and_needs_no_fritz_credentials(self) -> None:
        settings = self.read_settings()
        settings["default_provider"] = "queue"
        settings["fritzbox"]["username"] = ""
        settings["fritzbox"]["password"] = ""
        settings["fritzbox"]["totp_secret"] = ""
        self.write_settings(settings)

        page = self.request()
        request_id = self.hidden(page, "queue_request_id")
        csrf = self.hidden(page, "csrf")
        post = {
            "action": "send_sms",
            "csrf": csrf,
            "queue_request_id": request_id,
            "provider": "queue",
            "recipient": "+491709999999",
            "message": "Nur dauerhaft vormerken",
        }
        first = self.request(post)
        second = self.request(post)
        self.assertIn("zum Versand vorgemerkt", first)
        self.assertIn("zum Versand vorgemerkt", second)
        result_box = re.search(r'<div class="message ok">(.*?)</div>', first, re.DOTALL)
        self.assertIsNotNone(result_box)
        self.assertNotIn("gesendet", result_box.group(1).lower())
        self.assertTrue(self.queue_path.is_file())
        with sqlite3.connect(self.queue_path) as connection:
            count = connection.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0]
            status = connection.execute("SELECT status FROM sms_queue").fetchone()[0]
        self.assertEqual(1, count)
        self.assertEqual("pending", status)

        next_id = self.hidden(first, "queue_request_id")
        self.assertNotEqual(request_id, next_id)
        post["queue_request_id"] = next_id
        third = self.request(post)
        self.assertIn("zum Versand vorgemerkt", third)
        with sqlite3.connect(self.queue_path) as connection:
            count = connection.execute("SELECT COUNT(*) FROM sms_queue").fetchone()[0]
        self.assertEqual(2, count)

    def test_csrf_and_admin_protection_remain_active(self) -> None:
        unauthenticated_session = secrets.token_hex(16)
        login_page = self.request(session_id=unauthenticated_session)
        self.assertIn("Bitte mit dem Adminpasswort anmelden", login_page)
        self.assertNotIn("Queue (vormerken)", login_page)
        bad_login = self.request(
            {
                "action": "admin_login",
                "csrf": self.hidden(login_page, "csrf"),
                "admin_password": "wrong-password",
            },
            session_id=unauthenticated_session,
        )
        self.assertIn("Adminpasswort ist ungueltig", bad_login)

        before = self.credentials_path.read_bytes()
        page = self.request(
            {
                "action": "send_sms",
                "csrf": "0" * 32,
                "queue_request_id": "1" * 32,
                "provider": "queue",
                "recipient": "+491700000000",
                "message": "Nicht vormerken",
            }
        )
        self.assertIn("Formular-Token ist ungueltig", page)
        self.assertEqual(before, self.credentials_path.read_bytes())
        self.assertFalse(self.queue_path.exists())


class SmsConfigSourceTest(unittest.TestCase):
    def test_shared_transport_functions_are_identical_to_library(self) -> None:
        config = (INTEGRATION_DIR / "sms-config.php").read_text(encoding="utf-8")
        library = (INTEGRATION_DIR / "telepraxis-sms.php").read_text(encoding="utf-8")
        config_transport = function_block(
            config, "tp_sms_build_url", "tp_sms_fritz_current_totp"
        )
        library_transport = function_block(
            library, "tp_sms_build_url", "tp_sms_send_default"
        )
        self.assertEqual(library_transport, config_transport)

    def test_patchable_constants_and_version_remain_unchanged(self) -> None:
        source = (INTEGRATION_DIR / "sms-config.php").read_text(encoding="utf-8")
        self.assertIn("const TP_SMS_VERSION = '0.3.1';", source)
        self.assertIn("const TP_SMS_CREDENTIALS_FILE = 'sms-credentials.json';", source)
        self.assertIn("const TP_SMS_CONFIG_ADMIN_PASSWORD = 'bitte-aendern';", source)
        self.assertIn("const TELEPRAXIS_SMS_CONFIG = true;", source)


if __name__ == "__main__":
    unittest.main(verbosity=2)

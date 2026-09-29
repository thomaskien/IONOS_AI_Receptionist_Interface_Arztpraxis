# Maßgeblicher SMS-Stand und gezielte Übernahme

Stand: 29.09.2026. **Das Repository `thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis`
(lokales Projekt `ionos`) ist künftig maßgeblich für die SMS-Funktionen.**

Die reguläre `telepraxis-app.php`, ihre SMS-Konfiguration, Bibliotheken,
Worker und SMS-Tests werden dort weiterentwickelt. `kienzlefon` enthält eine
Demointegration und ausdrücklich keine automatisch synchronisierte zweite
Produktivversion. Änderungen zuerst in `ionos` implementieren und testen;
benötigte Bibliotheksänderungen anschließend gezielt in die Demo übernehmen.
Die beiden App-Dateien dürfen nicht gegenseitig vollständig ersetzt werden.

## Übernommener Stand

Ausgangspunkt in `ionos`: Commit `4c8c665` einschließlich der bereits lokal
vorliegenden Änderungen vom 29.09.2026, insbesondere der sichtbaren Bezeichnung
„kienzlefon app“. Die erste Übernahme erfolgte noch unter Version 3.4.2.
Der Nutzer hat die fertigen SMS-Funktionen anschließend ausdrücklich als
**Version 3.5** freigegeben. Der vollständige bisherige Changelog bleibt erhalten
und wird durch den Eintrag für v3.5 vom 29.09.2026 ergänzt.

| Aus `kienzlefon` | Maßgebliche Datei in `ionos` |
| --- | --- |
| `demo-webseite/telepraxis-sms.php` | `telepraxis-sms.php` |
| `demo-webseite/telepraxis-sms-queue.php` | `telepraxis-sms-queue.php` |
| `demo-webseite/telepraxis-sms-worker.php` | `telepraxis-sms-worker.php` |
| SMS-bezogene Änderungen in `demo-webseite/telepraxis-app-demo.php` | gezielt in `telepraxis-app.php` |
| `tests/test_telepraxis_sms*.py` | gleiche Tests mit angepassten Dateipfaden in `tests/` |
| `config/sms-queue-*.example.json`, Dienstvorlage | gleiche Pfade in `config/` |
| `docs/SMS-QUEUE.md` | Anleitung für die reguläre App unter gleichem Pfad |

Der geprüfte Übernahmestand mit 59 Tests und SHA-256-Prüfsummen ist in
`SMS-TRANSFER-2026-09-29.json` festgehalten. Die dortigen Pfade und Prüfsummen
bezeichnen die Ausgangsdateien in `kienzlefon`, nicht die danach angepassten
Zieldateien. Zusätzliche Integrationstests prüfen die reguläre App und Konfiguration.

`sms-config.php` wurde im IONOS-Stand um Queue-Auswahl und Einstellungen erweitert.
Die bisher dort enthaltenen Transportfunktionen bleiben wegen der bestehenden
Installer-Schnittstellen zunächst erhalten und werden gegen die Bibliothek
geprüft. TOTP-Änderungen müssen beide Versandoberflächen erreichen.

## Bewahrte Funktionen und Grenzen

Papierkorb, Wiederherstellung, Adminschutz bei endgültigem Löschen, Kommentare,
Platzbindung, Statuswechsel, Dringend-Markierung, Kopfzeile, Polling, Druck- und
Kopieransicht stammen aus der regulären App. Das sofortige endgültige Löschen
der Demo wurde nicht übertragen. Bestehende Credentials werden durch die
Codeübernahme weder ersetzt noch aktiviert; die Queue muss bewusst als Provider
ausgewählt werden.

Implementiert sind TOTP-Fix, persistente Ausgangsqueue, unabhängiger Worker,
gesonderte Löschaufträge und die konfigurierbare Liste mit zwei Antwort-SMS.
Inzwischen sind auch Empfang und vollständiger Journalabgleich für das bestätigte
FRITZ!OS-Format implementiert und auf dem Zielsystem aktiviert. Lange SMS kommen
im geprüften Format bereits zusammengesetzt an; unbekannte Segmentinformationen
werden zurückgehalten und nicht automatisch beantwortet.

Weitere SMS werden dem zuletzt angelegten offenen Vorgang derselben Nummer
zugeordnet und dort chronologisch als blaue Kommentare mit Benachrichtigungston
angezeigt. Nur die erste SMS je Vorgang erhält die Antwortliste, ausschließlich
an vollständige deutsche Nummern. App und Empfangsspeicher verwenden eine
gemeinsame Inbox-Sperre und atomare Dateischreibvorgänge. Installation und
Wiederanlauf sind in [SMS-EMPFANG.md](SMS-EMPFANG.md) beschrieben.

Der Zielserver-Installer erhält die zusätzliche Queue-Bibliothek und die
PDO-SQLite-Abhängigkeit. Der SMS-Worker wird weiterhin separat nach
`SMS-QUEUE.md` eingerichtet; er wird durch diese Codeänderung weder installiert
noch gestartet. Webapp und Worker benötigen dieselbe lokale SQLite-Datei auf
dem Zielserver, getrennt vom Webroot und vom IONOS-Quellserver für verschlüsselte
Eingangsdaten.

## Veröffentlichung und Installation

Installation und laufender Empfang wurden ausdrücklich freigegeben und sind
abgeschlossen. Am 29.09.2026 hat der Nutzer zusätzlich die Aktualisierung der
Dokumentation und Veröffentlichung der Änderungen auf GitHub beauftragt.
Die Git-Historie dieses Repositorys bezeichnet den veröffentlichten Stand;
die Prüfsummen im Transfermanifest bleiben der historische Übernahmenachweis.

Der App-Updater wird mit seinen Tests und dokumentierten Grenzen mitgeführt.
Er aktualisiert die drei Empfangsmodule noch nicht automatisch. Vor Updates
die tatsächlichen Pfade, Dienstbenutzer, gemeinsame Gruppe und vorhandenen
Provider-Einstellungen prüfen. Zugangsdaten, produktive Datenbanken und
Vorgangsdateien bleiben außerhalb von Git.

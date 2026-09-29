# Review der SMS-Übernahme nach IONOS

Historischer Übernahmebericht vom 29.09.2026 für die erste Queue-Integration.
Die folgenden 74 Tests und damaligen offenen Punkte beschreiben diese erste
Etappe. Empfang, Installation, Vorgangszuordnung und SMS-Ton wurden anschließend
fertiggestellt; der Nutzer hat inzwischen auch die GitHub-Veröffentlichung
beauftragt. Den aktuellen Stand und die späteren Prüfungen beschreibt
[SMS-UEBERGABE-2026-09-29.md](SMS-UEBERGABE-2026-09-29.md).
Die fertiggestellte SMS-Erweiterung ist auf Nutzerwunsch als App-Version 3.5
freigegeben; die Versionsangaben unten beschreiben die historische Übernahme.

## Ergebnis

IONOS ist ab diesem Stand das maßgebliche SMS-Repository. Die reguläre App
erhält die Queue als dritten aktiven Versandweg; `sms-config.php` bietet Queue
sowohl als Standard-Provider als auch für Testaufträge an. Der eigenständige
Worker und seine persistente Löschqueue werden mitgeführt. Konfigurierbar sind
Queue-Pfad, Zustellprovider und die Liste vorbereiteter Antwort-SMS.

Die Bibliotheken `telepraxis-sms.php`, `telepraxis-sms-queue.php` und
`telepraxis-sms-worker.php` entsprechen dem zuvor geprüften Stand aus
`kienzlefon`. Die gemeinsame Transportimplementierung in `sms-config.php` wird
zusätzlich auf Gleichheit mit der Bibliothek geprüft, damit der TOTP-Fix auf
beiden Versandoberflächen erhalten bleibt.

Grundlage der App ist die aktuelle lokale IONOS-Datei aus Commit `4c8c665`
einschließlich der vorhandenen Produktumbenennung. Übernommen wurden nur die
SMS-bezogenen Änderungen. Die App behält Version 3.4.2 und den bisherigen
Changelog; `sms-config.php` behält 0.3.1. Neue datierte Changelog-Einträge
beschreiben die Integration. Es wurde keine neue Release-Version festgelegt.

## Bewahrung und Review

- Bestehende Credentials-Datei nicht übernommen, nicht überschrieben und nicht
  für Tests verwendet. Alle nicht zur Übernahme gehörenden lokalen Dateien
  werden beim Übertragen per SHA-256 gegen den Ausgangsstand geprüft.
- Pfadkonstanten und gepatchte Adminpasswörter der Ausgangsbasis erhalten.
- Bestehende lokale Änderungen in README, App, Konfiguration und Installer
  als Grundlage erhalten; übrige lokale Änderungen bleiben unberührt.
- Papierkorb und Wiederherstellung aus der regulären App erhalten. Die sofortige
  endgültige Löschung der Demo ist nicht enthalten.
- Queue-Testwiederholungen verwenden dieselbe Auftragskennung; erst ein
  erfolgreicher Auftrag bereitet eine neue Kennung im Ergebnisformular vor.
- Leere Secret-Felder bewahren gespeicherte Werte; Löschen erfolgt ausdrücklich.
  Ungültige Antwortlisten werden verständlich angezeigt und können korrigiert
  werden. Validierungsfehler speichern keine Teilkonfiguration.
- Zielserver-Installer ergänzt nur Queue-Bibliothek und PDO-SQLite-Abhängigkeit.
  Die Einrichtung des SMS-Workers bleibt ein eigener Installationsschritt.

## Validierung

Alle Prüfungen liefen in einer temporären Kopie des vollständigen Zielstands;
bei der Übernahme werden die Zieldateien auf identischen Inhalt geprüft.

| Prüflauf | Ergebnis |
| --- | --- |
| FRITZ!Box-/TOTP-Protokoll am lokalen HTTP-Testserver | 15 Tests bestanden |
| Persistente Versandqueue | 14 Tests bestanden |
| Konfigurierbare Antwortliste und Transaktionen | 10 Tests bestanden |
| Separate Löschqueue, Wiederholungen und Abstürze | 9 Tests bestanden |
| Reguläre App inklusive Papierkorb, Kommentaren und Dringend-Markierung | 13 Tests bestanden |
| SMS-Konfiguration, Zugangsschutz, Settings-Erhalt und Transportgleichheit | 13 Tests bestanden |
| PHP-Syntax aller fünf betroffenen PHP-Dateien | bestanden |
| Gerendertes eingebettetes App-JavaScript mit Node `--check` | bestanden |
| Installer mit `bash -n` | bestanden |

Gesamt: **74 Tests bestanden.** Keine echte SMS versendet, keine FRITZ!Box
kontaktiert. Bei der Integration wurden keine Provider aktiviert und kein
Dienst gestartet. Ein Test gegen die tatsächliche Installation steht aus.

## Damals noch offen – inzwischen umgesetzt

Nach dieser ersten Integration fehlten Empfangsadapter und Prüfung des echten
FRITZ!OS-Formats. Beides ist inzwischen erfolgt; eine bekannte lange SMS kam
bereits vollständig zusammengesetzt an. Dauerhafte Eingangssicherung, geprüfte
Routerbereinigung und einmalige Bestätigung je Vorgang sind implementiert.
Unbekannte Segment-/Listenformate werden weiterhin bewusst nicht geraten.

Die Repository-Zuordnung steht in [SMS-REPOSITORIES.md](SMS-REPOSITORIES.md),
Einrichtung und Konfigurationsvarianten in [SMS-QUEUE.md](SMS-QUEUE.md),
der aktive Empfang in [SMS-EMPFANG.md](SMS-EMPFANG.md).

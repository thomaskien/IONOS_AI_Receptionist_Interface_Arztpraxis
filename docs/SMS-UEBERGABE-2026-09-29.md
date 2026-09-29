# Übergabe: SMS-Weiterentwicklung im Projekt ionos

Stand: 29.09.2026. Diese Datei ist der Einstieg für die Fortsetzung der SMS-Arbeit.
Aktuelle freigegebene App-Version: **3.5** mit der vollständigen SMS-Erweiterung.
Die Versionsanpassung ist auf dem Zielsystem installiert; Kopfzeile und
Browsertitel verwenden 3.5. PHP-/JavaScript-Prüfung und alle 18 App-Tests
bestanden. Der vollständige Funktionsstand wurde zuvor mit 170 Tests geprüft.

## Arbeitsort und maßgebliche Version

**Ab jetzt im lokalen Projekt `ionos` weiterarbeiten:**

```text
<Projektverzeichnis>/ionos
```

Repository: `thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis`.
Dieser Stand ist maßgeblich für SMS-Bibliothek, Konfiguration, reguläre App,
Worker und Tests. Das getrennte Projekt `kienzlefon` enthält die Demo und den
ursprünglichen Entwicklungsstand. Es gibt keine automatische Synchronisierung.
Künftige Änderungen zuerst in `ionos` durchführen; benötigte Bibliotheksänderungen
später gezielt in die Demo übernehmen. Niemals die reguläre App durch
`kienzlefon/demo-webseite/telepraxis-app-demo.php` ersetzen: Die Demo hat ein
anderes Löschverhalten, die reguläre App muss ihren Papierkorb behalten.

Vor Änderungen `AGENTS.md` und `git status` lesen. Die SMS-Integration,
Produktbezeichnungen, Dokumentation und der App-Updater wurden mit Commit
`a9b240f` auf GitHub veröffentlicht. Anschließend hat der Nutzer ausdrücklich
die neue App-Version **3.5** angefordert. Nichts pauschal zurücksetzen oder
überschreiben; den vollständigen Changelog einschließlich der historischen
Einträge für 3.4.2 erhalten. Weitere Versionssprünge benötigen eine neue Freigabe.

## Nutzerauftrag und Entscheidungen

- FRITZ!Box **6850 LTE, FRITZ!OS 8.25**. SMS-Versand über die bestehende
  TOTP-Anbindung funktioniert laut Nutzer bereits.
- Ein eigenständiger SMS-Worker soll Ein- und Ausgang bearbeiten; keine
  Abhängigkeit vom Kienzlefon-/Asterisk-/KI-Worker.
- Eingänge sollen im üblichen Telepraxis-JSON-Format als bearbeitbare Vorgänge
  ankommen. Die lokale Ziel-Inbox wurde im Chat benannt. Das Mapping nutzt
  `typ: "sonstiges"`, `payload.anliegen`, `payload.telefon` und `payload.id`.
- Queue ist der dritte aktive Versandweg neben `fritz` und `seven`; `none`
  deaktiviert den Versand. Die Weboberfläche soll beim Senden nicht auf die
  Routeranmeldung/TOTP warten.
- Aktualisierter Nutzerauftrag: Weitere SMS derselben Nummer an den zuletzt
  angelegten offenen Vorgang (Neu/In Bearbeitung) anhängen, chronologisch unter
  Kommentare und deutlich farbig markieren. Nur für die erste SMS je Vorgang
  die konfigurierte Antwortliste einreihen. Die Liste enthält weiterhin mehrere
  einzelne Antwort-SMS.
- **Keine SMS dauerhaft in der FRITZ!Box belassen.** Empfangene Teile zunächst
  dauerhaft lokal sichern, danach Routerkopien löschen. Gesendete Einträge
  ebenfalls bereinigen; Löschfehler dürfen keine erneute Aussendung auslösen.

Zwei voreingestellte Antwort-SMS, unverändert beibehalten:

```json
{
  "auto_reply": {
    "messages": [
      "1/2 SMS an die Praxis weitergeleitet. Testbetrieb! Vielen Dank!",
      "2/2 Fehlen persönliche Daten, bitte eine neue vollständige SMS senden."
    ]
  }
}
```

Die Texte haben 63 bzw. 70 UTF-16-Codeeinheiten. `messages: []` deaktiviert die
Antwort. Die FRITZ!Box-Anbindung begrenzt jede ausgehende SMS auf 70
UTF-16-Codeeinheiten; der zuvor getestete lange Gesamttext wurde vom Router
nicht zuverlässig versendet. Die Liste enthält einzelne SMS, keine automatisch
zusammengesetzte ausgehende Lang-SMS. seven.io behält die allgemeine Textgrenze.

## Fertiger lokaler Stand

- `telepraxis-sms.php`: TOTP-Freigabe korrekt abwarten, SID-/Cookie-Behandlung,
  70er-Prüfung, Provider `queue`, Nachrichtenliste und Callback zum dauerhaften
  Sichern einer vom Router erzeugten Nachrichten-ID.
- `telepraxis-sms-queue.php`: geschützte lokale SQLite-Datenbank, Schema 3,
  Migration aus Schema 1/2, idempotente Einzel- und Batch-Aufträge, FIFO,
  exklusiver Worker-Lock, kurze Transaktionen ohne Router-I/O.
- `telepraxis-sms-worker.php`: eigenständiger PHP-CLI-Worker mit `--config`,
  `--init`, `--status`, `--once` und Dauerbetrieb. Optionaler Empfang ist jetzt
  integriert; ohne `receive.enabled: true` bleibt er ausgeschaltet.
- `telepraxis-sms-journal.php`, `telepraxis-sms-receive-store.php` und
  `telepraxis-sms-receive.php`: Parser, persistentes Empfangsjournal, atomare
  lokale Ausgabe, einmalige Antwortliste und geprüfte Routerbereinigung.
  Details und Installation: [SMS-EMPFANG.md](SMS-EMPFANG.md).
- Separate persistente Löschqueue: bekannte FRITZ!Box-IDs nach Ende des
  Sendeversuchs löschen, Fehler mit Wartezeit erneut bearbeiten. Sendestatus
  bleibt unverändert. Auch bei anschließend unsicherem Sendeergebnis bleibt eine
  zuvor gesicherte ID für die Bereinigung erhalten.
- `telepraxis-app.php`: gezielt integrierte Queue, HTTP 202 bei Aufnahme,
  Auftragskennung für Wiederholungen, Status im Kommentarverlauf, frühzeitiges
  Freigeben des PHP-Sitzungslocks. Reguläre Funktionen und Produktname erhalten.
- `sms-config.php`: Queue als Standard- und Testprovider, Datenbankpfad,
  Zustellprovider und Antwortliste. Secrets bleiben bei leeren Feldern erhalten.
  Validierungsfehler speichern keine Teilkonfiguration; fehlerhafte gespeicherte
  Antwortlisten sind reparierbar. Fehlgeschlagene Queue-Tests behalten ihre
  Auftragskennung im Formular.
- `zielserver-vorbereiten-v1.8.sh`: zusätzliche Queue-Bibliothek und PDO-SQLite
  vorbereitet. Der SMS-Dienst wird dadurch nicht automatisch eingerichtet.
- Beispiele und Dienstvorlage unter `config/`; Betriebsanleitung in
  [SMS-QUEUE.md](SMS-QUEUE.md).

Die gemeinsamen Versandfunktionen sind bislang zusätzlich in `sms-config.php`
enthalten, um die vorhandenen Installer-Konstanten und Strukturen zu erhalten.
Der Transportblock muss mit `telepraxis-sms.php` übereinstimmen; ein Test prüft
diese Gleichheit. Änderungen dort immer in beiden Dateien nachvollziehen.

Queue-Status: `pending`, `sending`, `accepted`, `failed`, `uncertain`.
`accepted` bestätigt die Providerannahme, nicht die Zustellung beim Empfänger.
Unsichere oder fehlgeschlagene Sendungen werden nicht automatisch wiederholt.
`--status` zeigt zusätzlich `cleanup_pending`.

Für die vorbereitete Eingangsantwort existiert:

```php
tp_sms_queue_auto_reply($settings, $recipient, $receivedMessageKey, $context);
```

Die Empfangskennung muss Gerät und Nachricht stabil unterscheiden. Erst nach
dauerhafter Telepraxis-Ausgabe aufrufen; bei Wiederholung derselben Kennung
entsteht kein zweiter Antwortauftrag. Die Antwortliste wird atomar eingereiht.

Webapp und Worker benötigen dieselbe lokale Queue-Datenbank außerhalb des
Webroots. Keine SQLite-Datenbank auf NFS/SMB oder im Cloud-Sync-Verzeichnis
betreiben. Wenn die Web-Konfiguration Zugangsdaten/Antworten für den Worker
verwalten soll, dieselbe Datei per `--config` verwenden und den Worker nach
Änderungen neu starten. Getrennte Konfigurationsdateien werden nicht synchronisiert.

## Nachgewiesener Empfang und Installationsstand

Serverzugang, vorhandener Konfigurationspfad und lokale Ziel-Inbox sind inzwischen
im Chat bekannt. Die vorhandene Konfiguration wurde nur eingelesen; keine
Zugangsdaten oder privaten Serverdetails wurden in Quelltext oder Dokumentation
übernommen. PHP 8.4 mit den benötigten Erweiterungen ist auf dem Ziel vorhanden.

Der lesende Live-Abruf bestätigte `data.smsListData.messages` mit Eingängen
`received/6` und Ausgängen `sent/0`. Eine vom Nutzer gesendete, bekannte Test-SMS
mit **405 Zeichen** erschien genau einmal und mit unverändertem Gesamttext.
Diese FRITZ!OS-Antwort enthält bereits die zusammengesetzte Nachricht. Es wurden
keine Segment- oder Seitenfelder beobachtet. Der Parser rät keine Zuordnungen:
Unbekannte Segmentinformationen werden archiviert und nicht beantwortet;
ein unbestätigtes Listenformat stoppt den Abgleich.

Das neue Empfangsjournal verwendet die geschützte Queue-Datenbank. Gerät,
Routerhost und kanonischer Inboxpfad werden gebunden. Rohdaten werden vor dem
Löschen dauerhaft gesichert. Einträge werden über Gerätekennung und
Nachrichtenfingerabdruck dedupliziert; Antworten und Ausgabe überstehen einen
Neustart ohne Wiederholung. Ein deaktivierter Antwort-Snapshot bleibt für diesen
Eingang deaktiviert. Vor jedem Löschen wird die Identität frisch gelesen; eine
wiederverwendete UID löscht keinen anderen Text. Fehlende/ungeklärte Daten und
Löschfehler bleiben lokal sichtbar. Keine automatische Aufbewahrungsbereinigung.

**Installation und begrenzter Live-Test wurden inzwischen ausdrücklich
freigegeben und durchgeführt.** Die sechs Worker-Module sind unter
`/opt/telepraxis-sms` installiert; Dienstkonto `telepraxis-sms` und geschützter
Queuebereich `/var/lib/telepraxis-sms` sind eingerichtet. Der Test übernahm
nur die bekannte lange Nachricht der im Chat benannten Testnummer.

Ergebnis: ein vollständiger 405-Zeichen-Vorgang in der tatsächlichen App,
zwei Standardantworten vom Router angenommen, alle drei gesicherten Testkopien
entfernt, keine Duplikate bei Wiederaufnahme. Alle 25 anderen Routereinträge
blieben anhand ihrer Fingerabdrücke unverändert. Queue: 2 accepted, sonst 0;
Löschaufträge: 0 offen; SQLite-Integrität: ok. Der Nutzer hat anschließend den
Handyempfang bestätigt („die sms kam“), ohne die Anzahl gesondert zu nennen. Details stehen in
[SMS-EMPFANG.md](SMS-EMPFANG.md#freigegebene-installation-und-einzelabnahme).

Die Live-Abnahme lief gezielt über die installierten Bibliotheksfunktionen in
getrennten PHP-CLI-Prozessen unter dem Dienstkonto. Die Originalkonfiguration
wurde nur eingelesen und für diesen Aufruf im Speicher ergänzt. Keine reale
Nummer und keine zusätzlichen Credentials wurden in Skriptdateien gespeichert.
Es wurde kein allgemeiner Empfangslauf über den Altbestand ausgeführt.

## Nachfolgendes Queue-Webupdate und laufender Versanddienst

Nach dem Nutzerhinweis, dass „Queue“ in der Web-Konfiguration fehlt, wurde der
zusammengehörige geprüfte Webstand installiert. Ein PHP-Tokenvergleich bestätigte
vorher, dass die Serverdateien abgesehen von Installationskonstanten der
bekannten Ausgangsbasis entsprechen. Der Updater bestand 24 Tests und den
Prüfmodus auf dem Server; Pfade und Adminpasswörter wurden übernommen und die
Originale geschützt unter `/var/backups/kienzlefon-app` gesichert.

App, SMS-Bibliothek, Konfigurationsseite und Queue-Bibliothek sind aktualisiert.
Die bestehende SMS-Konfiguration verwendet jetzt `default_provider: "queue"`,
Zustellprovider `fritz` und `/var/lib/telepraxis-sms/outbox.sqlite`. Andere
Konfigurationsabschnitte einschließlich Zugangsdaten blieben erhalten; die
vorherige Konfiguration liegt geschützt bei der Updatesicherung.

`telepraxis-sms-worker.service` läuft als systemd-Dienst mit Autostart unter dem
Dienstkonto. Prozessstatus active/running, keine Neustarts; beide Webdienste
aktiv. Gerendert unter `www-data`: Queue auswählbar und ausgewählt, korrekter
Datenbankpfad, kein Fehlerbanner. Die App zeigt nun beide Antwortaufträge des
Testvorgangs mit Status `accepted` an. Die aktuelle Bearbeitung der Karte blieb
beim Update erhalten. Im abschließenden Prüfzeitraum wurde ein weiterer Auftrag
an die im Chat genannte Testnummer erfolgreich verarbeitet: insgesamt 3 accepted,
0 pending/sending/failed/uncertain, 0 offene Löschaufträge. Dieser weitere Auftrag
wurde nicht vom Agenten beauftragt.

## Empfang aktiviert; keine Antworten an Kurzwahlen oder ins Ausland

Der Nutzer hat anschließend den Empfang ausdrücklich aktiviert und automatische
Antworten an kurze Netzbetreibernummern sowie ausländische Nummern ausgeschlossen.
Diese Freigabe ist bereits erteilt; keine erneute Rückfrage zur Aktivierung nötig.

Vor dem Einschalten wurde `tp_sms_receive_reply_recipient()` ergänzt und getestet:
Nur `answerable: true` und eine eindeutig normalisierte deutsche Rufnummer mit
`+49` und mindestens acht nationalen Ziffern ohne führende Null erhalten Antworten.
`0049` und die deutsche nationale `0` werden normalisiert. Ausländische Nummern,
Kurzwahlen, alphanumerische und unklare Absender bleiben ohne Antwort, werden aber
weiterhin als Vorgänge ausgegeben. Die Sperre betrifft automatische Eingangsantworten.
32 Empfangstests bestanden einschließlich neuer Auslands-/Kurzwahltests;
PHP-Syntax und Formatprüfung erfolgreich. Das Modul wurde atomar auf dem Server ersetzt.

Bei angehaltenem Dienst wurden Konfiguration und SQLite-Datenbank geschützt
unter `/var/backups/telepraxis-sms` gesichert. Der zu diesem Zeitpunkt vorhandene
Routerbestand umfasste 27 Einträge (19 Eingänge, 8 Ausgänge). Alle sind dauerhaft
archiviert; die 19 Eingänge wurden als Vorgänge ausgegeben und mit dauerhaft
leerer Antwortliste markiert. Keine nachträglichen Antworten an den Altbestand.
Anschließend wurde die Datenbank erneut konsistent gesichert.

`receive.enabled` steht jetzt auf `true`; die bestehende Konfiguration enthält
weiterhin die zwei unveränderten Standardantworten für neue, zulässige Eingänge.
Der systemd-Dienst wurde gestartet und läuft ohne Neustartschleife. Neue SMS
werden automatisch übernommen, beantwortbare vollständige deutsche Nummern
erhalten die Antwortliste genau einmal. Die gesicherten Routerkopien werden
vom laufenden Dienst bereinigt. Zum Abschalten Konfiguration ändern und Dienst
neu starten; nach Routerbereinigung niemals die Empfangsdatenbank auf die
Sicherung vor dem Erstimport zurücksetzen.

Abschlussprüfung: Routerjournal leer, 20 Eingänge insgesamt ausgegeben,
19 alte Eingänge dauerhaft ohne Antwort, alle Löschaufträge erledigt. Keine
Empfangs-/Antwortfehler, keine ungeklärten Einträge, 11 `accepted` unverändert,
sonst keine offenen/fehlgeschlagenen/unsicheren Versandaufträge. Der Erstimport
hat keine Antwortsendung ausgelöst. Deutschland-/Kurzwahlsperre direkt auf dem
Server mit synthetischen Nummern zusätzlich erfolgreich geprüft.

Das mitgeführte und separat geprüfte App-Update-Skript umfasst die neuen
Empfangsmodule noch nicht. Die koordinierte Installation ist in
[APP-UPDATE.md](APP-UPDATE.md#separater-sms-worker) beschrieben.
Eine spätere Ergänzung des Updatewegs um die drei Empfangsmodule sowie eine
Oberfläche für manuelle Sonderfallklärung sind noch offen. Die bereits installierten
Empfangsmodule blieben beim Webupdate erhalten. Die CLI zeigt entsprechende Zähler.

## Weitere SMS zum Vorgang und einmalige Bestätigung

Der anschließende Nutzerauftrag ist umgesetzt und installiert: Neue SMS gehen
an den **zuletzt angelegten offenen Vorgang** derselben normalisierten Nummer,
sofern dessen Status Neu oder In Bearbeitung ist und er nicht im Papierkorb liegt.
Geprüft werden `payload.telefon`, `payload.id` und `payload.anrufer_id`. Ohne
Treffer entsteht wie bisher ein neuer SMS-Vorgang. Alte Karten werden nicht
nachträglich zusammengeführt.

Weitere SMS werden mit vollständigem Text und Router-Empfangszeit in
`app.comments` gespeichert, Typ `sms_received`, mit stabiler Empfangskennung und
Absender. Die App erhält diese Metadaten bei Statusänderungen und Kommentaren,
sortiert den gesamten Verlauf chronologisch und zeigt Eingangs-SMS blau als
„SMS eingegangen“ an – auch in Neu, Tabellen, Druck und Zwischenablage.
Status, Platz und ursprünglicher Haupttext bleiben unverändert.

Nur die erste SMS je Vorgang darf die konfigurierte Antwortliste auslösen.
Auch ein bestehender Telefon-/Webvorgang bekommt die Liste bei seiner ersten SMS;
spätere SMS bleiben ohne erneute Bestätigung. Ein alter SMS-Eingang mit damals
deaktivierter Bestätigung zählt bereits als erste SMS. Auslands- und Kurzwahlsperre
bleiben wirksam. Zuordnung und Erstantwortentscheidung stehen dauerhaft in den
zusätzlichen Journalspalten `export_file` und `reply_first`; Migration additiv,
Queue-Schema weiterhin 3. Wiederanlauf bestätigt vorhandene Kommentar-Kennungen
und erzeugt keine Duplikate. Unklare fehlende Ausgaben bleiben zurückgehalten.

**App und Empfangsspeicher bilden ab jetzt ein zusammengehöriges Update.**
Beide verwenden `.telepraxis-inbox.lock` für den gesamten Änderungsablauf;
die App auch beim endgültigen Löschen. Dateischreiben ist auf beiden Seiten
atomar. Einen alten App-Stand ohne diese Sperre nicht mit dem neuen Empfangsspeicher
betreiben. Betriebsdetails stehen in [SMS-EMPFANG.md](SMS-EMPFANG.md#weitere-sms-zum-offenen-vorgang).

Sol implementierte den klar begrenzten Empfangsteil im isolierten Worktree,
der Lead App-Anzeige, Schreibkoordination und Integration. Nach Review wurden
zusätzlich die Erkennung alter Journalzuordnungen ohne Dateimarker und der Schutz
fehlerhafter Kommentarstrukturen ergänzt. Vollständiger Lead-Lauf: **145 SMS-Tests
bestanden**, einschließlich zehn neuer Zuordnungs-/Recovery-Fälle und drei neuer
App-Integrationsprüfungen. PHP-Lint, vollständige eingebettete JavaScript-Syntax,
Rendering von Karten/Tabellen, Textausgabe, Polling-Fokus und `git diff --check`
erfolgreich. Der abgeschlossene Worker-Worktree wurde archiviert.

Vor Installation bestätigte ein PHP-Tokenvergleich erneut die freigegebene
Server-App-Basis; das Empfangsmodul war bytegleich zur vorigen Installation.
Bei gestopptem Worker und Haupt-Webdienst wurden Inbox, Konfiguration und eine
konsistente SQLite-Sicherung geschützt unter `/var/backups/telepraxis-sms/followup-*`
angelegt. Nur App und Empfangsspeicher wurden ersetzt; Installationskonstanten,
Dateieigentümer, Rechte und bestehende Konfiguration blieben erhalten.

Die additive Migration lief unter dem Dienstkonto erfolgreich. Worker und beide
Webdienste anschließend aktiv, keine Neustartschleife, keine offenen Aufträge oder
Empfangsfehler; Abschlussstand 24 ausgegebene Eingänge und 20 accepted. Diese
Zähler umfassen den normalen Betrieb vor diesem Update. Es gab keinen echten
SMS-Testversand für diese Fortsetzung.

Zusätzliche Serverprüfung in separatem temporären Verzeichnis mit synthetischen
Daten: Dienstkonto hängt erste SMS an, Webkonto ergänzt Praxisnotiz, Dienstkonto
hängt zweite SMS an, Webkonto kann weiter bearbeiten. Ergebnis ein Vorgang,
zwei SMS-Kommentare, unveränderter Bearbeitungsstatus/Platz, genau ein synthetischer
Bestätigungsauftrag, Folgeantwort skipped, SQLite-Integrität ok. Die Testdaten
wurden danach entfernt; keine Produktivvorgänge bearbeitet. Die Webapp muss neu
geladen werden, damit laufende Browser die neue Kommentar-Darstellung verwenden.

## Benachrichtigungston für angehängte SMS

Auf anschließenden Nutzerwunsch ist auch der vorhandene Benachrichtigungston
für neu eingegangene SMS-Kommentare aktiviert. Der Browser merkt sich deren
stabile Empfangskennungen und verwendet dieselbe Tonfolge wie für neue Vorgänge.
Erster Seitenabruf, bekannte SMS, Praxisnotizen und ausgehende Versandstatus
bleiben ohne zusätzlichen SMS-Ton. Stummschaltung gilt weiterhin; bei mehreren
gleichzeitigen Eingängen ertönt die Folge einmal je erfolgreichem Abruf.

Nur `telepraxis-app.php` wurde geändert und nach erneutem Basisvergleich mit
geschützter Sicherung installiert; Installationskonstanten bleiben erhalten.
Haupt-Webdienst neu geladen, beide Webdienste und SMS-Worker aktiv. Der Worker
brauchte dafür keinen Neustart. PHP-Lint und alle **18 App-Integrationstests**
bestanden, einschließlich JavaScript-Syntax, vorhandener Rendering-/Pollingtests
und neuer Prüfung der tatsächlichen Tonfunktion bei SMS, Stummschaltung,
Wiederholungen, verspäteten Antworten und Fehlerwiederholung. Laufende Browser
müssen die App neu laden.

## Prüfstand und Startbefehle

Abschließender Veröffentlichungscheck am 29.09.2026: **170 Tests bestanden**
(146 SMS-/App-/Konfigurationstests und 24 App-Updater-Tests), vollständig mit
verfügbarem Node.js für die JavaScript-Prüfungen und freigegebenen lokalen
HTTP-Testservern. Die folgenden Zahlen dokumentieren frühere Zwischenstände.

Der ursprüngliche Übernahmestand bestand **74 Tests**, die zusätzliche Diagnose
zunächst **81 Tests**. Der Empfangsstand bestand anschließend **129 SMS-Tests**
im vollständigen Lauf (Exit 0). Danach bestand das um einen neuen Empfangstest
ergänzte App-Modul alle **14 Tests** (Exit 0); damit sind insgesamt **130
verschiedene SMS-Tests** erfolgreich geprüft. Der neue Test prüft die Anzeige
eines tatsächlich vom Empfangsspeicher erzeugten Vorgangs samt Antwortverlauf
und Papierkorb/Restore einschließlich der bestehenden Adminpflicht für Restore.
PHP-Syntax der sieben SMS-Module und `git diff --check` sind erfolgreich;
die neuen/ungetrackten Dateien wurden zusätzlich auf Formatfehler geprüft.

PHP 8.5 lokal: Python 3.14 meldet in einigen bestehenden Tests nicht fatale
`ResourceWarning` zu offenen SQLite-Verbindungen. Der Loopback-Testserver
benötigt in der Sandbox eine Freigabe. Tests verwenden ausschließlich synthetische
Router und Telefonnummern. Auf die zunächst rein lesende Live-Diagnose folgte die separat freigegebene
Einzelabnahme mit zwei echten Antworten ausschließlich an die Testnummer.

Im Projekt `ionos`:

```sh
python3 -B -m unittest discover -s tests
php -l telepraxis-app.php
php -l sms-config.php
php -l telepraxis-sms.php
php -l telepraxis-sms-queue.php
php -l telepraxis-sms-worker.php
php -l telepraxis-sms-inspect.php
php -l telepraxis-sms-journal.php
php -l telepraxis-sms-receive-store.php
php -l telepraxis-sms-receive.php
bash -n zielserver-vorbereiten-v1.8.sh
bash -n kienzlefon-app-update-v1.0.sh
git diff --check
```

Node.js muss für die zusätzlichen JavaScript-Verhaltens- und Syntaxprüfungen
im `PATH` verfügbar sein; andernfalls werden diese Prüfungen übersprungen.

Die Protokolltests benötigen einen lokalen HTTP-Testserver; eine Sandbox kann
dessen Loopback-Port blockieren. Die Tests verwenden keine echten SMS-Ziele.

**Commit und Push dieses Stands sind ausdrücklich beauftragt.** Der Nutzer hat
am 29.09.2026 die Veröffentlichung der Änderungen auf GitHub und Anpassung der
Dokumentation angefordert. Die Git-Historie liefert die konkrete Commitreferenz.
Die vorhandenen Änderungen an Produktbezeichnung, Dokumentation und App-Updater
wurden vor der gemeinsamen Veröffentlichung mitgeprüft.

Worker-Installation, Einzelabnahme, Queue-Webupdate, Empfang, Vorgangszuordnung
und SMS-Ton sind installiert. Der Ein-/Ausgangsdienst läuft. Worker-Probeaufrufe
mit echter Konfiguration können SMS senden oder löschen und sind keine Syntaxchecks.

Weitere Unterlagen:

- [Integrationsreview](SMS-INTEGRATION-REVIEW.md)
- [Lesende Empfangsdiagnose und Integrationsgrenzen](SMS-EMPFANG-DIAGNOSE.md)
- [Repository-Zuordnung](SMS-REPOSITORIES.md)
- [Betrieb und Konfiguration](SMS-QUEUE.md)
- [Empfang, Wiederanlauf und Erstaktivierung](SMS-EMPFANG.md)
- [Prüfsummen des ursprünglichen Übernahmestands](SMS-TRANSFER-2026-09-29.json)

## Review der Empfangsfortsetzung

Sol wurde entsprechend der Worker-Regel über den getrennten CLI-Prozess eingesetzt:
ein lesendes Queue-Review, anschließend getrennte Aufträge für Parser und
Empfangsspeicher. Der Lead übernahm Architektur, Transport und Worker-Integration,
prüfte beide Ergebnisse und ergänzte Guards gegen unbestätigte Segment-/Listenfelder,
UID-Wiederverwendung, fehlende IDs beim Abwesenheitsnachweis sowie Tests für
Fehleranzeige und Wiederanlauf. Es wurden keine Worker-Commits übernommen.
Die Ergebnisse wurden gezielt in den Hauptarbeitsbaum übernommen; die beiden
abgeschlossenen Worker-Worktrees anschließend erfolgreich archiviert.

Bestehende Änderungen einschließlich der App-Update-Dateien wurden erhalten
und vor der beauftragten Veröffentlichung geprüft. Nach der ersten Veröffentlichung
hat der Nutzer den Versionssprung auf **3.5** ausdrücklich freigegeben. Der
Versionsheader, die zentrale App-Konstante, der Changelog und die aktuellen
Dokumentationen wurden entsprechend angepasst. Worker-Installation und
nachfolgende Webupdates sind oben dokumentiert.

## Startnachricht für den nächsten Chat im Projekt ionos

> Bitte lies AGENTS.md, docs/SMS-UEBERGABE-2026-09-29.md und docs/SMS-EMPFANG.md.
> Aktuelle freigegebene App-Version ist 3.5; die Versionsanpassung wurde vom Nutzer
> ausdrücklich für die fertige SMS-Erweiterung angefordert.
> SMS-Worker und Queue-Webstand sind installiert. Queue ist Standard, der Dienst
> läuft unter systemd mit Autostart und aktiviertem Empfang. Der Nutzer hat den
> Empfang freigegeben, aber automatische Antworten an Kurzwahlen/Netzbetreiber-
> Kurznummern oder ins Ausland ausdrücklich verboten. Nur vollständige deutsche
> Rufnummern bekommen automatische Antworten. Der Altbestand (19 Eingänge und
> 8 Ausgänge) wurde gesichert; alte Eingänge bleiben dauerhaft ohne Antwort.
> Bewahre alle lokalen Änderungen und die vorhandene Queue-/Empfangsdatenbank.
> Weitere SMS werden inzwischen dem zuletzt angelegten offenen Vorgang derselben
> Nummer zugeordnet und als blaue Kommentare angezeigt. Nur die erste SMS je
> Vorgang erhält die Antwortliste. App und Empfangsspeicher sind dafür gemeinsam
> aktualisiert und verwenden eine gemeinsame Inbox-Sperre; nicht getrennt auf
> einen alten Stand zurücksetzen. Neue SMS-Kommentare lösen den vorhandenen
> Benachrichtigungston aus; erste Seitenladung und bekannte SMS bleiben stumm.
> Die synthetische Prüfung beider Dienstkonten auf dem Server war erfolgreich.
> Installation, Webupdate und Empfang sind freigegeben und erledigt; nicht erneut
> danach fragen. Zugangsdaten und private Serverdetails nicht in Chat oder
> Repository übernehmen. Commit und Push dieses Stands sind ausdrücklich
> beauftragt; den aktuellen Git-Stand prüfen, statt erneut danach zu fragen.

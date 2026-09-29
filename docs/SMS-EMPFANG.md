# SMS-Empfang und Routerjournal

Stand: 29.09.2026. Lokal implementiert, mit synthetischen Routern getestet und
nach Nutzerfreigabe als Worker-Module installiert. Ein begrenzter Live-Test ist
erfolgreich abgeschlossen. Versand und Empfang laufen inzwischen dauerhaft
unter systemd. Automatische Antworten sind ausschließlich für vollständige
deutsche Rufnummern freigegeben; der vorhandene Altbestand wurde ohne Antworten übernommen.
Der bestehende eigenständige PHP-Worker übernimmt optional auch den Empfang.
Die reguläre App behält ihren Papierkorb; die beiden Standardantworten werden
einmal je Vorgang bei dessen erster SMS verwendet.

## Bestätigtes Format

Auf der FRITZ!Box 6850 LTE mit FRITZ!OS 8.25 wurde lesend bestätigt:

- `POST data.lua`: `sid`, `page=smsList`, `xhr=1`.
- Liste unter `data.smsListData.messages`, daneben `tfaEnabled`.
- Eingang: `sender`, `answerable`, `status: 6`, `date`, `text`, `uid`,
  `status_name: "received"`.
- Ausgang: `receiver`, `answerable`, `status: 0`, `date`, `text`, `uid`, `ref`,
  `status_name: "sent"`.
- Gerätekennung aus `jason_boxinfo.xml`, Feld `Serial`; gespeichert wird nur
  `SHA256("fritz-serial:" + trim(Serial))`.

Eine vom Nutzer gesendete, bekannte Test-SMS mit 405 Zeichen wurde genau einmal
mit vollständig identischem Text gefunden. Die Box lieferte sie bereits
zusammengesetzt. Es wurden keine Segment- oder Seiteninformationen beobachtet.
Das ist ein Nachweis für diesen Text und Firmwarestand, keine Zusicherung für
jedes zukünftige Routerformat. Der Testtext ist ohne reale Rufnummer oder
Gerätekennung in `tests/test_telepraxis_sms_receive.py` enthalten.

Der Parser hängt keine Texte anhand von Rufnummer und Zeit zusammen. Neue
Segmentfelder werden als ungeklärt lokal archiviert, ohne Vorgang oder Antwort.
Ein unbekanntes Listenformat bricht den Abgleich vor der Bereinigung ab.
Andere Statuswerte, ungültige Felder und fehlende IDs bleiben als Rohdaten
erhalten. Nur beobachtete Endzustände `received/6` und `sent/0` dürfen nach
erneutem Identitätsvergleich in der Box gelöscht werden.

## Verarbeitung und Wiederanlauf

1. Derselbe Prozess-Lock wie beim Versand sperrt den Routerzugriff anderer
   Worker dieser Queue. Der Empfang prüft die Geräteidentität vor und nach
   dem Journalabruf.
2. Alle gelesenen Einträge werden in einer kurzen SQLite-Transaktion dauerhaft
   archiviert. `WAL` und `synchronous=FULL` gelten wie für die Versandqueue.
   Keine Routeroperation läuft innerhalb einer Schreibtransaktion.
3. Vollständige Eingänge werden einem offenen Vorgang derselben Nummer zugeordnet
   oder als `sms-<Empfangskennung>.json` neu angelegt. Änderungen erfolgen über
   temporäre Datei, Modus `0660`, `fsync`, atomaren `rename` und Synchronisierung
   des Verzeichnisses. Die gemeinsame Inbox-Sperre schützt App und Worker.
4. Nur für die erste SMS eines Vorgangs und erst nach bestätigter Ausgabe wird die zum Eingangszeitpunkt gespeicherte
   Antwortliste als ein idempotenter Queue-Batch eingereiht. Ein Neustart nach
   dem Einreihen erzeugt keine zweite Liste. `messages: []` bleibt für diesen
   Eingang auch bei späterer Konfigurationsänderung dauerhaft deaktiviert.
5. Die Routerkopie darf bereits nach der dauerhaften Rohdatensicherung entfernt
   werden. Vor jedem Löschen werden Gerät, UID und Nachrichtenfingerabdruck
   frisch verglichen. Eine wiederverwendete UID löscht keinen neuen Inhalt.
   Löschfehler bleiben mit Wartezeit offen und lösen keine weitere SMS aus.

Die Datenbank bindet sich beim ersten erfolgreichen Abgleich an Gerätekennung,
Routerhost und kanonischen Inboxpfad. Änderungen daran werden abgewiesen und
müssen geplant werden; die Datenbank nicht einfach löschen, um die Sperre zu
umgehen. Die Empfangskennung enthält Gerät und Fingerabdruck aus UID, Richtung,
Datum, Telefonnummer und Text. Unbekannte Einträge werden anhand ihrer gesamten
normalisierten Rohdaten unterschieden.

Die Ausgabe verwendet `typ: "sonstiges"`, den Originaltext in
`payload.anliegen`, die Absenderkennung in `payload.id` und `payload.telefon`,
`payload.quelle: "sms"` und das Routerdatum als `received_at`. Die bestehenden
App-Funktionen können diesen Vorgang unverändert bearbeiten. Automatische
Antwortaufträge werden über `source_file` der Karte zugeordnet.

Bereits ausgegebene Nachrichten werden nicht erneut ausgegeben und nach dem endgültigen
Löschen in der App nicht neu erstellt. Ist nach einem Prozessabbruch eine passende
Datei vorhanden, wird sie unter Erhalt der App-Änderungen bestätigt. Fehlt sie
im unterbrochenen Zustand `exporting`, bleibt der Eingang mit
`output_missing_after_interruption` zurückgehalten: Ein noch nicht erfolgter
Export lässt sich dann nicht sicher von einer inzwischen endgültig gelöschten
Karte unterscheiden. Der vollständige Eingang bleibt in der Datenbank erhalten
und benötigt eine bewusste Prüfung. Kein automatisches Verwerfen bei Zeitablauf.

## Weitere SMS zum offenen Vorgang

Seit der Fortsetzung vom 29.09.2026 werden neue SMS anhand von `payload.telefon`,
`payload.id` oder `payload.anrufer_id` bestehenden Vorgängen zugeordnet. Es zählen
nur gültige, vollständige Rufnummern; deutsche nationale `0`, `0049` und `+49`
sowie übliche Formatierungszeichen werden normalisiert. Buchstabenkennungen und
Kurzwahlen führen nicht zum Zusammenlegen unabhängiger Vorgänge.

Es kommen nur **Neu** und **In Bearbeitung** ohne Papierkorbmarkierung infrage.
Bei mehreren Treffern entscheidet auf ausdrücklichen Nutzerwunsch der neueste
Anlagezeitpunkt (`received_at`, mit Zeitzone); gleiche Zeitpunkte werden über den
Dateinamen eindeutig aufgelöst. Abgeschlossene oder gelöschte Vorgänge bleiben
unberührt. Bisher getrennte Karten werden nicht nachträglich zusammengeführt.

Angehängte SMS stehen in `app.comments` mit vollständigem Text, Empfangszeit,
`kind: "sms_received"`, `sms_received_key` und `sms_sender`. App und Worker
erhalten diese Kennzeichnungen auch bei späterer Bearbeitung. Die App sortiert
SMS, Praxisnotizen und Versandstatus chronologisch und markiert Eingangs-SMS
blau mit dem Text „SMS eingegangen“. Das gilt für Karten und Tabellen, auch in
„Neu“; Druck und Zwischenablage enthalten die Kennzeichnung ebenfalls.
Status, Platzzuordnung und Haupttext des Vorgangs bleiben erhalten.

Neue Eingangs-SMS im Kommentarverlauf lösen beim Polling denselben
Benachrichtigungston wie neue Vorgänge aus. Die vorhandene Ton-Einstellung gilt
auch hier. Empfangskennungen werden für die geladene Seite gemerkt, sodass
wiederholte oder verspätete Polling-Antworten den SMS-Ton nicht erneut auslösen.
Der erste Seitenabruf bleibt für vorhandene Einträge stumm; Praxisnotizen und
ausgehende SMS-Statusmeldungen erzeugen keinen zusätzlichen SMS-Ton. Mehrere
gleichzeitig erkannte Eingänge nutzen eine gemeinsame Tonfolge.

Die erste SMS eines Vorgangs erhält die konfigurierte Antwortliste genau einmal.
Das gilt auch für die erste SMS zu einem zuvor per Telefon oder Webformular
angelegten Vorgang. Weitere SMS erhalten `reply_first: 0` und `reply_status:
skipped`, ohne neue Antwortaufträge. Eine bereits vorhandene SMS zählt auch dann
als erste, wenn für sie Antworten deaktiviert waren. Nach Abschluss beginnt eine
neue SMS einen neuen Vorgang, sofern kein anderer passender offener Vorgang besteht.
Auslands- und Kurzwahlsperre gelten weiterhin.

Die Empfangstabelle erhält additiv `export_file` und `reply_first`; alte Zeilen
bleiben kompatibel, das Queue-Schema bleibt 3. Zuordnung und Antwortentscheidung
werden vor der Dateiveröffentlichung dauerhaft gespeichert. Bei Wiederanlauf
belegt entweder die ursprüngliche SMS-Kennung oder die Kommentar-Kennung die
erfolgte Übernahme. Fehlt der Nachweis, bleibt der Datensatz zur Prüfung zurück.

**App und Empfangsspeicher gemeinsam aktualisieren:** Beide verwenden für
Änderungen und endgültiges Löschen die stabile Datei `.telepraxis-inbox.lock`
in der Inbox. Sie muss für Dienstkonto und Webservergruppe gemeinsam schreibbar
sein (0660). Nicht während des Betriebs löschen oder austauschen. Die App schreibt
Vorgänge ebenfalls atomar. Ein alter App-Stand ohne diese Sperre darf nicht
parallel zum neuen Empfangsspeicher schreiben. Vor dem Update Dienste anhalten
und Inbox sowie SQLite-Datenbank konsistent sichern. Bei einem Rollback nach
neuen Eingängen zuerst Empfang stoppen; niemals blind die alte Datenbank einspielen.

Antworten setzen `answerable: true` und eine vollständige deutsche Telefonnummer
voraus. Zugelassen sind eindeutig normalisierbare Nummern mit `+49`, `0049`
oder deutscher nationaler `0`, anschließend mindestens acht nationale Ziffern
ohne führende Null. `tp_sms_receive_reply_recipient()` prüft diese Freigabe
unmittelbar vor dem Einreihen. Ausländische Nummern, Kurzwahlen, zu kurze oder
unklare Kennungen und alphanumerische Absender erhalten einen Vorgang, aber keine
automatische Antwort (`reply_status: skipped`). Der Nutzer hat Auslands- und
Kurzwahlantworten ausdrücklich ausgeschlossen. Die Regel betrifft automatische
Eingangsantworten. Versandannahme bleibt vom tatsächlichen
Zustellnachweis getrennt; unsichere Sendungen werden weiterhin nicht wiederholt.

## Installation vorbereiten

Für den Empfang werden gemeinsam benötigt:

```text
telepraxis-sms.php
telepraxis-sms-queue.php
telepraxis-sms-worker.php
telepraxis-sms-journal.php
telepraxis-sms-receive-store.php
telepraxis-sms-receive.php
```

`telepraxis-sms-inspect.php` ist ein separates, optionales Diagnosewerkzeug.
PHP ab 8.1 mit curl, SimpleXML, OpenSSL, PDO-SQLite und funktionierender
UTF-16-Konvertierung wird benötigt. Das Dateisystem muss Datei- und
Verzeichnissynchronisierung unterstützen. Das bisherige App-Update-Skript
installiert diese neuen Empfangsmodule noch nicht; es ersetzt keine Einrichtung
des eigenständigen Empfangsdienstes.

Die vorhandene geschützte SMS-Konfiguration um diesen Abschnitt ergänzen;
Beispiele enthalten ausschließlich Platzhalter:

```json
{
    "receive": {
        "enabled": false,
        "format": "fritz-sms-list-assembled",
        "output_mode": "target-local",
        "inbox_path": "/srv/telepraxis/CHANNEL/inbox"
    }
}
```

`target-local` ist ausschließlich für die bereits entschlüsselte Inbox auf dem
Zielsystem vorgesehen. Diesen Adapter nicht für Klartextablagen auf dem
Quellserver verwenden. Ein verschlüsselter SMS-Transportadapter ist nicht enthalten.

Queue und Empfangsjournal verwenden dieselbe lokale SQLite-Datei außerhalb des
Webroots, von NFS/SMB und Cloud-Sync. Das gesamte Verzeichnis einschließlich WAL
schützen und konsistent sichern. Das Journal enthält Telefonnummern und
Nachrichtentexte; es wird nicht automatisch bereinigt. Für Backups die SQLite-
Backup-Funktion verwenden oder den Dienst stoppen und WAL korrekt berücksichtigen.

Das Dienstkonto braucht die Kanalgruppe `tp-CHANNEL` und Schreibrechte nur in
Queue und Inbox. SSH-Home, `.ssh` und Schlüsselrechte nicht aufweichen. In der
systemd-Vorlage die zusätzliche Gruppe und `ReadWritePaths` für die Inbox
setzen; den Konfigurationspfad und dessen Leserechte prüfen. Geänderte Gruppen
werden für laufende Dienste erst nach Neustart wirksam; bei Änderungen für
`www-data` auch php-fpm neu starten.

Alle Sender derselben Box müssen dieselbe Queue verwenden. Bei gemeinsam
genutzter Konfiguration `default_provider: "queue"` setzen. Der Empfang lehnt
`default_provider: "fritz"` ab. Direkte Testsendungen aus der Konfigurationsseite
oder der Routeroberfläche sind nicht durch den Queue-Lock geschützt und dürfen
nicht parallel zum Empfang laufen. Nach Konfigurationsänderungen Worker neu starten.

**Erstaktivierung bewusst planen:** Der erste Abruf übernimmt auch alle älteren
Journaleinträge. Bei aktiver Antwortliste können ältere, beantwortbare Eingänge
ebenfalls Antworten auslösen. Für einen reinen Erstimport muss die Antwortliste
vorher leer sein; diese Eingänge bleiben anschließend dauerhaft ohne Antwort.
Keine echten Telefonnummern in Beispieldateien eintragen. Beim hier dokumentierten
Zielsystem sind Installation, Einzelabnahme und anschließender allgemeiner Empfang
inzwischen ausdrücklich freigegeben und aktiviert. Der Altbestand wurde ohne
Antworten importiert; für neue Eingänge gelten die deutsche Rufnummernfreigabe
und die einmalige Bestätigung je Vorgang. Der Ablauf ist unten dokumentiert.

## Betrieb und Fehleranzeige

`--init` initialisiert lokal; `--status` liest nur Zähler. Beide kontaktieren die
Box nicht. `--once` und Dauerbetrieb können Eingänge importieren, Routerkopien
löschen und Queue-SMS versenden. Pro Zyklus wird höchstens ein Eingang exportiert,
eine Antwortliste eingereiht, eine Journalbereinigung und ein Versand bearbeitet.
Bei fehlgeschlagenem Journalabgleich wird nicht gesendet oder gelöscht; schon
archivierte Eingänge können weiterhin lokal ausgegeben und vorgemerkt werden.

Im Empfangsmodus ersetzt die sichere Journalbereinigung die alte, ausschließlich
an Ausgangsaufträge gebundene Löschqueue. Nach bestätigter Abwesenheit bzw.
Bereinigung werden deren Aufträge abgeglichen; der Sendestatus bleibt unverändert.

`--status` ergänzt die Versandzähler um:

| Zähler | Bedeutung |
| --- | --- |
| `received_pending` | Wartet auf lokale Ausgabe oder Wiederanlaufprüfung |
| `received_exported` | Dauerhaft als Vorgang ausgegeben |
| `received_held` | Ausgabe braucht manuelle Prüfung, z.B. Dateikollision |
| `reply_pending` | Antwortentscheidung bzw. Einreihen steht aus |
| `reply_error` | Gespeicherte Antwortkonfiguration ist ungültig |
| `journal_cleanup_pending` | Routerbereinigung steht aus |
| `journal_cleanup_failed` | Davon bereits fehlgeschlagene Löschversuche |
| `journal_unclassified` | Rohdaten gesichert, Format/Status nicht freigegeben |

`--once` liefert bei Abgleichfehlern, zurückgehaltenen oder ungeklärten Einträgen,
Antwortfehlern und Löschfehlern Exit 1. Ausgaben enthalten nur Status und neutrale
Fehlercodes. Ein ungeklärter historischer Eintrag bleibt im Zähler erhalten,
auch wenn die Box später einen anderen Zustand derselben UID liefert.
Fehlerdetails und Rohdaten bei Bedarf geschützt lokal prüfen, nicht in Logs
oder Chat kopieren. Eine Verwaltungsoberfläche für diese Sonderfälle ist noch
nicht enthalten.

## Prüfung

```sh
python3 -B -m unittest discover -s tests -p 'test_*sms*.py'
php -l telepraxis-sms-journal.php
php -l telepraxis-sms-receive-store.php
php -l telepraxis-sms-receive.php
php -l telepraxis-sms-worker.php
```

Die Tests verwenden ausschließlich temporäre Datenbanken und synthetische lokale
HTTP-Server. Sie prüfen auch Neustarts nach Veröffentlichung/Queue-Commit,
Dateikollisionen, App-Änderungen und Purge, UID-Wiederverwendung, Gerätewechsel,
deaktivierte/geänderte Antworten, Routerausfälle und Löschfehler. Die bestehende
App wurde mit einem tatsächlich vom Empfangsspeicher erzeugten Vorgang auf
Textanzeige, Antwortverlauf, Papierkorb und Restore geprüft. Der abschließende
Stand umfasst **146 SMS-/App-/Konfigurationstests**, einschließlich Zuordnung,
einmaliger Bestätigung, gemeinsamer Dateisperre, chronologischer Anzeige und
Benachrichtigungston. Zusammen mit 24 Updater-Tests bestand der vollständige
Veröffentlichungslauf alle **170 Tests**. Für die JavaScript-Prüfungen Node.js
im `PATH` bereitstellen.

## Freigegebene Installation und Einzelabnahme

Am 29.09.2026 hat der Nutzer die Installation und einen Live-Test ausschließlich
mit seiner im Chat genannten Nummer freigegeben. Die sechs geprüften Module
liegen unter `/opt/telepraxis-sms`, mit PHP-Syntaxprüfung auf dem Zielsystem und
Prüfsummenvergleich zur lokalen Quelle. Ein Systemkonto `telepraxis-sms` wurde
angelegt und der vorhandenen Kanalgruppe hinzugefügt. Die lokale Datenbank
liegt unter `/var/lib/telepraxis-sms/outbox.sqlite`; Verzeichnis `2770`, Datei
`0660`, Zugriff über Dienstkonto und Kanalgruppe. Der SSH-Home-Modus blieb `0710`.

Die Abnahme verwendete die installierten Funktionen unter diesem Dienstkonto
in getrennten PHP-CLI-Prozessen. Die vorhandenen Zugangsdaten wurden nur gelesen;
Testparameter und Zielnummer wurden ausschließlich im Arbeitsspeicher ergänzt.
Es entstand keine zusätzliche Credentials-Datei und kein gespeichertes
Testskript mit realer Nummer. Nur der exakt bekannte Testeingang wurde übernommen.
Alle Sendungen waren zusätzlich auf die Testnummer und die zwei Standardtexte
beschränkt. Es wurde kein allgemeiner Workerzyklus über ältere Eingänge gestartet.

Nachgewiesen wurden:

- Ein neuer Vorgang mit dem vollständigen, identischen 405-Zeichen-Text.
- Sichtbarkeit in der tatsächlich installierten App unter `www-data`, Status Neu.
- Genau zwei Antwortaufträge, beide vom Router als `accepted` bestätigt.
- Dauerhafte Archivierung des Eingangs und beider Ausgänge vor der Bereinigung.
- Alle drei Testkopien aus der Box entfernt, keine offenen Löschaufträge.
- Erneuter Start/Wiederaufnahme erzeugt weder zweiten Vorgang noch weitere Antworten.
- Alle 25 anderen Routereinträge anhand ihrer Fingerabdrücke unverändert.
- SQLite-Integritätsprüfung `ok`, keine fehlgeschlagenen/unsicheren Testsendungen.

Der Nutzer hat anschließend im Chat den Handyempfang bestätigt („die sms kam“).
Die Anzahl der empfangenen Antworten wurde dabei nicht gesondert genannt.
Die Testdatenbank und der Testvorgang bleiben als Nachweis erhalten.

Bei dieser Einzelabnahme waren noch keine systemd-Unit und Queue-Webanbindung
eingerichtet. Die drei vorhandenen Webdateien und die Konfiguration wurden
zunächst als unverändert bestätigt. Der anschließende Ausbau ist unten dokumentiert.

## Queue-Webupdate und laufender Ausgangsdienst

Auf den anschließenden Nutzerhinweis, dass „Queue“ in `sms-config.php` noch nicht
auswählbar war, wurde der zusammengehörige geprüfte Webstand installiert:
App, SMS-Bibliothek, Konfigurationsseite und Queue-Bibliothek. Die Serverdateien
wurden zuvor per PHP-Tokenvergleich als bekannte Ausgangsbasis bestätigt;
abweichend waren ausschließlich die vorgesehenen Installationskonstanten.
Der Updater bestand 24 lokale Tests und den Prüfmodus auf dem Zielserver.

Das Update übernahm Pfade und Adminpasswörter aus der Installation, erstellte
eine private Sicherung unter `/var/backups/kienzlefon-app` und startete nur den
zugehörigen Apache-Dienst neu. Die zweite Apache-Instanz verwendet einen
getrennten Webroot und blieb aktiv. Versionsnummern wurden nicht zusätzlich erhöht.

Die bestehende Konfiguration verwendet jetzt `default_provider: "queue"`,
`queue.delivery_provider: "fritz"` und die bereits getestete lokale Datenbank.
Zugangsdaten und andere bestehende Konfigurationsabschnitte blieben erhalten;
die vorherige Konfiguration wurde geschützt gesichert. `receive.enabled` ist
ausdrücklich `false`, damit keine automatischen Antworten auf weitere Eingänge
entstehen. Antworttexte werden dadurch nicht verändert.

`telepraxis-sms-worker.service` ist installiert, aktiviert und läuft mit
Autostart unter dem eigenen Dienstkonto. Der Dienst hat nur die vorgesehenen
Schreibfreigaben für Queue und Kanal-Inbox. Geprüft wurden ein stabiler Prozess
ohne Neustartschleife, eine Queue ohne offene Sendungen und beide Webdienste.
Die gerenderte Konfigurationsseite unter `www-data` bietet Queue an, zeigt Queue
als ausgewählten Standard und den korrekten Datenbankpfad ohne Fehlerbanner.
Die App zeigt beim vorhandenen Testvorgang beide Antworten mit Status `accepted`.
Beim Webupdate und Dienststart wurden keine weiteren Test-SMS beauftragt.
Im abschließenden Prüfzeitraum wurde ein zusätzlicher Auftrag an dieselbe
Testnummer erfolgreich vom laufenden Dienst verarbeitet; letzter Stand:
3 `accepted`, keine offenen/fehlgeschlagenen/unsicheren Sendungen und keine
offenen Löschaufträge. Dieser weitere Auftrag wurde nicht vom Agenten eingereiht.

## Aktivierter Empfang mit eingeschränkten Antworten

Anschließend hat der Nutzer den Empfang ausdrücklich freigegeben und Antworten
an kurze Netzbetreibernummern sowie ins Ausland ausgeschlossen. Die entsprechende
Prüfung wurde im Empfangsspeicher ergänzt, mit 32 Empfangstests erfolgreich
geprüft und bei gestopptem Dienst atomar installiert. Die Tests decken deutsche
nationale/internationale Schreibweisen, ausländische Nummern, Kurzwahlen und
die vollständige Vorgangsausgabe ohne Antwortauftrag für gesperrte Empfänger ab.

Vor der Aktivierung wurden Konfiguration und Datenbank konsistent geschützt
unter `/var/backups/telepraxis-sms` gesichert. Der aktuelle Routerbestand umfasste
27 Einträge: 19 empfangene und 8 gesendete SMS. Alle wurden dauerhaft archiviert,
die 19 Eingänge als Vorgänge ausgegeben und ihre Antwortliste als leer eingefroren.
Die Konfiguration der zwei künftigen Standardantworten blieb dabei unverändert;
es erfolgte kein nachträglicher Antwortversand an den Altbestand. Nach dem Import
wurde eine weitere konsistente Datenbanksicherung angelegt.

Danach wurde ausschließlich `receive.enabled` auf `true` gesetzt und der Dienst
gestartet. Der neue Prozess läuft ohne Neustartschleife. Neu eingehende vollständige
SMS werden automatisch als Vorgang übernommen; die zwei konfigurierten Antworten
werden nur bei erfüllter deutscher Rufnummernfreigabe eingereiht. Gesicherte
Routerkopien werden automatisch bereinigt. Ein erneutes Deaktivieren soll über
Konfiguration und Dienstneustart erfolgen: Die Empfangsdatenbank nach erfolgter
Routerbereinigung keinesfalls durch die Sicherung vor dem Erstimport ersetzen.

Abschließende Live-Prüfung unter dem gemeinsamen Worker-Lock: Routerjournal leer,
keine offenen/fehlgeschlagenen Löschungen, keine zurückgehaltenen oder ungeklärten
Eingänge und keine offenen/fehlgeschlagenen/unsicheren Sendungen. Die Versandzahl
blieb bei 11 angenommenen Aufträgen; der Erstimport löste keine Antwort aus.
Insgesamt sind 20 Eingänge ausgegeben (vorheriger Test plus 19 aus dem Altbestand).
Die Kurzwahl-/Auslandssperre wurde zusätzlich direkt auf dem Server mit
synthetischen Nummern ohne Versand bestätigt.

## Installierte Zuordnung weiterer SMS

Die oben beschriebene Zuordnung zu offenen Vorgängen und einmalige Bestätigung
sind anschließend gemeinsam mit der farbigen App-Anzeige installiert worden.
Vorher wurden die bestätigte Serverbasis verglichen, 145 SMS-Tests erfolgreich
ausgeführt und die PHP-/JavaScript-Syntax geprüft. Die Installation sicherte
Inbox, Konfiguration und SQLite konsistent bei gestoppten Diensten; private
Sicherungen liegen unter `/var/backups/telepraxis-sms/followup-*`.

Die Konfiguration blieb unverändert. Die additive Migration lief unter dem
Dienstkonto erfolgreich; Worker und beide Webdienste sind aktiv und fehlerfrei.
Prüfstand nach Installation: 24 ausgegebene Eingänge, 20 angenommene Sendungen,
keine offenen oder fehlgeschlagenen Aufträge. Keine nachträgliche Zusammenlegung.

Ein separater synthetischer Test direkt auf dem Server bestätigte die gemeinsame
Dateisperre und Schreibberechtigung der beiden echten Dienstkonten: erste SMS,
zwischenzeitliche Praxisnotiz durch die App, zweite SMS zum selben Vorgang,
weitere App-Bearbeitung. Ein Vorgang, zwei SMS-Kommentare und nur ein
Bestätigungsauftrag aus der einteiligen Testantwortliste; zweite Antwort
übersprungen. Kein echter Versand; temporäre Testdaten anschließend entfernt.
Offene Browser müssen für die neue Darstellung einmal neu geladen werden.

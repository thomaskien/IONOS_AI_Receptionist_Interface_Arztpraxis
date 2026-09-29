# Eigenständige SMS-Ausgangsqueue

Maßgeblich ist das IONOS-Repository; siehe [Repository-Zuordnung](SMS-REPOSITORIES.md).

`telepraxis-sms.php` unterstützt `none` (aus), `fritz`, `seven` und als dritten
aktiven Versandweg `queue`. Bestehende Konfigurationen behalten ihren Provider.
Die Queue wird erst mit `default_provider: "queue"` aktiviert.

Die Webanfrage speichert einen Auftrag in einer lokalen SQLite-Datenbank und
antwortet mit HTTP 202 und „SMS zum Versand vorgemerkt“. Der separate
PHP-CLI-Worker erledigt Anmeldung, TOTP und Versand. Er benötigt weder die
Telepraxis-Webapp noch Kienzlefon, Asterisk oder die KI-Laufzeit.

## Dateien und Voraussetzungen

- PHP 8.1 oder neuer mit PDO-SQLite; im Worker zusätzlich curl und OpenSSL.
- Webapp und Worker laufen auf demselben Server und greifen auf dieselbe lokale
  Datenbank zu. SQLite nicht auf NFS/SMB, einem Cloud-Sync-Verzeichnis oder über
  getrennte Container-Dateisysteme betreiben.
- Die Webapp benötigt `telepraxis-sms.php` und `telepraxis-sms-queue.php` neben
  ihrer App-Datei. Für den eigenständigen Worker diese beiden Dateien plus
  `telepraxis-sms-worker.php` nach `/opt/telepraxis-sms/` installieren.
  Bei aktiviertem Empfang kommen drei Module hinzu, siehe
  [SMS-Empfang und Routerjournal](SMS-EMPFANG.md). Empfang ist standardmäßig aus.
- Das Queue-Verzeichnis vorher anlegen. Es muss außerhalb des Webroots liegen,
  darf nicht öffentlich lesbar sein und muss für Webprozess und Worker lesbar
  und schreibbar sein (einschließlich SQLite-Journaldateien).
- Ein eigenes Dienstkonto `telepraxis-sms` und eine gemeinsame Gruppe sind
  vorgesehen. Beispielsweise `/var/lib/telepraxis-sms` mit Gruppe
  `telepraxis-sms` und Modus `2770`; Datenbank mit `0660`. Dem tatsächlichen
  PHP-Webprozess diese Gruppe zuweisen und seine Prozesse danach neu starten.
  Dienstkonten, Pfade und PHP-Binary an die konkrete Installation anpassen.

## SMS-Konfiguration der Webapp

In `sms-config.php` unter Standard-Provider „Queue“ auswählen und den lokalen
Datenbankpfad sowie FRITZ!Box oder seven.io als Zustellprovider eintragen. Der
Worker muss dieselbe Datenbank verwenden. Der Testversand über Queue merkt
nur einen Auftrag vor; er benötigt den laufenden Worker für die Zustellung.

Die Datei aus `TP_SMS_CREDENTIALS_FILE` wie bisher verwenden. Beispiel:

```json
{
    "default_provider": "queue",
    "queue": {
        "database_path": "/var/lib/telepraxis-sms/outbox.sqlite",
        "delivery_provider": "fritz",
        "busy_timeout_ms": 1000
    }
}
```

`queue.delivery_provider` kann `fritz` oder `seven` sein. Die Auswahl wird mit
jedem Auftrag gespeichert. `none` und `queue` sind als Zielprovider unzulässig.
Die Webapp benötigt im Queue-Modus keine Router- oder seven-Zugangsdaten.
Eine vollständige Vorlage liegt in `config/sms-queue-web.example.json`.

## Worker-Konfiguration und Start

`config/sms-queue-worker.example.json` als `/etc/telepraxis-sms/worker.json`
installieren und die vorhandenen funktionierenden FRITZ!Box-Einstellungen
übernehmen. Die Datei soll nur für das Dienstkonto lesbar sein, z.B. `0600`.
Das Verzeichnis selbst darf für den Webprozess gesperrt sein. Für seven den
vorhandenen `seven`-Abschnitt mit API-Schlüssel verwenden. Web- und
Worker-Konfiguration müssen denselben `queue.database_path` enthalten.

Soll `sms-config.php` auch die vom Worker verwendeten Zugangsdaten und
Antworttexte verwalten, den Worker mit `--config` auf dieselbe bestehende
SMS-Konfigurationsdatei außerhalb des Webroots verweisen. Das Dienstkonto
benötigt Leserechte; nach einer Änderung den Worker neu starten, da er seine
Konfiguration beim Start lädt. Eine zweite Worker-Konfigurationsdatei ist
optional: In diesem Fall werden Änderungen aus der Weboberfläche nicht
automatisch dorthin übertragen. Antworttexte und Routerzugang dort separat
aktualisieren. Die Auswahl des Zustellproviders wird beim Einreihen des Auftrags
aus der Web-Konfiguration gespeichert.

```sh
php /opt/telepraxis-sms/telepraxis-sms-worker.php --config /etc/telepraxis-sms/worker.json --init
php /opt/telepraxis-sms/telepraxis-sms-worker.php --config /etc/telepraxis-sms/worker.json --status
php /opt/telepraxis-sms/telepraxis-sms-worker.php --config /etc/telepraxis-sms/worker.json
```

Diese Befehle unter dem Dienstkonto ausführen. `--init` initialisiert die Queue,
`--status` zeigt nur Zähler und versendet nichts. Der letzte Befehl startet den
Dauerbetrieb. `--once` bearbeitet höchstens einen Sendeauftrag und einen fälligen
Löschauftrag und **kann eine echte SMS senden oder eine Routerkopie löschen**.
Bei aktiviertem Empfang führt derselbe Aufruf zusätzlich den Journalabgleich,
eine lokale Vorgangsausgabe und gegebenenfalls das Einreihen der ersten Antwortliste aus.
Die Vorlage `config/telepraxis-sms-worker.service` startet den
Worker unabhängig als systemd-Dienst. Erst nach Konfiguration und Einrichtung
der Dateirechte den Dienst aktivieren und die Webapp auf `queue` umstellen.

## Automatische Antwort als Nachrichtenliste

In der Worker-Konfiguration steuert `auto_reply.messages` die einzelnen SMS
der Eingangsbestätigung. Standard sind diese zwei Texte (63 und 70 Zeichen):

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

Jeder Listeneintrag ist eine eigenständige SMS. Die Reihenfolge entspricht der
Liste. Die Nummerierung ist Bestandteil der Texte und wird nicht automatisch
ergänzt. Eine eigene Liste ersetzt die Standardliste vollständig; ein einzelner
Eintrag ergibt eine SMS, weitere Einträge entsprechend mehr. Mit
`"messages": []` wird die automatische Antwort abgeschaltet. Die Längengrenze
wird pro SMS geprüft; ein langer Text wird nicht automatisch zerlegt.

Die Liste wird nur für die **erste eingegangene SMS je Vorgang** verwendet.
Weitere SMS derselben Nummer werden dem zuletzt angelegten offenen Vorgang
zugeordnet und dort als farbige Kommentare ergänzt, ohne erneute Bestätigung.
Abgeschlossene Vorgänge und Papierkorb werden nicht als Zuordnungsziel verwendet.
Details: [Weitere SMS zum offenen Vorgang](SMS-EMPFANG.md#weitere-sms-zum-offenen-vorgang).

`tp_sms_queue_auto_reply($settings, $recipient, $receivedMessageKey, $context)`
ist der vorbereitete Einstiegspunkt für den Empfangsdienst. Dieser darf ihn
erst nach dauerhafter Übernahme der empfangenen Nachricht in Telepraxis aufrufen.
Die stabile Empfangskennung muss Gerät und Nachricht eindeutig identifizieren;
Rufnummer oder SMS-Text allein reichen dafür nicht. Im optionalen Kontext kann
`source_file` die Telepraxis-Vorgangsdatei zuordnen.

Alle Teile werden in einer gemeinsamen Transaktion gespeichert. Ein ungültiger
Text oder ein Schreibfehler verwirft die gesamte neue Antwort. Wiederholte
Aufrufe mit derselben Empfangskennung liefern die vorhandenen Aufträge zurück;
auch nach einem Neustart werden bereits bearbeitete Teile nicht wiederholt.
Wird für dieselbe Kennung ein anderer Empfänger oder eine veränderte Antwort
angefordert, erfolgt eine Fehlermeldung statt einer weiteren Aussendung.
Der Worker bearbeitet die Teile der Reihe nach, mit getrenntem Status pro SMS.
Ein fehlgeschlagener Teil wird nicht automatisch wiederholt; die übrigen Teile
behalten ihre reguläre Queue-Verarbeitung.

Queue-Schema 3 ergänzt persistente Löschaufträge; Schema 2 ergänzte die Tabelle
für zusammengehörige Sendeaufträge. Vor einem
Update den Worker stoppen, Bibliotheken und Worker gemeinsam aktualisieren
und anschließend neu starten. Vorhandene Aufträge aus Schema 1 und 2 bleiben bei der
automatischen Migration erhalten; für eine Sicherung auch SQLite-WAL-Daten
berücksichtigen bzw. die SQLite-Backup-Funktion nutzen.

## Verhalten und Grenzen

Die Queue speichert Auftrags-ID, Empfänger, unveränderten Text, Zielprovider,
Vorgangsdatei, Arbeitsplatz und Status. Wiederholungen derselben Webanfrage
verwenden dieselbe Auftragskennung und erzeugen keinen zweiten Auftrag.
Bewusst erneut verfasste Nachrichten erhalten eine neue Kennung.

Der Versand läuft FIFO, ein Auftrag gleichzeitig pro Queue. Ein Prozess-Lock
verhindert doppelte Bearbeitung durch zwei gestartete Worker; Datenbank-
Transaktionen bleiben kurz und umfassen keine Routerzugriffe. Für dieselbe
FRITZ!Box nur eine Queue verwenden und alle automatischen Sender darüber
führen. Die unverändert verfügbaren direkten Provider umgehen diese Queue.

Telepraxis zeigt die Aufträge mit ihrem aktuellen Status im Kommentarbereich
der zugehörigen Karte; sie erscheinen auch beim Drucken und Kopieren. Der
Verlauf kommt aus der Queue und wird nicht zusätzlich in die Vorgangsdatei
geschrieben. Die Datenbank daher gemeinsam mit den Vorgängen sichern.
Löschen oder Abschließen einer Karte storniert einen vorgemerkten Auftrag
nicht. Eine Stornierungsoberfläche ist noch nicht enthalten.

| Status | Bedeutung |
| --- | --- |
| `pending` | Dauerhaft gespeichert, wartet auf den Worker |
| `sending` | Versand läuft |
| `accepted` | Provider hat den Versand bestätigt; kein Zustellnachweis |
| `failed` | Vor dem Transport gescheitert, bitte Konfiguration prüfen |
| `uncertain` | Versand möglicherweise erfolgt; vor erneutem Versand prüfen |

Bei Transportfehlern oder einem Absturz während des Versands wird ein Auftrag
konservativ auf `uncertain` gesetzt. Solche Aufträge werden **nicht automatisch
erneut gesendet**. Auch `failed` benötigt eine bewusste neue Beauftragung nach
Behebung des Fehlers. Damit kann ein Timeout keine automatische Doppel-SMS
auslösen.

### FRITZ!Box nach dem Versand bereinigen (ohne Empfang)

Der Queue-Worker löscht bekannte, von ihm erzeugte FRITZ!Box-Journaleinträge
nach Abschluss des Sendeversuchs. Die Router-ID wird bereits nach der ersten
Routerantwort dauerhaft als Löschauftrag gesichert, vor dem abschließenden
Sendeversuch. Dadurch bleibt sie auch bei einem späteren Transportfehler oder
Prozessabbruch erhalten. Zuerst wird der Sendezustand gespeichert, danach wird
in einer getrennten Sitzung gelöscht. Auch ein unsicherer Sendeversuch wird
bereinigt, sobald die Journal-ID bekannt ist.

Löschfehler bleiben als offene Aufträge erhalten und werden mit Wartezeit erneut
versucht. Das ändert den Sendestatus nicht und versendet keine weitere SMS.
`--status` zeigt dafür zusätzlich `cleanup_pending`. Der Host des Routers wird
im Auftrag festgehalten: Eine geänderte Host-Konfiguration blockiert das Löschen
auf einem anderen Ziel. Fehlerprotokolle enthalten weder SMS-Text noch Empfänger
oder Zugangsdaten.

Im Queue-Betrieb ist die Bereinigung fest vorgesehen. `delete_after_send` wird
nur innerhalb des einzelnen Sendeaufrufs vorübergehend deaktiviert, damit der
separate Löschauftrag die Bereinigung übernimmt. Beim direkten Versand ohne
Queue steuert die bisherige Einstellung weiterhin das sofortige Löschen;
Standard und Beispielkonfiguration verwenden `true`.

Die reine Ausgangsbereinigung erfasst nicht das gesamte SMS-Journal: Eingänge, ältere
Einträge ohne lokal gespeicherte Router-ID und Sendeversuche, bei denen keine ID
ankam, benötigen den optionalen [Empfangs-/Journalabgleich](SMS-EMPFANG.md). Dort
werden Rohdaten dauerhaft gesichert und Geräte- sowie Nachrichtenidentität vor
dem Löschen geprüft. Auch eine
nicht erreichbare FRITZ!Box lässt sich erst nach Wiederherstellung der Verbindung
bereinigen. Es werden keine bestehenden Eingänge blind gelöscht.

Die Queue enthält SMS-Texte und Telefonnummern als Klartext in ihrem
geschützten lokalen Verzeichnis. Sie ist kein Telepraxis-Transportdatensatz
und wird nicht mit dessen Public Key verschlüsselt, da der Worker den Inhalt
zum Versand lesen muss. Es gibt noch keine automatische Aufbewahrungsbereinigung.

Für FRITZ!Box gilt zusätzlich zur allgemeinen Textlänge eine feste Grenze von
70 UTF-16-Codeeinheiten (bei üblichen deutschen Texten 70 Zeichen). Längere
Texte werden bereits vor dem Routerzugriff bzw. vor Aufnahme in die Queue mit
einer Fehlermeldung abgelehnt. Bereits vorhandene zu lange Queue-Aufträge werden
ohne Versand auf `failed` gesetzt. Die Grenze gilt nicht für seven.io.

### Optionaler Empfang

Der Empfangsadapter ist implementiert und nach Freigabe als Worker-Module
installiert. Der begrenzte Live-Test ist erfolgreich. Der Queue-Ausgangsdienst
läuft inzwischen als systemd-Dienst mit Autostart; der automatische Empfang
ist ebenfalls freigegeben und aktiviert. Der Altbestand wurde ohne Antworten
übernommen. Automatische Antworten gehen ausschließlich an vollständige
deutsche Rufnummern, niemals an Kurzwahlen oder ausländische Nummern.
Er wird nur bei `receive.enabled: true` geladen. Ein normaler Start als
Sendeworker löst weiterhin keine Eingangsbestätigung aus. Formatnachweis,
Konfiguration, Dateirechte, Wiederanlauf und Erstimport sind in
[SMS-EMPFANG.md](SMS-EMPFANG.md) beschrieben.

Eine bekannte Lang-SMS mit 405 Zeichen kam beim lesenden Test auf der 6850 LTE
mit FRITZ!OS 8.25 bereits vollständig zusammengesetzt an. Unbekannte Segmentdaten
werden nicht geraten oder beantwortet. Das separate
[Diagnosewerkzeug](SMS-EMPFANG-DIAGNOSE.md) bleibt rein lesend.

## Lokale Prüfungen ohne echte SMS

```sh
php -l telepraxis-sms.php
php -l telepraxis-sms-queue.php
php -l telepraxis-sms-worker.php
php -l telepraxis-app.php
python3 tests/test_telepraxis_sms.py
python3 tests/test_telepraxis_sms_queue.py
python3 tests/test_telepraxis_sms_web_queue.py
python3 tests/test_telepraxis_sms_auto_reply.py
python3 tests/test_telepraxis_sms_cleanup.py
python3 tests/test_sms_config.py
python3 tests/test_telepraxis_sms_journal.py
python3 tests/test_telepraxis_sms_receive_store.py
python3 tests/test_telepraxis_sms_receive.py
```

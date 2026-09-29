# SMS-Empfang: lesende Formatdiagnose

Stand: 29.09.2026. Das Werkzeug `telepraxis-sms-inspect.php` wurde zunächst mit
einem synthetischen lokalen HTTP-Server und anschließend lesend an der 6850 LTE
mit FRITZ!OS 8.25 geprüft. Das bestätigte Format und der inzwischen lokal
implementierte Empfangsadapter sind in [SMS-EMPFANG.md](SMS-EMPFANG.md) beschrieben.

## Aufruf

Auf dem bereits für die FRITZ!Box eingerichteten Rechner werden das
Diagnoseskript und die vorhandene `telepraxis-sms.php` im selben Verzeichnis
benötigt. PHP mit curl, SimpleXML und UTF-16-Konvertierung wie für den bisherigen
Transport vorausgesetzt. Es wird keine Queue-Datenbank angelegt oder geöffnet.

```sh
php telepraxis-sms-inspect.php --help
php telepraxis-sms-inspect.php --config /etc/telepraxis-sms/worker.json
```

Der Konfigurationspfad ist ein Beispiel. Den tatsächlichen absoluten Pfad der
bereits funktionierenden Konfiguration verwenden. Das Werkzeug verlangt
`--config`; es fällt nicht auf die lokale `sms-credentials.json` zurück.
Zugangsdaten werden weder geändert noch ausgegeben. Für die Diagnose genügt der
vorhandene `fritzbox`-Abschnitt. Die Live-Diagnose wurde ohne dauerhafte
Skriptinstallation ausgeführt. Es erfolgten keine Sende- oder Löschaktionen.

Der Ablauf besteht aus Anmeldung, einem POST auf `data.lua` mit ausschließlich
`sid`, `page=smsList`, `xhr=1` und anschließender Abmeldung. Es werden keine
Sende-, Lösch-, Bestätigungs- oder TOTP-Aktionen aufgerufen. Aktualisierte SIDs
und Session-Cookies werden berücksichtigt. Die temporäre Cookie-Datei wird mit
Modus `0600` angelegt und bei regulärem Abschluss und abgefangenen Fehlern entfernt.
Das ist ein einmaliger Seitenabruf, kein vollständiger Journalabgleich.

## Ausgabe und Grenzen

Die JSON-Ausgabe enthält nur Strukturinformationen:

- Objekte, Listen, Reihenfolge und Anzahl der Listenelemente;
- bekannte mögliche Feldnamen; unbekannte Schlüssel werden zu `field-1` usw.;
- Datentypen und bei Zeichenketten die Länge in UTF-16-Codeeinheiten;
- `value-1` usw. für gleiche Werte innerhalb genau dieses Abrufs.

Auch Zahlen und Wahrheitswerte werden durch Vergleichskennungen ersetzt.
Nachrichten, Telefonnummern, Zeitangaben, Geräte-/Nachrichtenkennungen,
Hostnamen und Routerfehlermeldungen werden nicht als Werte ausgegeben.
Sitzungs- und Authentifizierungsfelder werden vollständig ausgelassen. Es gibt
keinen Rohdatenexport. Fehler liefern nur die fehlgeschlagene Phase und Exit 1.
`--help` arbeitet ohne Konfiguration oder Netzwerkzugriff.

Die Liste bekannter Feldnamen ist ausschließlich eine Ausgabefreigabe. Sie ist
**kein Nachweis**, dass diese Felder auf der FRITZ!Box existieren oder eine
bestimmte Bedeutung haben. Die Ausgabe setzt `multipart_verified: false`.
Gleiche Vergleichskennungen zeigen lediglich gleiche Werte innerhalb eines
Abrufs; sie sind keine dauerhaften Empfangskennungen. Unbekannte Schlüssel
bleiben absichtlich anonymisiert. Bei Bedarf muss ein konkret benötigtes
Metadatenfeld nach Prüfung gezielt freigegeben werden.

Damit lässt sich zunächst feststellen, ob eine Liste, Nachrichtentexte und
potenzielle Teilinformationen vorhanden sind. Zum Nachweis von Teilenummer,
Gesamtzahl, stabiler Geräte-/Nachrichtenkennung und Wiederverwendung von IDs
reicht diese Diagnose allein nicht. Dafür sind anschließend gezielte Prüfungen
mit nicht vertraulichen Testnachrichten und bestätigter Feldsemantik nötig.
Nichts anhand von Absender und Zeit allein zusammensetzen.

Die [FRITZ!-Dokumentation zum SMS-Empfang und -Versand](https://fritz.com/apps/knowledge-base/FRITZ-Box-6850-5G/4142_sms-mit-fritz-box-empfangen-und-senden)
beschreibt ein Journal mit bis zu 100 Nachrichten, dokumentiert aber kein
Multipart-JSON-Schema. Sie ersetzt daher die Formatprüfung am Gerät nicht.

## Anschluss an den Empfangsadapter

Server, vorhandene Konfiguration und lokale Ziel-Inbox wurden vom Nutzer im
Chat benannt. Zugangsdaten und private Serverdetails bleiben außerhalb dieser
Dokumentation. Der bestätigte POST liefert `data.smsListData.messages` mit
`sender`/`receiver`, `answerable`, `status`, `date`, `text`, `uid`, `status_name`
und bei Ausgängen `ref`. Eine bekannte lange Test-SMS mit 405 Zeichen wurde
genau einmal und vollständig identisch als `received` gefunden. Die Box hat
diesen Text bereits zusammengesetzt; eigene Segmentverkettung ist dafür nicht
erforderlich. Die Roh-Seriennummer aus `jason_boxinfo.xml` wird nur im Speicher
zur Bildung der gehashten Gerätekennung verwendet.

Beim Review des vorherigen Queue-Stands wurden diese Integrationsgrenzen
bestätigt und im neuen Empfangsspeicher berücksichtigt:

- `sms_queue_cleanup` benötigt einen Ausgangsauftrag als Fremdschlüssel. Eingänge
  brauchen einen eigenen persistenten Übernahme-/Löschstatus.
- `auto_reply.messages: []` erzeugt absichtlich keinen Queue-Eintrag. Der
  Empfangsspeicher muss deshalb auch „ohne Antwort verarbeitet“ dauerhaft
  markieren. Ein späterer Neustart oder eine geänderte Antwortliste darf diesen
  Eingang nicht erneut als neu behandeln.
- Das bisherige Cleanup prüft den konfigurierten Hostnamen, keine Geräteidentität.
  Ein Routerwechsel unter gleichem Namen wird damit nicht erkannt. Eine
  Gerätebindung und der erneute Vergleich des gesamten Nachrichtenfingerabdrucks
  sichern daher die Bereinigung im aktivierten Empfangsmodus ab. Die UID allein
  ist keine dauerhafte Empfangskennung.

## Tests

```sh
php -l telepraxis-sms-inspect.php
python3 tests/test_telepraxis_sms_inspect.py
```

Die sieben Tests verwenden nur synthetische Daten und einen Server auf
`127.0.0.1`. Sie prüfen unter anderem exakte Leseanfragen ohne Senden/Löschen,
Sessionwechsel, Anonymisierung einschließlich dynamischer Schlüssel,
Textlängen, Fehlerpfade, Cookie-Bereinigung und unveränderte Konfiguration.
Eine Sandbox muss dafür das Binden des lokalen Testports erlauben.

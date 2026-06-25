# Uebergabe API

Stand: 2026-06-25

## Ziel

Der IONOS KI-Telefonassistent sendet strukturierte Anliegen per HTTP-POST an einen eigenen PHP-Endpoint. Jede Anfrage wird als einzelne JSON-Datei gespeichert und spaeter von der Telepraxis-App verarbeitet.

Der kanalbezogene Endpoint hat das Schema:

```text
https://###servername###/telepraxis-receive-###ssh-benutzer###.php
```

Jeder Abrufkanal verwendet eine eigene Empfangsdatei mit Bindestrich vor dem SSH-Benutzernamen. Beispiel fuer den SSH-Benutzer `dahl`: `telepraxis-receive-dahl.php`.

## Sicherheitsnotiz

Die Ursprungssuebergabe enthielt einen konkreten produktiven `X-TP-Token`. Dieser Wert wird hier bewusst nicht dauerhaft dokumentiert. Fuer uebertragbare Vorlagen und Repo-Dokumentation gilt:

```text
###CHANGE_ME_LONG_RANDOM_SECRET###
```

Echte Secrets muessen geschuetzt und ausserhalb oeffentlich teilbarer Dokumentation behandelt werden.

## IONOS-Architektur

Jedes IONOS-Tool sendet JSON per POST an:

```text
https://###servername###/telepraxis-receive-###ssh-benutzer###.php
```

Header:

```text
Content-Type: application/json
X-TP-Token: ###CHANGE_ME_LONG_RANDOM_SECRET###
```

Der PSK muss serverseitig identisch in der kanalbezogenen Empfangsdatei `telepraxis-receive-<ssh-benutzer>.php` oder spaeter besser in einer geschuetzten Config/Environment-Quelle hinterlegt sein.

IONOS hat offenbar keine dokumentierten automatischen Kontextvariablen fuer Call-ID/Caller-ID. Das Feld `id` muss daher als Tool-Parameter gefuehrt werden.

Aktuelle Feldregel:

- `id`: uebermittelte Anrufernummer / Caller-ID
- `telefon`: vom Anrufer aktiv genannte oder bestaetigte Rueckrufnummer
- `zusammenfassung`: vom Modell erzeugt; 1-3 Saetze bei einfachen Faellen, bis 5 Saetze bei komplexeren Faellen; nur genannte Fakten, keine Annahmen

Aus einer IONOS-Mail mit `id: None` und separater IONOS-interner Mail-ID folgt: Die interne IONOS-Mail-ID steht vermutlich nicht als Tool-Kontextvariable zur Verfuegung.

## Empfangsdatei

Datei:

```text
/var/www/html/telepraxis-receive-<ssh-benutzer>.php
```

### IONOS-Requests

- Authentifizierung ausschliesslich ueber statischen Header `X-TP-Token`.
- IONOS soll kein Rate-Limit erhalten.

### Webformular-Requests

`kontakt-<ssh-benutzer>.php` sendet:

```json
{
  "id": "web-formular",
  "otp": "..."
}
```

Webformular-Requests benoetigen:

- OTP, gueltig fuer 24 Stunden,
- OTP nur einmal nutzbar,
- Rate-Limit maximal 20 Requests je IP innerhalb von 10 Minuten,
- bei Ueberschreitung HTTP 429 mit verstaendlicher JSON-Meldung.

Historische einfache Pfade, nicht mehr Zielmodell:

```text
/srv/telepraxis/otp.sqlite
/srv/telepraxis/inbox
```

Im Mehrbenutzerbetrieb gelten kanalbezogene Pfade:

```text
/srv/telepraxis/state/<ssh-benutzer>/otp.sqlite
/srv/telepraxis/<ssh-benutzer>/inbox
```

PHP SQLite war zunaechst Ursache eines HTTP-500; `php-sqlite3`/PDO SQLite musste installiert werden.

## Erwartetes gespeichertes JSON

Jeder Eingang soll eine einzelne Datei erzeugen:

```text
YYYYMMDD_HHMMSS_<zufall>.json
```

Beispielstruktur:

```json
{
  "received_at": "2026-06-25T12:34:56+02:00",
  "remote_ip": "...",
  "user_agent": "...",
  "typ": "ueb_req",
  "payload": {
    "typ": "ueb_req",
    "id": "+492331...",
    "telefon": "+492331...",
    "zusammenfassung": "...",
    "vorname": "...",
    "nachname": "..."
  }
}
```

Keine Whitelist fuer `typ` verwenden. Alle eingehenden Typen sollen gespeichert werden.

PHP-inhaltlich sollen keine Pflichtfelder geprueft werden; es reicht, wenn wenigstens irgendein Nutzfeld befuellt ist. Die IONOS-Tool-Definitionen steuern die Pflichtfelder.

## Aktuelle Request-Typen und Felder

`rezeptbestellung`:

```text
typ, id, telefon, zusammenfassung, vorname, nachname, geburtsdatum, medikamente
```

`ueb_req`:

```text
typ, id, telefon, zusammenfassung, vorname, nachname, geburtsdatum, fachrichtung, grund
```

`rueckruf_min`:

```text
typ, id, telefon, zusammenfassung
```

`rueckruf_tel_grund`:

```text
typ, id, telefon, grund, zusammenfassung
```

`rueckruf_details`:

```text
typ, id, telefon, zusammenfassung, vorname, nachname, geburtsdatum, grund
```

`sonstiges`:

```text
typ, id, telefon, anliegen, zusammenfassung
```

`fallback_name_tel_grund`:

```text
typ, id, telefon, name, grund, zusammenfassung
```

`fallback_vn_nn_grund`:

```text
typ, id, telefon, vorname, nachname, grund, zusammenfassung
```

`fallback_id_zusammenfassung`:

```text
typ, id, zusammenfassung
```

Zusaetzlicher dringender Fallback:

- Toolname: `sonstiges_dringend_fehlleitung`
- gesendeter `typ`: `sonstiges`
- Felder: `typ, id, telefon, grund, zusammenfassung`
- Zweck: fehlgeschlagene Weiterleitung bei Notfall oder dringendem Anruf

## IONOS-Tool-Regeln

Alle Beschreibungen beginnen kuenftig exakt mit:

```text
IMMER verwenden wenn ...
```

Die IONOS-Tools verwenden:

```text
https://###servername###/telepraxis-receive-###ssh-benutzer###.php
```

Header:

```json
{ "name": "X-TP-Token", "value": "###CHANGE_ME_LONG_RANDOM_SECRET###" }
```

## Webformular

Datei:

```text
/var/www/html/kontakt-<ssh-benutzer>.php
```

Verhalten:

- erzeugt beim Oeffnen ein OTP,
- OTP ist 24 Stunden gueltig,
- OTP nur einmal nutzbar,
- sendet same-origin an `/telepraxis-receive-<ssh-benutzer>.php`,
- `id` ist hart codiert als `web-formular`,
- kein Caller-ID-Feld im Formular,
- `telefon` ist notwendig.

Auswahl:

- Rueckrufbitte
- Ueberweisung
- Rezept
- Termin
- Sonstiges

Termin wird beim Senden als `typ = sonstiges` und `grund = Terminwunsch` uebermittelt.

Die Gespraechszusammenfassung ist im Formular verborgen und wird clientseitig aus eingegebenen Feldern gebildet.

Nach Erfolg:

- Anzeige `Erfolgreich gespeichert`
- `ok=true`
- Senden-Knopf bleibt deaktiviert/grau

Bei Fehler:

- Anzeige `Fehlgeschlagen`
- Fehlermeldung/Grund aus Serverantwort anzeigen

Bei HTTP 429 sinngemaess:

```text
Senden NICHT Erfolgreich: Empfang begrenzt auf maximal 20 Nachrichten in 10 Minuten, bitte warten und dann nochmal absenden.
```

## Offene Entwicklungsaufgaben

`telepraxis-receive.php` als Vorlage und die installierten `telepraxis-receive-<ssh-benutzer>.php`-Dateien pruefen und gegebenenfalls sauber konsolidieren:

- IONOS PSK ohne Rate-Limit,
- Webformular OTP plus 20/10-min Rate-Limit,
- sichere Dateischreibung via Temp-Datei und `rename`,
- keine Detailfehler nach aussen leaken, insbesondere keine PHP-/SQLite-Ausnahmen.

`kontakt.php` konservativ verbessern:

- Erfolgs-/Fehleranzeige pruefen,
- OTP nach Fehler weiterhin nutzbar lassen, sofern der Server es nicht als benutzt markiert hat,
- bei Erfolg Senden deaktiviert lassen,
- keine E-Mail-Felder ergaenzen.

Spaeteres Ziel:

- Telepraxis-App soll die kanalbezogene lokale Inbox `/srv/telepraxis/<ziel-benutzer>/inbox/*.json` einlesen.
- Felder robust darstellen, weil verschiedene `typ` unterschiedliche Feldmengen liefern.
- Bei aelteren Beispielen kann statt `payload.id` auch `anrufer_id` vorkommen; Parser sollte beides tolerant lesen.

Sicherheit spaeter pruefen:

- PSK nicht dauerhaft im PHP-Quellcode lassen, besser per geschuetzter Config/Environment-Datei.
- Moegliche Rotation des PSK.
- Optional Reverse-Proxy-/Webserver-Limits zusaetzlich zu PHP-Rate-Limit.

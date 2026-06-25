# Uebergabe Empfangsmodul

Stand: 2026-06-25

## Ziel

Das System transportiert eingehende JSON-Daten vom Quellsystem zum Zielsystem, ohne dass die Daten auf dem Quellsystem im Klartext liegen.

- Quellsystem: nimmt POST-JSON entgegen, authentifiziert den Request und speichert nur verschluesselte Dateien.
- Zielsystem: holt `*.enc` per SSH/SCP, entschluesselt lokal, validiert Inhalt und loescht danach lokale und remote verschluesselte Zwischenfiles.
- Mehrbenutzerbetrieb: Jeder Abrufkanal bekommt einen eigenen SSH-Benutzer auf dem Quellsystem.

## Schluesselarten

Es gibt zwei getrennte Schluesselarten:

- SSH-Key: nur fuer Abruf und Loeschen per SSH/SCP.
- RSA-Keypaar fuer Inhaltsverschluesselung: Public Key im PHP-Script auf dem Quellsystem, Private Key nur auf dem Zielsystem.

## Quellsystem

Das PHP-Empfangsmodul nimmt POST-JSON entgegen.

Authentifizierung:

- IONOS: statischer PSK im Header `X-TP-Token`.
- Webformular: `id == "web-formular"` mit OTP und Rate-Limit.

Speicherung:

- JSON wird nicht im Klartext gespeichert.
- PHP verwendet `openssl_seal()`.
- Geschrieben wird ein JSON-Wrapper mit Feldern wie `v`, `created_at`, `cipher`, `sha256`, `ek`, `iv`, `ct`.
- Dateiendung: `*.json.enc`.

## Zielsystem

Das Fetch-Script:

- holt `*.enc` vom Quellsystem,
- entschluesselt `ek` mit dem RSA-Private-Key,
- entschluesselt `ct` mit dem daraus gewonnenen AES-Key,
- prueft SHA256,
- validiert JSON,
- schreibt das entschluesselte `*.json` atomisch,
- loescht danach lokale `.enc` und remote `.enc`.

Polling ist aktuell bewusst simpel mit 5 Sekunden vorgesehen.

## Zentraler Architekturpunkt

Es gab ein reales Problem: SSH funktionierte oder OTP/SQLite funktionierte, aber nicht beides gleichzeitig.

Ursache:

- SSH mit `StrictModes` verlangt strikte Rechte im Home- und `.ssh`-Bereich.
- SQLite/PHP braucht Schreibrechte im Datenbereich.

Loesung:

- SSH-Home streng halten.
- Schreibbare App-Datenbereiche getrennt fuehren.
- Gemeinsame Gruppe `tp-<ssh-benutzer>` fuer SSH-Benutzer und `www-data` verwenden.
- php-fpm nach relevanten Gruppen-/Rechteaenderungen neu starten.

## Aktuelle Basisdateien

Quellsystem:

- `quellserver-vorbereiten-nginx-v1.2.sh`
- `quellserver-benutzer-erzeugen-v1.2.sh`

Zielsystem:

- `zielserver-vorbereiten-v1.2.sh`

Weitere relevante Dateien:

- `telepraxis-receive.php`
- `telepraxis-app.php`
- `telepraxis_fetch_and_decrypt.sh`

Die v1.2-Skripte sind der aktuelle Arbeitsstand und sollen als Basis fuer Weiterarbeit gelten.

## Skriptlogik

### `quellserver-vorbereiten-nginx-v1.2.sh`

Soll ein Quellsystem mit nginx vorbereiten:

- notwendige Pakete installieren,
- nginx, certbot und php-fpm einrichten,
- FQDN interaktiv abfragen,
- Let's Encrypt korrekt einrichten,
- auf Jitsi/nginx-Rahmenbedingungen Ruecksicht nehmen.

Wichtig:

- Kein generisches `php`-Metapaket installieren, weil es ungewollt Apache nachziehen kann.
- Stattdessen gezielt `php-fpm`, `php-cli`, `php-curl`, `php-sqlite3`.
- OpenSSL in PHP pruefen, aber kein separates `php-openssl`-Paket erwarten.

### `quellserver-benutzer-erzeugen-v1.2.sh`

Richtet pro Abrufkanal einen separaten SSH-Benutzer auf dem Quellsystem ein.

Fragt interaktiv ab:

- SSH-Benutzername,
- SSH-Public-Key,
- Public Key fuer JSON-Verschluesselung,
- IONOS-Secret manuell oder automatisch erzeugt.

Soll dann:

- Benutzer anlegen,
- Verzeichnisstruktur anlegen,
- `authorized_keys` setzen,
- PHP-Datei von GitHub bootstrappen,
- PHP-Zieldatei als `/var/www/html/telepraxis-receive<ssh-benutzer>.php` schreiben,
- `$INBOX_DIR`, `$OTP_DB`, `$IONOS_PSK`, `$PUBLIC_KEY_PEM` patchen,
- erzeugtes IONOS-Secret am Ende auf der Konsole ausgeben.

### `zielserver-vorbereiten-v1.2.sh`

Richtet das Zielsystem pro Benutzer ein.

Fragt interaktiv ab:

- lokaler Zielbenutzer,
- Quellserver-Host,
- SSH-Benutzer auf dem Quellsystem,
- optional Port.

Soll dann:

- Benutzer anlegen,
- SSH-Key erzeugen,
- RSA-Keypaar fuer JSON-Entschluesselung erzeugen,
- `telepraxis-app.php` bootstrappen,
- `telepraxis_fetch_and_decrypt.sh` bootstrappen,
- Fetch-Script patchen,
- systemd-Dienst einrichten, der unter diesem Benutzer laeuft,
- SSH-Public-Key und RSA-Public-Key am Ende ausgeben.

## Pfadlogik

Quellsystem pro Kanal:

- SSH-Home: `/srv/telepraxis/<ssh-benutzer>`
- Inbox: `/srv/telepraxis/<ssh-benutzer>/inbox`
- PHP-Datei: `/var/www/html/telepraxis-receive<ssh-benutzer>.php`
- OTP/State: getrennt vom eigentlichen SSH-Kern

Zielsystem pro Zielbenutzer:

- Basis: `/srv/telepraxis/<ziel-benutzer>`
- lokale verschluesselte Zwischenablage: `/srv/telepraxis/<ziel-benutzer>/.encrypted`
- lokale entschluesselte Inbox: `/srv/telepraxis/<ziel-benutzer>/inbox`
- SSH-Key: `/srv/telepraxis/<ziel-benutzer>/.ssh/telepraxis_fetch_key`
- RSA-Private-Key: `/srv/telepraxis/<ziel-benutzer>/telepraxis_decrypt_private.pem`
- RSA-Public-Key: `/srv/telepraxis/<ziel-benutzer>/telepraxis_decrypt_public.pem`
- Web-App: pro Benutzer getrennt, damit Mehrbenutzerbetrieb sich nicht ueberschreibt

## Bekannte Problemstellen

- Apache wurde ungewollt durch generische PHP-Metapakete installiert.
- `php-openssl` existiert unter Debian/Ubuntu meist nicht als separates Paket; OpenSSL-Support per `php -m` pruefen.
- SQLite-Fehler `unable to open database file` deutet oft auf Pfad-/Rechteprobleme, relative Pfade oder fehlenden php-fpm-Neustart nach Gruppenaenderungen.
- SSH-Key-Login scheitert typischerweise an falschem `authorized_keys`, falschem Home oder zu offenen/falschen Rechten.
- OTP/SQLite und SSH-StrictModes duerfen nicht durch lockere Home-Rechte gegeneinander ausgespielt werden.

## Naechste Pruefpunkte

- Alle drei Install-Skripte gemeinsam gegen reale Zielpfade testen.
- Quellsystem-Rechte, OTP-DB-Pfad, Gruppenmitgliedschaften und php-fpm-Neustarts pruefen.
- Mehrbenutzerbetrieb mit mindestens zwei getrennten Benutzern/Kanaelen testen.
- Pruefen, ob `telepraxis-app.php` in der finalen Mehrbenutzerform pro Benutzer sauber funktioniert.
- Optional Logging, Health-Checks, systemd-Diagnoseausgaben und Remote-Loesch-Fail-Szenarien verbessern.

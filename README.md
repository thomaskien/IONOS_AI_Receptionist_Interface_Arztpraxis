# telepraxis-app

Kompakte Ein-Datei-Webapp zur Bearbeitung eingehender JSON-Vorgänge aus dem Verzeichnis `./inbox`.

<img src="Screenshot 2026-03-31 at 13-40-44 telepraxis-app v2.1.png" alt="drawing" width="1000"/>


## Benutzungskonzept

Die App ist für mehrere Arbeitsplätze gedacht:

- **Neu**: neue eingegangene Vorgänge
- **In Bearbeitung**: nur die Vorgänge des aktuell eingestellten eigenen Arbeitsplatzes
- **Abgeschlossen**: erledigte Vorgänge
- **Papierkorb**: gelöschte Vorgänge, nur mit Admin-Funktion sichtbar

Ein Arbeitsplatz wird oben eingetragen und lokal im Browser gespeichert. Dadurch sieht jeder Arbeitsplatz links nur seine eigenen bearbeiteten Vorgänge, während Vorgänge anderer Arbeitsplätze weiter in der mittleren Spalte sichtbar bleiben.

Die App lädt die Daten regelmäßig neu und eignet sich damit für den laufenden Einsatz im Praxisalltag.

## Wichtige Funktionen

- Einlesen von JSON-Dateien aus `./inbox`
- Statuswechsel: **Neu**, **In Bearbeitung**, **Abgeschlossen**
- Markierung **Dringend**
- Soft-Delete in den Papierkorb
- Admin-Funktionen für **Wiederherstellen** und **endgültiges Löschen**
- Polling-Aktualisierung alle 5 Sekunden
- Benachrichtigungston bei neu erkannten Eingängen
- Klick auf den Namen kopiert `Nachname, Vorname JJJJ`
- Klick auf das Geburtsdatum kopiert das Geburtsdatum
- Gesprächszusammenfassung in Bearbeitung ein- und ausklappbar
- Geöffnete Zusammenfassungen bleiben trotz Refresh erhalten
- Telefonnummern sind direkt anklickbar
- Übermittelte Telefonnummer wird zusätzlich angezeigt
- Lokale Speicherung von Arbeitsplatz, Ton, Sichtbarkeit von Abgeschlossen und Papierkorb
- **Kontaktformular mit identischem Endpunkt**

<img src="Screenshot 2026-04-01 at 09-03-13 Telepraxis Kontakt.png" alt="drawing" width="600"/>



## Unterstützte Inhalte

Die App unterstützt die aktuell besprochenen Request-Typen des Telefonassistenten, darunter insbesondere:

- Rückruf
- Sonstiges
- Rezeptbestellung
- Überweisung
- Fallback-Typen mit reduzierten Angaben

## Technische Hinweise

- Datei: `telepraxis-app.php`
- Zeitzone: `Europe/Berlin`
- Standard-Polling: `5000 ms`
- Admin-Passwort aktuell fest im PHP-Code definiert und sollte angepasst werden

## Kurzablauf

1. `telepraxis-app.php` im Webroot ablegen
2. Unterhalb davon ein Verzeichnis `inbox` mit den JSON-Dateien bereitstellen
3. App im Browser öffnen
4. Arbeitsplatz eintragen
5. Vorgänge bearbeiten, abschließen oder löschen

# sicherheit
- OTP wird durch kontakt.php generiert und kann nur einmal verwendet werden
<pre>
pt-get update
apt-get install -y php-sqlite3
systemctl restart apache2
</pre>
- IONOS kontaktiert per
<pre>
IONOS Tool Header (statisch):

Name: X-TP-Token
Value: der Wert aus $IONOS_PSK (nachdem du CHANGE_ME... ersetzt hast)
</pre>

# telepraxis – verschlüsselter JSON-Transport

## Systemaufbau

Das System besteht aus zwei Seiten:

### 1. Quellserver
Auf dem Quellserver nimmt `telepraxis-receive-encrypted.php` JSON per HTTP-POST entgegen.  
Die Daten werden **nicht im Klartext gespeichert**, sondern direkt in PHP mit einem fest eingebetteten **Public Key** verschlüsselt und als Datei im Inbox-Verzeichnis abgelegt.

Beispiel:
- PHP-Datei: `/var/www/html/telepraxis-receive-encrypted.php`
- Ablage: `/srv/telepraxis/inbox/*.json.enc`

### 2. Zielsystem
Das Zielsystem besitzt den zugehörigen **Private Key**.  
Ein Shell-Script holt die verschlüsselten Dateien regelmäßig per **SCP/SSH** vom Server, entschlüsselt sie lokal und legt daraus wieder normale JSON-Dateien ab.

Beispiel:
- geholt von: `root@#servername#:/srv/telepraxis/inbox/`
- lokal entschlüsselt nach: `/Volumes/webroot/inbox/`

## Sicherheitskonzept

Es werden **zwei getrennte Schlüsselarten** verwendet:

### Inhaltsverschlüsselung
- **Public Key** liegt im PHP-Script
- **Private Key** liegt nur auf dem Zielsystem

Damit können die Dateien bereits auf dem Quellserver nur verschlüsselt gespeichert werden.

### Transport / Zugriff
Zusätzlich kann für SCP/SSH ein **separater SSH-Key** verwendet werden.  
Dieser dient nur zum Holen und Löschen der Dateien, nicht zur Entschlüsselung des Inhalts.

## Dateiformat

Die gespeicherte Datei ist ein JSON-Wrapper mit verschlüsseltem Inhalt, z. B. mit diesen Feldern:

- `cipher`
- `ek`
- `iv`
- `ct`
- `sha256`

Der eigentliche Nutzinhalt steckt verschlüsselt in `ct`.

## Ablauf

1. Client sendet JSON an PHP
2. PHP validiert den Request
3. PHP erzeugt einen Datensatz mit Metadaten
4. PHP verschlüsselt den Datensatz direkt mit dem Public Key
5. PHP speichert eine Datei `*.json.enc`
6. Zielsystem holt die Datei per SCP
7. Zielsystem entschlüsselt lokal mit dem Private Key
8. Zielsystem prüft Hash und JSON-Gültigkeit
9. Zielsystem schreibt die entschlüsselte JSON-Datei atomisch
10. Danach wird die verschlüsselte Datei lokal und auf dem Server gelöscht

## Funktionen des Fetch-Scripts

Das Shell-Script kann:

- Server, Benutzer, Pfade und Ports im Header konfigurieren
- optional einen eigenen SSH-Key verwenden
- verschlüsselte Dateien per SCP holen
- lokal entschlüsseln
- SHA-256 prüfen
- JSON validieren
- erst nach erfolgreicher Verarbeitung löschen
- einmalig oder im Polling-Betrieb laufen, z. B. alle 5 Sekunden

## Ziel des Aufbaus

Das Ziel ist, dass sensible JSON-Daten:

- **auf dem Quellserver nicht im Klartext liegen**
- **nur auf dem Zielsystem entschlüsselt werden**
- **nach erfolgreicher Verarbeitung automatisch entfernt werden**


# zertifikate erstellen und fetch einrichten

<pre>
#server:
sudo apt install php-openssl
sudo mkdir -p /srv/telepraxis/inbox
sudo chown -R www-data:www-data /srv/telepraxis/inbox
sudo chmod 770 /srv/telepraxis/inbox
  
  
#client:
sudo openssl genpkey \
  -algorithm RSA \
  -pkeyopt rsa_keygen_bits:4096 \
  -out telepraxis_decrypt_private.pem
sudo openssl pkey \
  -in telepraxis_decrypt_private.pem \
  -pubout \
  -out telepraxis_decrypt_public.pem
chmod +x telepraxis_fetch_and_decrypt.sh
./telepraxis_fetch_and_decrypt.sh

</pre>


# ionos-rezepte für die API

- bitte einzeln einkopieren

<pre>
[
  {
    "name": "rezeptbestellung",
    "description": "IMMER verwenden wenn ein Anrufer ein Rezept bestellen möchte. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
    "parameters": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
        "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückfragen (notwendig, ggf. bestätigen/erfragen)" },
        "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." },
        "vorname": { "type": "string", "description": "Vorname" },
        "nachname": { "type": "string", "description": "Nachname" },
        "geburtsdatum": { "type": "string", "description": "Geburtsdatum" },
        "medikamente": { "type": "string", "description": "Alle gewünschten Medikamente als Freitext (gern mit Stärke), mehrere möglich" }
      },
      "required": ["id", "telefon", "zusammenfassung", "vorname", "nachname", "geburtsdatum", "medikamente"]
    },
    "request": {
      "method": "POST",
      "url": "https://##servername##/telepraxis-receive.php",
      "headers": [{ "name": "Content-Type", "value": "application/json" }],
      "queryString": [],
      "postData": {
        "mimeType": "application/json",
        "text": "{\"typ\":\"rezeptbestellung\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\",\"vorname\":\"{{ vorname }}\",\"nachname\":\"{{ nachname }}\",\"geburtsdatum\":\"{{ geburtsdatum }}\",\"medikamente\":\"{{ medikamente }}\"}"
      }
    }
  },
  {
    "name": "ueb_req",
    "description": "IMMER verwenden wenn ein Anrufer eine Überweisung anfragt. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Erfasse außerdem Vorname, Nachname, Geburtsdatum, gewünschte Fachrichtung, Grund und eine Zusammenfassung des Gesprächs.",
    "parameters": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
        "telefon": { "type": "string", "description": "Ggf. zusätzlich genannte Telefonnummer für Rückfragen (notwendig, ggf. bestätigen/erfragen)" },
        "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." },
        "vorname": { "type": "string", "description": "Vorname" },
        "nachname": { "type": "string", "description": "Nachname" },
        "geburtsdatum": { "type": "string", "description": "Geburtsdatum" },
        "fachrichtung": { "type": "string", "description": "Gewünschte Fachrichtung" },
        "grund": { "type": "string", "description": "Kurzer Grund für die Überweisung" }
      },
      "required": ["id", "telefon", "zusammenfassung", "vorname", "nachname", "geburtsdatum", "fachrichtung", "grund"]
    },
    "request": {
      "method": "POST",
      "url": "https://##servername##/telepraxis-receive.php",
      "headers": [{ "name": "Content-Type", "value": "application/json" }],
      "queryString": [],
      "postData": {
        "mimeType": "application/json",
        "text": "{\"typ\":\"ueb_req\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\",\"vorname\":\"{{ vorname }}\",\"nachname\":\"{{ nachname }}\",\"geburtsdatum\":\"{{ geburtsdatum }}\",\"fachrichtung\":\"{{ fachrichtung }}\",\"grund\":\"{{ grund }}\"}"
      }
    }
  },
  {
    "name": "rueckruf_min",
    "description": "IMMER verwenden wenn ein Anrufer um Rückruf bittet und nur die Basisdaten erfasst werden sollen. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
    "parameters": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
        "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückruf (notwendig)" },
        "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
      },
      "required": ["id", "telefon", "zusammenfassung"]
    },
    "request": {
      "method": "POST",
      "url": "https://##servername##/telepraxis-receive.php",
      "headers": [{ "name": "Content-Type", "value": "application/json" }],
      "queryString": [],
      "postData": {
        "mimeType": "application/json",
        "text": "{\"typ\":\"rueckruf_min\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
      }
    }
  },
  {
    "name": "rueckruf_tel_grund",
    "description": "IMMER verwenden wenn ein Anrufer um Rückruf bittet und zusätzlich einen Grund nennt. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
    "parameters": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
        "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückruf (notwendig)" },
        "grund": { "type": "string", "description": "Kurzer Grund für den Rückruf" },
        "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
      },
      "required": ["id", "telefon", "grund", "zusammenfassung"]
    },
    "request": {
      "method": "POST",
      "url": "https://##servername##/telepraxis-receive.php",
      "headers": [{ "name": "Content-Type", "value": "application/json" }],
      "queryString": [],
      "postData": {
        "mimeType": "application/json",
        "text": "{\"typ\":\"rueckruf_tel_grund\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"grund\":\"{{ grund }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
      }
    }
  },
  {
    "name": "rueckruf_details",
    "description": "IMMER verwenden wenn ein Anrufer um Rückruf bittet und vollständige Patientendaten genannt werden. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
    "parameters": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
        "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückruf (notwendig)" },
        "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." },
        "vorname": { "type": "string", "description": "Vorname" },
        "nachname": { "type": "string", "description": "Nachname" },
        "geburtsdatum": { "type": "string", "description": "Geburtsdatum" },
        "grund": { "type": "string", "description": "Kurzer Grund für den Rückruf" }
      },
      "required": ["id", "telefon", "zusammenfassung", "vorname", "nachname", "geburtsdatum", "grund"]
    },
    "request": {
      "method": "POST",
      "url": "https://##servername##/telepraxis-receive.php",
      "headers": [{ "name": "Content-Type", "value": "application/json" }],
      "queryString": [],
      "postData": {
        "mimeType": "application/json",
        "text": "{\"typ\":\"rueckruf_details\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\",\"vorname\":\"{{ vorname }}\",\"nachname\":\"{{ nachname }}\",\"geburtsdatum\":\"{{ geburtsdatum }}\",\"grund\":\"{{ grund }}\"}"
      }
    }
  },
  {
    "name": "sonstiges",
    "description": "IMMER verwenden wenn das Anliegen nicht Rezept, Rückruf oder Überweisung ist. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
    "parameters": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
        "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückfragen (notwendig)" },
        "anliegen": { "type": "string", "description": "Freitext: worum geht es?" },
        "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
      },
      "required": ["id", "telefon", "anliegen", "zusammenfassung"]
    },
    "request": {
      "method": "POST",
      "url": "https://##servername##/telepraxis-receive.php",
      "headers": [{ "name": "Content-Type", "value": "application/json" }],
      "queryString": [],
      "postData": {
        "mimeType": "application/json",
        "text": "{\"typ\":\"sonstiges\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"anliegen\":\"{{ anliegen }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
      }
    }
  },
  {
    "name": "fallback_name_tel_grund",
    "description": "IMMER verwenden wenn ein Sonderfall/Problem gemeldet werden muss (z. B. nicht erfolgreich durchgestellter dringender Anruf) und nur Name/Telefon/Grund vorliegen. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
    "parameters": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
        "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückfragen (notwendig)" },
        "name": { "type": "string", "description": "Name der Person (Freitext, z. B. 'Nachname, Vorname')" },
        "grund": { "type": "string", "description": "Kurzer Grund / was ist passiert" },
        "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
      },
      "required": ["id", "telefon", "name", "grund", "zusammenfassung"]
    },
    "request": {
      "method": "POST",
      "url": "https://##servername##/telepraxis-receive.php",
      "headers": [{ "name": "Content-Type", "value": "application/json" }],
      "queryString": [],
      "postData": {
        "mimeType": "application/json",
        "text": "{\"typ\":\"fallback_name_tel_grund\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"name\":\"{{ name }}\",\"grund\":\"{{ grund }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
      }
    }
  },
  {
    "name": "fallback_vn_nn_grund",
    "description": "IMMER verwenden wenn ein Sonderfall/Problem gemeldet werden muss (z. B. nicht erfolgreich durchgestellter dringender Anruf) und Vorname/Nachname/Grund vorliegen. Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Das Feld telefon mit der ggf. zusätzlich genannten Rückrufnummer befüllen (telefon ist notwendig). Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
    "parameters": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
        "telefon": { "type": "string", "description": "Vom Anrufer genannte Telefonnummer für Rückfragen (notwendig)" },
        "vorname": { "type": "string", "description": "Vorname" },
        "nachname": { "type": "string", "description": "Nachname" },
        "grund": { "type": "string", "description": "Kurzer Grund / was ist passiert" },
        "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
      },
      "required": ["id", "telefon", "vorname", "nachname", "grund", "zusammenfassung"]
    },
    "request": {
      "method": "POST",
      "url": "https://##servername##/telepraxis-receive.php",
      "headers": [{ "name": "Content-Type", "value": "application/json" }],
      "queryString": [],
      "postData": {
        "mimeType": "application/json",
        "text": "{\"typ\":\"fallback_vn_nn_grund\",\"id\":\"{{ id }}\",\"telefon\":\"{{ telefon }}\",\"vorname\":\"{{ vorname }}\",\"nachname\":\"{{ nachname }}\",\"grund\":\"{{ grund }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
      }
    }
  },
  {
    "name": "fallback_id_zusammenfassung",
    "description": "IMMER verwenden wenn sonst nichts sicher erfasst werden konnte (z. B. Gespräch abgebrochen, dringender Anruf nicht durchgestellt, unklare Lage). Das Feld id IMMER mit der übermittelten Anrufernummer/Caller-ID befüllen. Zusätzlich eine Zusammenfassung des Gesprächs mitschicken.",
    "parameters": {
      "type": "object",
      "properties": {
        "id": { "type": "string", "description": "Übermittelte Anrufernummer/Caller-ID (IMMER damit befüllen)" },
        "zusammenfassung": { "type": "string", "description": "Zusammenfassung des Gesprächs: bei übersichtlichen Fällen 1–3 Sätze, bei komplexeren Fällen bis zu 5 Sätze. Nur genannte Fakten (keine Ergänzungen/Annahmen)." }
      },
      "required": ["id", "zusammenfassung"]
    },
    "request": {
      "method": "POST",
      "url": "https://##servername##/telepraxis-receive.php",
      "headers": [{ "name": "Content-Type", "value": "application/json" }],
      "queryString": [],
      "postData": {
        "mimeType": "application/json",
        "text": "{\"typ\":\"fallback_id_zusammenfassung\",\"id\":\"{{ id }}\",\"zusammenfassung\":\"{{ zusammenfassung }}\"}"
      }
    }
  }
]

  
</pre>




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

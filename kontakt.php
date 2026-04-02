<?php
// kontakt.php – liefert Formular aus und erzeugt OTP (1 Tag gültig, 1x verwendbar)
// sendet an /telepraxis-receive.php (gleiches Origin -> kein CORS nötig)

$OTP_DB = '/srv/telepraxis/otp.sqlite';

function ensure_db($path) {
    if (!is_dir(dirname($path))) @mkdir(dirname($path), 0770, true);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE IF NOT EXISTS otps (
        token_hash TEXT PRIMARY KEY,
        expires_at INTEGER NOT NULL,
        used_at INTEGER
    )');
    return $pdo;
}

$pdo = ensure_db($OTP_DB);

// OTP erzeugen
$otp = bin2hex(random_bytes(16));
$hash = hash('sha256', $otp);
$expires = time() + 24 * 60 * 60;

$stmt = $pdo->prepare('INSERT OR REPLACE INTO otps(token_hash, expires_at, used_at) VALUES(:h, :e, NULL)');
$stmt->execute(array(':h' => $hash, ':e' => $expires));

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Telepraxis Kontakt</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 760px; margin: 24px auto; padding: 0 12px; }
    label { display:block; margin: 12px 0 6px; font-weight: 600; }
    input, select, textarea { width: 100%; padding: 10px; box-sizing: border-box; }
    .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
    .hidden { display:none; }
    button { margin-top: 16px; padding: 12px 16px; font-weight: 700; cursor:pointer; }
    pre { background:#f5f5f5; padding:12px; overflow:auto; }
    .note { font-size: 0.9rem; opacity: 0.8; }
    /* Modal */
    .modalBackdrop { position: fixed; inset: 0; background: rgba(0,0,0,.5); display:none; align-items:center; justify-content:center; }
    .modal { background: white; padding: 18px; border-radius: 10px; max-width: 520px; width: calc(100% - 24px); }
    .modal h2 { margin: 0 0 10px; }
    .modal .btnrow { display:flex; gap: 10px; justify-content:flex-end; }
  </style>
</head>
<body>
  <h1>Kontaktformular</h1>

  <form id="f">
    <input type="hidden" id="otp" name="otp" value="<?php echo htmlspecialchars($otp, ENT_QUOTES, 'UTF-8'); ?>" />

    <label for="typ">Anliegen</label>
    <select id="typ" name="typ" required>
      <option value="rueckruf_tel_grund">Rückrufbitte</option>
      <option value="ueb_req">Überweisung</option>
      <option value="rezeptbestellung">Rezept</option>
      <option value="termin">Termin</option>
      <option value="sonstiges">Sonstiges</option>
    </select>

    <div class="row">
      <div>
        <label for="vorname">Vorname</label>
        <input id="vorname" name="vorname" autocomplete="given-name" />
      </div>
      <div>
        <label for="nachname">Nachname</label>
        <input id="nachname" name="nachname" autocomplete="family-name" />
      </div>
    </div>

    <div class="row">
      <div>
        <label for="geburtsdatum">Geburtsdatum</label>
        <input id="geburtsdatum" name="geburtsdatum" placeholder="TT.MM.JJJJ" />
      </div>
      <div>
        <label for="telefon">Telefonnummer (notwendig)</label>
        <input id="telefon" name="telefon" required autocomplete="tel" />
      </div>
    </div>

    <div id="block_rezept" class="hidden">
      <label for="medikamente">Medikamente</label>
      <textarea id="medikamente" name="medikamente" rows="3" placeholder="z.B. Ramipril 5 mg; Metformin 1000 mg"></textarea>
    </div>

    <div id="block_ueb" class="hidden">
      <label for="fachrichtung">Fachrichtung</label>
      <input id="fachrichtung" name="fachrichtung" placeholder="z.B. Augenheilkunde" />
      <label for="grund_ueb">Grund</label>
      <input id="grund_ueb" name="grund_ueb" placeholder="z.B. Katarakt" />
    </div>

    <div id="block_rueckruf" class="hidden">
      <label for="grund_rr">Grund</label>
      <input id="grund_rr" name="grund_rr" placeholder="z.B. Befundfrage" />
    </div>

    <div id="block_termin" class="hidden">
      <label for="termin_text">Terminwunsch</label>
      <textarea id="termin_text" name="termin_text" rows="3" placeholder="z.B. nächste Woche vormittags, wegen ..."></textarea>
      <div class="note">Wird beim Absenden in <code>grund</code> übertragen.</div>
    </div>

    <div id="block_sonstiges" class="hidden">
      <label for="anliegen">Anliegen</label>
      <textarea id="anliegen" name="anliegen" rows="3" placeholder="Worum geht es?"></textarea>
    </div>

    <button type="submit" id="sendBtn">Senden</button>
  </form>

  <h2>Status</h2>
  <pre id="out">(noch nichts gesendet)</pre>

  <div class="modalBackdrop" id="modalBg">
    <div class="modal">
      <h2>Die Anfrage wurde erfolgreich gespeichert</h2>
      <div class="btnrow">
        <button id="closeBtn" type="button">Fenster schließen</button>
      </div>
      <div class="note">Hinweis: Manche Browser schließen Tabs nur, wenn das Fenster per Script geöffnet wurde.</div>
    </div>
  </div>

  <script>
    const endpoint = "/telepraxis-receive.php";

    const out = document.getElementById("out");
    const sendBtn = document.getElementById("sendBtn");
    const modalBg = document.getElementById("modalBg");
    const closeBtn = document.getElementById("closeBtn");

    function show(msg) { out.textContent = msg; }

    const blocks = {
      rezeptbestellung: document.getElementById("block_rezept"),
      ueb_req: document.getElementById("block_ueb"),
      rueckruf_tel_grund: document.getElementById("block_rueckruf"),
      termin: document.getElementById("block_termin"),
      sonstiges: document.getElementById("block_sonstiges"),
    };

    function el(id) { return document.getElementById(id); }

    function showFor(typ) {
      Object.values(blocks).forEach(b => b.classList.add("hidden"));
      if (blocks[typ]) blocks[typ].classList.remove("hidden");

      el("medikamente").required = (typ === "rezeptbestellung");
      el("fachrichtung").required = (typ === "ueb_req");
      el("grund_ueb").required = (typ === "ueb_req");
      el("grund_rr").required = (typ === "rueckruf_tel_grund");
      el("termin_text").required = (typ === "termin");
      el("anliegen").required = (typ === "sonstiges");
    }

    const typSel = el("typ");
    typSel.addEventListener("change", () => showFor(typSel.value));
    showFor(typSel.value);

    function buildSummary(p) {
      const who = [p.vorname, p.nachname].filter(Boolean).join(" ").trim();
      const dob = p.geburtsdatum ? `Geburtsdatum ${p.geburtsdatum}` : "";
      const tel = p.telefon ? `Rückrufnummer ${p.telefon}` : "";
      const base = [who, dob, tel].filter(Boolean).join(", ");

      let topic = "";
      if (p.typ === "rezeptbestellung") topic = `Rezeptwunsch: ${p.medikamente || ""}`.trim();
      if (p.typ === "ueb_req") topic = `Überweisung: ${p.fachrichtung || ""}${p.grund ? " ("+p.grund+")" : ""}`.trim();
      if (p.typ === "rueckruf_tel_grund") topic = `Rückrufbitte: ${p.grund || ""}`.trim();
      if (p.typ === "sonstiges") {
        if (p.anliegen) topic = `Anliegen: ${p.anliegen}`.trim();
        if (p.grund) topic = `Anliegen: ${p.grund}`.trim();
      }

      const s1 = base ? `${base}.` : "";
      const s2 = topic ? `${topic}.` : "";
      const s3 = "Bitte bearbeiten.";
      return [s1, s2, s3].filter(Boolean).join(" ").trim();
    }

    closeBtn.addEventListener("click", () => {
      try { window.close(); } catch(e) {}
      modalBg.style.display = "none";
    });

    el("f").addEventListener("submit", async (e) => {
      e.preventDefault();

      if (!e.target.reportValidity()) {
        show("Bitte Pflichtfelder ausfüllen (Browser-Validierung hat blockiert).");
        return;
      }

      sendBtn.disabled = true;

      const uiTyp = typSel.value;
      const telefon = el("telefon").value.trim();
      const otp = el("otp").value.trim();

      // Termin -> sonstiges, Termintext in grund
      const typ = (uiTyp === "termin") ? "sonstiges" : uiTyp;

      const payload = {
        typ,
        id: "web-formular",
        telefon,
        otp,
        vorname: el("vorname").value.trim(),
        nachname: el("nachname").value.trim(),
        geburtsdatum: el("geburtsdatum").value.trim()
      };

      if (uiTyp === "rezeptbestellung") payload.medikamente = el("medikamente").value.trim();
      if (uiTyp === "ueb_req") { payload.fachrichtung = el("fachrichtung").value.trim(); payload.grund = el("grund_ueb").value.trim(); }
      if (uiTyp === "rueckruf_tel_grund") payload.grund = el("grund_rr").value.trim();
      if (uiTyp === "termin") payload.grund = el("termin_text").value.trim();
      if (uiTyp === "sonstiges") payload.anliegen = el("anliegen").value.trim();

      payload.zusammenfassung = buildSummary(payload);

      show("Sende...\n\n" + JSON.stringify(payload, null, 2));

      try {
        const res = await fetch(endpoint, {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(payload)
        });

        const txt = await res.text();

        if (res.ok) {
          modalBg.style.display = "flex";
          show(`HTTP ${res.status}\n\n${txt}`);
        } else if (res.status === 429) {
          show("Senden NICHT Erfolgreich: Empfang begrenzt auf maximal 20 Nachrichten in 10 Minuten, bitte warten und dann nochmal absenden.\n\n" + txt);
        } else {
          show(`Senden NICHT Erfolgreich (HTTP ${res.status}):\n\n${txt}`);
        }
      } catch (err) {
        show("Senden NICHT Erfolgreich: Netzwerkfehler.\n" + (err && err.message ? err.message : String(err)));
      } finally {
        sendBtn.disabled = false;
      }
    });
  </script>
</body>
</html>

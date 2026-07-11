#!/usr/bin/env bash
# quellserver-benutzer-erzeugen-v1.2.sh
#
# v1.2
# von Dr. Thomas Kienzle / ChatGPT
#
# Changelog
# v1.2
# - trennt SSH-Home strikt von schreibbaren App-Verzeichnissen
# - legt zusaetzlich /srv/telepraxis/state/<ssh-benutzer> fuer OTP/SQLite an
# - verwendet gemeinsame Gruppe tp-<ssh-benutzer> statt www-data direkt in die Benutzer-Primärgruppe zu legen
# - patched nun INBOX_DIR, OTP_DB, IONOS_PSK und PUBLIC_KEY_PEM
# - setzt Rechte fuer SSH (.ssh/authorized_keys) strikt und fuer inbox/state separat
# - Zieldatei bleibt /var/www/html/telepraxis-receive<ssh-benutzer>.php
# v1.1
# - Zieldatei fuer PHP nun /var/www/html/telepraxis-receive<ssh-benutzer>.php
# - IONOS-Secret wird nun interaktiv manuell abgefragt oder automatisch erzeugt
# - IONOS-Secret wird in die PHP-Datei gepatcht und am Ende ausgegeben
# v1.0
# - legt interaktiv separaten SSH-Benutzer an oder konfiguriert ihn nach
# - legt /srv/telepraxis/<ssh-benutzer>/inbox an
# - richtet ~/.ssh/authorized_keys ein
# - bootstrapped telepraxis-receive.php von GitHub
# - setzt INBOX_DIR auf /srv/telepraxis/<ssh-benutzer>/inbox
# - setzt den eingebetteten PUBLIC_KEY_PEM interaktiv
# - nimmt www-data in die Benutzergruppe auf
# - setzt Rechte so, dass PHP schreiben und der SSH-Benutzer lesen/loeschen kann
# - erstellt Backup einer bestehenden telepraxis-receive<ssh-benutzer>.php

set -euo pipefail

BOOTSTRAP_URL="https://raw.githubusercontent.com/thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis/refs/heads/main/telepraxis-receive.php"
WEB_ROOT="/var/www/html"
WEB_USER="www-data"
BASE_ROOT="/srv/telepraxis"
STATE_ROOT="${BASE_ROOT}/state"

require_root() {
  if [ "$(id -u)" -ne 0 ]; then
    echo "Bitte als root ausfuehren."
    exit 1
  fi
}

need_cmd() {
  command -v "$1" >/dev/null 2>&1 || {
    echo "Fehlt: $1"
    exit 1
  }
}

ask_ssh_user() {
  local u=""
  while :; do
    read -r -p "SSH-Benutzername: " u
    if [[ "$u" =~ ^[a-z_][a-z0-9_-]*$ ]]; then
      printf '%s\n' "$u"
      return 0
    fi
    echo "Ungueltiger Benutzername. Erlaubt: a-z, 0-9, _, -, Beginn mit Buchstabe/_"
  done
}

ask_ssh_pubkey() {
  local k=""
  echo "Bitte SSH-Public-Key in EINER Zeile einfügen (z. B. ssh-ed25519 ...):"
  while :; do
    read -r k
    if printf '%s' "$k" | grep -Eq '^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp(256|384|521)|sk-ssh-ed25519@openssh.com|sk-ecdsa-sha2-nistp256@openssh.com) '; then
      printf '%s\n' "$k"
      return 0
    fi
    echo "Ungueltiger SSH-Public-Key. Bitte erneut komplett einfügen:"
  done
}

ask_public_key_pem() {
  local pem_file="$1"
  local line=""
  : > "$pem_file"

  echo
  echo "Bitte den Public Key fuer die JSON-Verschluesselung als kompletten PEM-Block einfuegen."
  echo "Mit -----END PUBLIC KEY----- abschliessen."
  echo

  local started=0
  while IFS= read -r line; do
    if [ $started -eq 0 ]; then
      if [ "$line" != "-----BEGIN PUBLIC KEY-----" ]; then
        echo "Die erste Zeile muss genau -----BEGIN PUBLIC KEY----- sein."
        continue
      fi
      started=1
    fi

    printf '%s\n' "$line" >> "$pem_file"

    if [ "$line" = "-----END PUBLIC KEY-----" ]; then
      break
    fi
  done

  if ! grep -qx -- '-----BEGIN PUBLIC KEY-----' "$pem_file"; then
    echo "BEGIN PUBLIC KEY fehlt."
    exit 1
  fi
  if ! grep -qx -- '-----END PUBLIC KEY-----' "$pem_file"; then
    echo "END PUBLIC KEY fehlt."
    exit 1
  fi
}

ask_ionos_secret() {
  local mode=""
  local secret=""

  echo
  echo "IONOS-Secret festlegen:"
  echo "  [A] automatisch erzeugen"
  echo "  [M] manuell eingeben"

  while :; do
    read -r -p "Auswahl [A/M] (Default A): " mode
    mode="${mode:-A}"
    case "$(printf '%s' "$mode" | tr '[:lower:]' '[:upper:]')" in
      A)
        secret="$(openssl rand -base64 32 | tr -d '\n' | tr '/+' '_-' | tr -d '=')"
        printf '%s\n' "$secret"
        return 0
        ;;
      M)
        while :; do
          read -r -p "IONOS-Secret manuell eingeben: " secret
          if [ -n "$secret" ]; then
            printf '%s\n' "$secret"
            return 0
          fi
          echo "Eingabe darf nicht leer sein."
        done
        ;;
      *)
        echo "Bitte A oder M eingeben."
        ;;
    esac
  done
}

ensure_group() {
  local group="$1"
  if getent group "$group" >/dev/null 2>&1; then
    echo "Gruppe $group existiert bereits, verwende sie weiter."
  else
    groupadd "$group"
    echo "Gruppe $group wurde angelegt."
  fi
}

ensure_user() {
  local user="$1"
  local home="$2"

  if id "$user" >/dev/null 2>&1; then
    echo "Benutzer $user existiert bereits, verwende ihn weiter."
    usermod -d "$home" "$user" >/dev/null 2>&1 || true
  else
    useradd -m -d "$home" -s /bin/bash -U "$user"
    passwd -l "$user" >/dev/null 2>&1 || true
    echo "Benutzer $user wurde angelegt."
  fi
}

setup_dirs_and_ssh() {
  local user="$1"
  local shared_group="$2"
  local ssh_pubkey="$3"
  local home="${BASE_ROOT}/${user}"
  local ssh_dir="${home}/.ssh"
  local auth_keys="${ssh_dir}/authorized_keys"
  local inbox="${home}/inbox"
  local state_dir="${STATE_ROOT}/${user}"

  install -d -o root -g root -m 0755 "$BASE_ROOT"
  install -d -o root -g root -m 0755 "$STATE_ROOT"
  install -d -o "$user" -g "$user" -m 0750 "$home"
  install -d -o "$user" -g "$user" -m 0700 "$ssh_dir"
  install -d -o "$user" -g "$shared_group" -m 2770 "$inbox"
  install -d -o root -g "$shared_group" -m 2770 "$state_dir"

  touch "$auth_keys"
  if ! grep -Fqx "$ssh_pubkey" "$auth_keys"; then
    printf '%s\n' "$ssh_pubkey" >> "$auth_keys"
  fi
  chown "$user:$user" "$auth_keys"
  chmod 0600 "$auth_keys"

  usermod -a -G "$shared_group" "$user" || true
  if id "$WEB_USER" >/dev/null 2>&1; then
    usermod -a -G "$shared_group" "$WEB_USER" || true
  fi

  if command -v setfacl >/dev/null 2>&1; then
    setfacl -m "u:${user}:rwx,g:${shared_group}:rwx" "$inbox" "$state_dir" || true
    if id "$WEB_USER" >/dev/null 2>&1; then
      setfacl -m "u:${WEB_USER}:rwx" "$inbox" "$state_dir" || true
      setfacl -d -m "u:${WEB_USER}:rwx,g:${shared_group}:rwx" "$inbox" "$state_dir" || true
    fi
    setfacl -d -m "u:${user}:rwx,g:${shared_group}:rwx" "$inbox" "$state_dir" || true
  fi

  echo "SSH und Verzeichnisstruktur eingerichtet:"
  echo "  Home : $home"
  echo "  Inbox: $inbox"
  echo "  State: $state_dir"
}

download_php() {
  local target="$1"
  local backup=""

  mkdir -p "$(dirname "$target")"

  if [ -f "$target" ]; then
    backup="${target}.bak.$(date +%Y%m%d_%H%M%S)"
    cp -a "$target" "$backup"
    echo "Backup erstellt: $backup"
  fi

  if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$BOOTSTRAP_URL" -o "$target"
  else
    wget -qO "$target" "$BOOTSTRAP_URL"
  fi

  chmod 0644 "$target"
}

patch_php_file() {
  local php_file="$1"
  local inbox_dir="$2"
  local otp_db="$3"
  local pem_file="$4"
  local ionos_secret="$5"

  python3 - "$php_file" "$inbox_dir" "$otp_db" "$ionos_secret" "$pem_file" <<'PY'
import pathlib, re, sys
php_file, inbox_dir, otp_db, ionos_secret, pem_file = sys.argv[1:]
text = pathlib.Path(php_file).read_text(encoding='utf-8')
pem = pathlib.Path(pem_file).read_text(encoding='utf-8').rstrip('\n')
patterns = [
    (r"\$INBOX_DIR\s*=\s*'[^']*';", f"$INBOX_DIR = '{inbox_dir}';"),
    (r"\$OTP_DB\s*=\s*'[^']*';", f"$OTP_DB = '{otp_db}';"),
    (r"\$IONOS_PSK\s*=\s*'[^']*';", f"$IONOS_PSK = '{ionos_secret}';"),
]
for pattern, replacement in patterns:
    new_text, count = re.subn(pattern, replacement, text, count=1)
    if count != 1:
        raise SystemExit(f"Patch fehlgeschlagen fuer Pattern: {pattern}")
    text = new_text
new_text, count = re.subn(
    r"\$PUBLIC_KEY_PEM\s*=\s*<<<'PEM'\n.*?\nPEM;",
    "$PUBLIC_KEY_PEM = <<<'PEM'\n" + pem + "\nPEM;",
    text,
    count=1,
    flags=re.S,
)
if count != 1:
    raise SystemExit("Patch fehlgeschlagen fuer PUBLIC_KEY_PEM")
pathlib.Path(php_file).write_text(new_text, encoding='utf-8')
PY

  php -l "$php_file" >/dev/null
}

maybe_restart_webstack() {
  local unit=""

  if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -Fxq 'apache2.service'; then
    systemctl restart apache2 || true
  fi
  if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -Fxq 'nginx.service'; then
    systemctl restart nginx || true
  fi
  while IFS= read -r unit; do
    [ -n "$unit" ] || continue
    systemctl restart "$unit" || true
  done < <(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '/^php[0-9.]+-fpm\.service/ {print $1}')
}

show_summary() {
  local user="$1"
  local shared_group="$2"
  local inbox="$3"
  local state_dir="$4"
  local php_file="$5"
  local ionos_secret="$6"

  echo
  echo "Fertig."
  echo "SSH-Benutzer:   $user"
  echo "Freigabegruppe: $shared_group"
  echo "Inbox:          $inbox"
  echo "OTP-State:      ${state_dir}/otp.sqlite"
  echo "PHP-Datei:      $php_file"
  echo "IONOS-Secret:   $ionos_secret"
  echo
  echo "Fetch-Quelle kuenftig:"
  echo "  $user@$(hostname -f):$inbox/"
  echo
  echo "Hinweis:"
  echo "  SSH-Home bleibt strikt unter ${BASE_ROOT}/${user}."
  echo "  Schreibbare App-Bereiche sind getrennt: inbox und ${STATE_ROOT}/${user}."
}

main() {
  require_root
  need_cmd php
  need_cmd python3
  need_cmd openssl
  if ! command -v curl >/dev/null 2>&1 && ! command -v wget >/dev/null 2>&1; then
    echo "Es wird curl oder wget benoetigt."
    exit 1
  fi

  local ssh_user=""
  local shared_group=""
  local ssh_pubkey=""
  local pem_tmp=""
  local ionos_secret=""
  local home=""
  local inbox=""
  local state_dir=""
  local otp_db=""
  local target_php=""

  ssh_user="$(ask_ssh_user)"
  shared_group="tp-${ssh_user}"
  ssh_pubkey="$(ask_ssh_pubkey)"

  pem_tmp="$(mktemp)"
  trap 'rm -f "$pem_tmp"' EXIT
  ask_public_key_pem "$pem_tmp"
  ionos_secret="$(ask_ionos_secret)"

  home="${BASE_ROOT}/${ssh_user}"
  inbox="${home}/inbox"
  state_dir="${STATE_ROOT}/${ssh_user}"
  otp_db="${state_dir}/otp.sqlite"
  target_php="${WEB_ROOT}/telepraxis-receive${ssh_user}.php"

  ensure_group "$shared_group"
  ensure_user "$ssh_user" "$home"
  setup_dirs_and_ssh "$ssh_user" "$shared_group" "$ssh_pubkey"
  download_php "$target_php"
  patch_php_file "$target_php" "$inbox" "$otp_db" "$pem_tmp" "$ionos_secret"
  maybe_restart_webstack
  show_summary "$ssh_user" "$shared_group" "$inbox" "$state_dir" "$target_php" "$ionos_secret"
}

main "$@"

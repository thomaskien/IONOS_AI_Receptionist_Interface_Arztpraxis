#!/usr/bin/env bash
# zielserver-vorbereiten-v1.8.sh
#
# v1.8
# von Dr. Thomas Kienzle / ChatGPT
#
# Changelog
# v1.8
# - behebt die Adminpasswort-Erzeugung: keine unsichtbaren Zeilenumbrueche mehr im gepatchten Passwort
# - generiert sechsstellige, gut lesbare Adminpasswoerter ohne mehrdeutige Zeichen
# v1.7
# - bootstrapped telepraxis-sms.php und sms-config.php in den Webroot
# - legt /srv/telepraxis/<benutzer>/config fuer SMS-Credentials ausserhalb des Webroots an
# - patcht den SMS-Credentials-Pfad in telepraxis-sms.php und sms-config.php
# - generiert oder uebernimmt Adminpasswoerter fuer Telepraxis-Webinterface und sms-config.php
# v1.6
# - legt die Web-App immer als /var/www/html/telepraxis-app.php ab
# - Fetch-Dienst, Keys und lokale Inbox bleiben weiterhin pro Benutzer getrennt
# v1.5
# - installiert auf Debian/Ubuntu-Zielsystemen gezielt php-curl und php-xml
# - prueft nach der Paketinstallation die PHP-Module curl, dom und SimpleXML
# v1.4
# - setzt die lokale Benutzerbasis auf owner=<benutzer>, group=tp-<benutzer>, mode=0710
# - erlaubt www-data damit nur das Durchqueren zur lokalen Inbox, damit die App JSON-Dateien lesen kann
# v1.3
# - patcht telepraxis-app.php nun auch bei aktuellem App-Stand mit TELEPRAXIS_INBOX_DIR-Konstante
# - bleibt rueckwaertskompatibel zum alten Platzhalter /srv/telepraxis/inbox
# v1.2
# - trennt SSH-Home/Key-Bereich strikt von schreibbarer Inbox
# - verwendet gemeinsame Gruppe tp-<benutzer> fuer Inbox statt www-data direkt in die Benutzer-Primärgruppe zu legen
# - historisch bis v1.5: telepraxis-app wurde mit Benutzerzusatz abgelegt
# - Fetch-Dienst und Fetch-Script bleiben pro Benutzer getrennt
# - Rechte fuer Inbox robuster mit setgid und optionalen Default-ACLs
# - Webstack-Neustart fuer nginx/Apache/PHP-FPM robuster
# v1.1
# - Rechte-/Gruppen-Setup fuer Inbox verfeinert
# - optionale Default-ACL fuer Inbox gesetzt, falls setfacl vorhanden ist
# - Webstack-Neustart robuster umgesetzt (Apache, nginx, PHP-FPM)
# - systemd-Dienst mit UMask=0002
# - Fetch-Script mit umask 0002 gepatcht
# - chown nicht mehr rekursiv ueber die komplette Basisstruktur
# v1.0
# - legt interaktiv einen Zielserver-Benutzer an oder verwendet ihn weiter
# - erzeugt SSH-Key fuer den Fetch vom Quellsystem
# - erzeugt RSA-Keypaar fuer die JSON-Entschluesselung
# - legt /srv/telepraxis/<benutzer>/inbox und /srv/telepraxis/<benutzer>/.encrypted an
# - bootstrapped telepraxis-app.php
# - bootstrapped telepraxis_fetch_and_decrypt.sh
# - patcht App- und Fetch-Konfiguration automatisch auf den gewaehlten Benutzer
# - richtet einen systemd-Dienst ein, der unter dem gewaehlten Benutzer laeuft
# - gibt am Ende SSH-Public-Key und RSA-Public-Key auf der Konsole aus

set -euo pipefail

APP_URL="https://raw.githubusercontent.com/thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis/refs/heads/main/telepraxis-app.php"
SMS_LIB_URL="https://raw.githubusercontent.com/thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis/refs/heads/main/telepraxis-sms.php"
SMS_CONFIG_URL="https://raw.githubusercontent.com/thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis/refs/heads/main/sms-config.php"
FETCH_URL="https://raw.githubusercontent.com/thomaskien/IONOS_AI_Receptionist_Interface_Arztpraxis/refs/heads/main/telepraxis_fetch_and_decrypt.sh"

BASE_ROOT="/srv/telepraxis"
WEBROOT_DIR="/var/www/html"
WEB_USER="www-data"

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

install_php_extension_packages() {
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update
    apt-get install -y php-curl php-xml
  else
    echo "Hinweis: apt-get nicht gefunden. Bitte php-curl und php-xml manuell installieren."
  fi
}

verify_php_extensions() {
  php -m | grep -iq '^curl$' || {
    echo "PHP-cURL-Unterstuetzung fehlt. Bitte php-curl installieren."
    exit 1
  }
  php -m | grep -iq '^dom$' || {
    echo "PHP-DOM-Unterstuetzung fehlt. Bitte php-xml installieren."
    exit 1
  }
  php -m | grep -iq '^SimpleXML$' || {
    echo "PHP-SimpleXML-Unterstuetzung fehlt. Bitte php-xml installieren."
    exit 1
  }
}

fetch_url_to_file() {
  local url="$1"
  local target="$2"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$url" -o "$target"
  else
    wget -qO "$target" "$url"
  fi
}

ask_default() {
  local prompt="$1"
  local def="$2"
  local value=""
  read -r -p "$prompt [$def]: " value
  if [ -z "$value" ]; then
    value="$def"
  fi
  printf '%s\n' "$value"
}

generate_admin_password() {
  python3 - <<'PY'
import secrets
alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
print(''.join(secrets.choice(alphabet) for _ in range(6)))
PY
}

ask_password_or_generate() {
  local result_var="$1"
  local prompt="$2"
  local value=""
  read -r -s -p "$prompt [Enter = automatisch generieren]: " value
  echo
  if [ -z "$value" ]; then
    value="$(generate_admin_password)"
  fi
  printf -v "$result_var" '%s' "$value"
}

ask_user() {
  local u=""
  while :; do
    read -r -p "Lokaler Zielserver-Benutzer: " u
    if [[ "$u" =~ ^[a-z_][a-z0-9_-]*$ ]]; then
      printf '%s\n' "$u"
      return 0
    fi
    echo "Ungueltiger Benutzername. Erlaubt: a-z, 0-9, _, -, Beginn mit Buchstabe/_"
  done
}

backup_if_exists() {
  local path="$1"
  if [ -f "$path" ]; then
    cp -a "$path" "${path}.bak.$(date +%Y%m%d_%H%M%S)"
  fi
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

ensure_dirs() {
  local user="$1"
  local shared_group="$2"
  local base="$3"
  local ssh_dir="$4"
  local pull_dir="$5"
  local inbox_dir="$6"
  local config_dir="$7"

  install -d -o root -g root -m 0755 "$BASE_ROOT"
  install -d -o "$user" -g "$shared_group" -m 0710 "$base"
  install -d -o "$user" -g "$user" -m 0700 "$ssh_dir"
  install -d -o "$user" -g "$user" -m 0750 "$pull_dir"
  install -d -o "$user" -g "$shared_group" -m 2770 "$inbox_dir"
  install -d -o "$user" -g "$shared_group" -m 2770 "$config_dir"

  usermod -a -G "$shared_group" "$user" || true
  if id "$WEB_USER" >/dev/null 2>&1; then
    usermod -a -G "$shared_group" "$WEB_USER" || true
  fi

  if command -v setfacl >/dev/null 2>&1; then
    setfacl -m "u:${user}:rwx,g:${shared_group}:rwx" "$inbox_dir" || true
    setfacl -m "u:${user}:rwx,g:${shared_group}:rwx" "$config_dir" || true
    if id "$WEB_USER" >/dev/null 2>&1; then
      setfacl -m "u:${WEB_USER}:rwx" "$inbox_dir" || true
      setfacl -m "u:${WEB_USER}:rwx" "$config_dir" || true
      setfacl -d -m "u:${WEB_USER}:rwx,g:${shared_group}:rwx" "$inbox_dir" || true
      setfacl -d -m "u:${WEB_USER}:rwx,g:${shared_group}:rwx" "$config_dir" || true
    fi
    setfacl -d -m "u:${user}:rwx,g:${shared_group}:rwx" "$inbox_dir" || true
    setfacl -d -m "u:${user}:rwx,g:${shared_group}:rwx" "$config_dir" || true
  fi
}

ensure_sms_credentials_file() {
  local user="$1"
  local shared_group="$2"
  local credentials_file="$3"

  if [ ! -f "$credentials_file" ]; then
    printf "{\n}\n" > "$credentials_file"
  fi
  chown "$user:$shared_group" "$credentials_file"
  chmod 0660 "$credentials_file"
}

generate_ssh_keypair() {
  local user="$1"
  local keyfile="$2"

  if [ -f "$keyfile" ] && [ -f "${keyfile}.pub" ]; then
    echo "SSH-Key existiert bereits: $keyfile"
  else
    ssh-keygen -t ed25519 -N '' -f "$keyfile" >/dev/null
    echo "SSH-Key erzeugt: $keyfile"
  fi

  chown "$user:$user" "$keyfile" "${keyfile}.pub"
  chmod 0600 "$keyfile"
  chmod 0644 "${keyfile}.pub"
}

generate_decrypt_keypair() {
  local user="$1"
  local private_key="$2"
  local public_key="$3"

  if [ -f "$private_key" ] && [ -f "$public_key" ]; then
    echo "Decrypt-Keypaar existiert bereits."
  else
    openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out "$private_key" >/dev/null 2>&1
    openssl pkey -in "$private_key" -pubout -out "$public_key" >/dev/null 2>&1
    echo "Decrypt-Keypaar erzeugt."
  fi

  chown "$user:$user" "$private_key" "$public_key"
  chmod 0600 "$private_key"
  chmod 0644 "$public_key"
}

patch_app_file() {
  local app_file="$1"
  local inbox_dir="$2"
  local admin_password="$3"

  python3 - "$app_file" "$inbox_dir" "$admin_password" <<'PY'
import pathlib, re, sys
app_file, inbox_dir, admin_password = sys.argv[1:]
path = pathlib.Path(app_file)
text = path.read_text(encoding='utf-8')

def php_single_quoted(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

if '/srv/telepraxis/inbox' in text:
    text = text.replace('/srv/telepraxis/inbox', inbox_dir)
else:
    pattern = r"const\s+TELEPRAXIS_INBOX_DIR\s*=\s*.*?;"
    replacement = f"const TELEPRAXIS_INBOX_DIR = {php_single_quoted(inbox_dir)};"
    text, count = re.subn(pattern, replacement, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit('Patch fehlgeschlagen: TELEPRAXIS_INBOX_DIR nicht gefunden')

pattern = r"const\s+TELEPRAXIS_ADMIN_PASSWORD\s*=\s*.*?;"
replacement = f"const TELEPRAXIS_ADMIN_PASSWORD = {php_single_quoted(admin_password)};"
text, count = re.subn(pattern, replacement, text, count=1, flags=re.S)
if count != 1:
    raise SystemExit('Patch fehlgeschlagen: TELEPRAXIS_ADMIN_PASSWORD nicht gefunden')

path.write_text(text, encoding='utf-8')
PY

  php -l "$app_file" >/dev/null
}

patch_sms_files() {
  local sms_lib_file="$1"
  local sms_config_file="$2"
  local credentials_file="$3"
  local sms_config_password="$4"

  python3 - "$sms_lib_file" "$sms_config_file" "$credentials_file" "$sms_config_password" <<'PY'
import pathlib, re, sys
sms_lib_file, sms_config_file, credentials_file, sms_config_password = sys.argv[1:]

def php_single_quoted(value):
    return "'" + value.replace("\\", "\\\\").replace("'", "\\'") + "'"

def patch_constant(text, name, value, label):
    pattern = rf"const\s+{name}\s*=\s*.*?;"
    replacement = f"const {name} = {php_single_quoted(value)};"
    text, count = re.subn(pattern, replacement, text, count=1, flags=re.S)
    if count != 1:
        raise SystemExit(f'Patch fehlgeschlagen: {label} nicht gefunden')
    return text

for filename in (sms_lib_file, sms_config_file):
    path = pathlib.Path(filename)
    text = path.read_text(encoding='utf-8')
    text = patch_constant(text, 'TP_SMS_CREDENTIALS_FILE', credentials_file, 'TP_SMS_CREDENTIALS_FILE')
    if filename == sms_config_file:
        text = patch_constant(text, 'TP_SMS_CONFIG_ADMIN_PASSWORD', sms_config_password, 'TP_SMS_CONFIG_ADMIN_PASSWORD')
    path.write_text(text, encoding='utf-8')
PY

  php -l "$sms_lib_file" >/dev/null
  php -l "$sms_config_file" >/dev/null
}

patch_fetch_file() {
  local fetch_file="$1"
  local remote_host="$2"
  local remote_user="$3"
  local remote_port="$4"
  local remote_dir="$5"
  local local_pull_dir="$6"
  local local_out_dir="$7"
  local private_key_file="$8"
  local ssh_identity_file="$9"

  python3 - "$fetch_file" "$remote_host" "$remote_user" "$remote_port" "$remote_dir" "$local_pull_dir" "$local_out_dir" "$private_key_file" "$ssh_identity_file" <<'PY'
import pathlib, re, sys
(fetch_file, remote_host, remote_user, remote_port, remote_dir,
 local_pull_dir, local_out_dir, private_key_file, ssh_identity_file) = sys.argv[1:]
text = pathlib.Path(fetch_file).read_text(encoding='utf-8')
replacements = {
    'REMOTE_HOST': remote_host,
    'REMOTE_USER': remote_user,
    'REMOTE_DIR': remote_dir,
    'LOCAL_PULL_DIR': local_pull_dir,
    'LOCAL_OUT_DIR': local_out_dir,
    'PRIVATE_KEY_FILE': private_key_file,
    'SSH_IDENTITY_FILE': ssh_identity_file,
    'SSH_PORT': remote_port,
    'RUN_FOREVER': '1',
    'SLEEP_SECONDS': '5',
    'DELETE_REMOTE_ON_SUCCESS': '1',
    'DELETE_LOCAL_ENC_ON_SUCCESS': '1',
}
for key, value in replacements.items():
    pattern = rf'^{key}=".*"$'
    repl = f'{key}="{value}"'
    text, count = re.subn(pattern, repl, text, count=1, flags=re.M)
    if count != 1:
        raise SystemExit(f'Patch fehlgeschlagen fuer {key}')
if 'umask 0002' not in text:
    text, count = re.subn(r'set -o pipefail\n', 'set -o pipefail\n\numask 0002\n', text, count=1)
    if count != 1:
        raise SystemExit('Patch fehlgeschlagen fuer umask 0002')
text = text.replace('chmod 0640 "$final_out"', 'chmod 0660 "$final_out"')
pathlib.Path(fetch_file).write_text(text, encoding='utf-8')
PY

  chmod 0755 "$fetch_file"
}

create_service_file() {
  local service_file="$1"
  local user="$2"
  local exec_script="$3"

  cat > "$service_file" <<SERVICE
[Unit]
Description=telepraxis fetch and decrypt for $user
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=$user
Group=$user
UMask=0002
ExecStart=$exec_script
Restart=always
RestartSec=2
WorkingDirectory=/srv/telepraxis/$user

[Install]
WantedBy=multi-user.target
SERVICE
}

restart_if_present() {
  local unit="$1"
  if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -Fxq "$unit"; then
    systemctl restart "$unit" || true
  fi
}

maybe_restart_webstack() {
  local unit=""

  restart_if_present "apache2.service"
  restart_if_present "nginx.service"

  while IFS= read -r unit; do
    [ -n "$unit" ] || continue
    systemctl restart "$unit" || true
  done < <(systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '/^php[0-9.]+-fpm\.service/ {print $1}')
}

show_summary() {
  local user="$1"
  local shared_group="$2"
  local source_host="$3"
  local source_user="$4"
  local source_port="$5"
  local base_dir="$6"
  local inbox_dir="$7"
  local config_dir="$8"
  local app_file="$9"
  local sms_lib_file="${10}"
  local sms_config_file="${11}"
  local sms_credentials="${12}"
  local fetch_file="${13}"
  local service_name="${14}"
  local ssh_pub="${15}"
  local decrypt_pub="${16}"
  local app_admin_password="${17}"
  local sms_config_password="${18}"

  echo
  echo "Fertig."
  echo "Lokaler Benutzer:       $user"
  echo "Freigabegruppe:         $shared_group"
  echo "Quellserver:            $source_user@$source_host:$source_port"
  echo "Lokale Basis:           $base_dir"
  echo "Lokale Inbox:           $inbox_dir"
  echo "Lokale Config:          $config_dir"
  echo "App-Datei:              $app_file"
  echo "SMS-Funktionsdatei:     $sms_lib_file"
  echo "SMS-Konfiguration:      $sms_config_file"
  echo "SMS-Credentials:        $sms_credentials"
  echo "Fetch-Script:           $fetch_file"
  echo "Dienst:                 $service_name"
  echo
  echo "Adminpasswoerter:"
  echo "----------------------------------------------------------------"
  echo "Telepraxis-Webinterface: $app_admin_password"
  echo "sms-config.php:          $sms_config_password"
  echo "----------------------------------------------------------------"
  echo
  echo "SSH-Public-Key fuer das Quellsystem (authorized_keys):"
  echo "----------------------------------------------------------------"
  cat "$ssh_pub"
  echo "----------------------------------------------------------------"
  echo
  echo "RSA-Public-Key fuer telepraxis-receive<ssh-benutzer>.php auf dem Quellsystem:"
  echo "----------------------------------------------------------------"
  cat "$decrypt_pub"
  echo "----------------------------------------------------------------"
  echo
  echo "Hinweise:"
  echo "- Auf dem Quellsystem den obigen SSH-Public-Key in authorized_keys des dortigen SSH-Benutzers eintragen."
  echo "- Auf dem Quellsystem den obigen RSA-Public-Key in die passende telepraxis-receive<ssh-benutzer>.php einsetzen."
  echo "- Die lokale Inbox ist fuer ${WEB_USER} und ${user} vorbereitet."
  echo "- Die SMS-Credentials liegen ausserhalb des Webroots unter: $sms_credentials"
  echo "- sms-config.php ist ueber den normalen Webserver erreichbar und mit dem obigen Adminpasswort geschuetzt."
}

main() {
  require_root
  install_php_extension_packages
  need_cmd php
  need_cmd python3
  need_cmd ssh-keygen
  need_cmd openssl
  need_cmd systemctl
  verify_php_extensions
  if ! command -v curl >/dev/null 2>&1 && ! command -v wget >/dev/null 2>&1; then
    echo "Es wird curl oder wget benoetigt."
    exit 1
  fi

  local target_user=""
  local shared_group=""
  local source_host=""
  local source_user=""
  local source_port=""
  local base_dir=""
  local ssh_dir=""
  local pull_dir=""
  local inbox_dir=""
  local config_dir=""
  local sms_credentials=""
  local ssh_key=""
  local decrypt_private=""
  local decrypt_public=""
  local fetch_script=""
  local app_target=""
  local sms_lib_target=""
  local sms_config_target=""
  local service_name=""
  local service_file=""
  local remote_dir=""
  local app_admin_password=""
  local sms_config_password=""

  target_user="$(ask_user)"
  shared_group="tp-${target_user}"
  source_host="$(ask_default 'Quellserver Hostname/FQDN' 'kontakt.praxispi.de')"
  source_user="$(ask_default 'SSH-Benutzer auf dem Quellsystem' "$target_user")"
  source_port="$(ask_default 'SSH-Port auf dem Quellsystem' '22')"
  ask_password_or_generate app_admin_password 'Supervisor/Adminpasswort fuer das Telepraxis-Webinterface'
  ask_password_or_generate sms_config_password 'Adminpasswort fuer sms-config.php'

  base_dir="${BASE_ROOT}/${target_user}"
  ssh_dir="${base_dir}/.ssh"
  pull_dir="${base_dir}/.encrypted"
  inbox_dir="${base_dir}/inbox"
  config_dir="${base_dir}/config"
  sms_credentials="${config_dir}/sms-credentials.json"
  ssh_key="${ssh_dir}/telepraxis_fetch_key"
  decrypt_private="${base_dir}/telepraxis_decrypt_private.pem"
  decrypt_public="${base_dir}/telepraxis_decrypt_public.pem"
  fetch_script="/usr/local/bin/telepraxis_fetch_and_decrypt_${target_user}.sh"
  app_target="${WEBROOT_DIR}/telepraxis-app.php"
  sms_lib_target="${WEBROOT_DIR}/telepraxis-sms.php"
  sms_config_target="${WEBROOT_DIR}/sms-config.php"
  service_name="telepraxis-fetch-and-decrypt-${target_user}.service"
  service_file="/etc/systemd/system/${service_name}"
  remote_dir="/srv/telepraxis/${source_user}/inbox"

  ensure_group "$shared_group"
  ensure_user "$target_user" "$base_dir"
  ensure_dirs "$target_user" "$shared_group" "$base_dir" "$ssh_dir" "$pull_dir" "$inbox_dir" "$config_dir"
  ensure_sms_credentials_file "$target_user" "$shared_group" "$sms_credentials"
  generate_ssh_keypair "$target_user" "$ssh_key"
  generate_decrypt_keypair "$target_user" "$decrypt_private" "$decrypt_public"

  mkdir -p "$WEBROOT_DIR" /usr/local/bin

  backup_if_exists "$app_target"
  fetch_url_to_file "$APP_URL" "$app_target"
  chmod 0644 "$app_target"
  patch_app_file "$app_target" "$inbox_dir" "$app_admin_password"

  backup_if_exists "$sms_lib_target"
  fetch_url_to_file "$SMS_LIB_URL" "$sms_lib_target"
  chmod 0644 "$sms_lib_target"

  backup_if_exists "$sms_config_target"
  fetch_url_to_file "$SMS_CONFIG_URL" "$sms_config_target"
  chmod 0644 "$sms_config_target"
  patch_sms_files "$sms_lib_target" "$sms_config_target" "$sms_credentials" "$sms_config_password"

  backup_if_exists "$fetch_script"
  fetch_url_to_file "$FETCH_URL" "$fetch_script"
  patch_fetch_file "$fetch_script" "$source_host" "$source_user" "$source_port" "$remote_dir" "$pull_dir" "$inbox_dir" "$decrypt_private" "$ssh_key"
  chown "$target_user:$target_user" "$fetch_script"

  create_service_file "$service_file" "$target_user" "$fetch_script"
  chmod 0644 "$service_file"

  systemctl daemon-reload
  systemctl enable --now "$service_name"

  maybe_restart_webstack

  show_summary "$target_user" "$shared_group" "$source_host" "$source_user" "$source_port" "$base_dir" "$inbox_dir" "$config_dir" "$app_target" "$sms_lib_target" "$sms_config_target" "$sms_credentials" "$fetch_script" "$service_name" "${ssh_key}.pub" "$decrypt_public" "$app_admin_password" "$sms_config_password"
}

main "$@"

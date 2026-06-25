#!/usr/bin/env bash
# quellserver-vorbereiten-nginx-v1.2.sh
#
# v1.2
# von Dr. Thomas Kienzle / ChatGPT
#
# Changelog
# v1.2
# - installiert nur nginx + certbot + PHP-FPM/CLI/CURL/SQLite3, nicht das generische php-Metapaket
# - prueft explizit auf OpenSSL- und SQLite-Unterstuetzung in PHP
# - weist auf bereits installierten Apache hin, entfernt ihn aber nicht automatisch
# v1.1
# - installiert zusaetzlich PHP, PHP-FPM, PHP-CLI, PHP-cURL, PHP-SQLite3 und PHP-OpenSSL
# - prueft nach der Installation explizit, ob PHP vorhanden ist
# - ermittelt den vorhandenen PHP-FPM-Socket robuster
# - startet/reloadet PHP-FPM bei Bedarf mit
# v1.0
# - installiert benoetigte Pakete fuer nginx + Let's Encrypt via certbot
# - fragt interaktiv FQDN und E-Mail-Adresse ab
# - kann den System-Hostname auf den FQDN setzen
# - legt einen separaten nginx-vHost fuer /var/www/html an
# - richtet Let's Encrypt per certbot --nginx ein
# - prueft vorhandene nginx-Konfigurationen auf server_name-Konflikte
# - richtet automatische Zertifikatserneuerung ein und testet dry-run

set -euo pipefail

WEBROOT="/var/www/html"
NGINX_SITE_DIR="/etc/nginx/sites-available"
NGINX_ENABLED_DIR="/etc/nginx/sites-enabled"
LETSENCRYPT_EMAIL=""
FQDN=""
SITE_FILE=""
PHP_FPM_SOCKET=""
PHP_FPM_SERVICE=""

need_root() {
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

ask_fqdn() {
  local v=""
  while :; do
    read -r -p "FQDN/Hostname fuer diesen vHost (z. B. telepraxis.example.de): " v
    if printf '%s' "$v" | grep -Eq '^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$'; then
      printf '%s\n' "$v"
      return 0
    fi
    echo "Bitte einen gueltigen FQDN eingeben."
  done
}

ask_email() {
  local v=""
  while :; do
    read -r -p "E-Mail fuer Let's Encrypt: " v
    if printf '%s' "$v" | grep -Eq '^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$'; then
      printf '%s\n' "$v"
      return 0
    fi
    echo "Bitte eine gueltige E-Mail-Adresse eingeben."
  done
}

ask_yes_no_default_yes() {
  local prompt="$1"
  local ans=""
  while :; do
    read -r -p "$prompt [Y/n]: " ans
    ans="${ans:-Y}"
    case "$ans" in
      Y|y|J|j|yes|YES) return 0 ;;
      N|n|no|NO) return 1 ;;
      *) echo "Bitte Y oder n eingeben." ;;
    esac
  done
}

install_packages() {
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y \
    nginx \
    certbot \
    python3-certbot-nginx \
    curl \
    php-fpm \
    php-cli \
    php-curl \
    php-sqlite3
}

warn_if_apache_present() {
  if dpkg -l 2>/dev/null | awk '{print $2}' | grep -Eq '^(apache2|apache2-bin|apache2-data|apache2-utils|libapache2-mod-php[0-9.]*)$'; then
    echo
    echo "Hinweis: Apache scheint bereits installiert zu sein."
    echo "Das Script entfernt Apache absichtlich nicht automatisch."
    echo
  fi
}

verify_php_available() {
  command -v php >/dev/null 2>&1 || {
    echo "PHP fehlt trotz Installation. Abbruch."
    exit 1
  }
  php -v >/dev/null 2>&1 || {
    echo "PHP ist nicht ausfuehrbar. Abbruch."
    exit 1
  }
  php -m | grep -iq '^openssl$' || {
    echo "PHP-OpenSSL-Unterstuetzung fehlt. Abbruch."
    exit 1
  }
  php -m | grep -Eiq '^(pdo_sqlite|sqlite3)$' || {
    echo "PHP-SQLite-Unterstuetzung fehlt. Abbruch."
    exit 1
  }
}

check_dns_hint() {
  echo
  echo "Wichtig: $FQDN muss bereits per DNS auf diesen Server zeigen."
  echo "Ausserdem muessen Port 80 und 443 aus dem Internet erreichbar sein."
  echo
}

set_system_hostname_if_wanted() {
  if ask_yes_no_default_yes "System-Hostname auf $FQDN setzen?"; then
    hostnamectl set-hostname "$FQDN"
    echo "Hostname gesetzt auf: $FQDN"
  else
    echo "System-Hostname bleibt unveraendert."
  fi
}

abort_if_server_name_exists() {
  if nginx -T 2>/dev/null | grep -E "^[[:space:]]*server_name[[:space:]].*\\b${FQDN//./\\.}\\b" >/dev/null 2>&1; then
    echo "Es existiert bereits ein nginx server_name-Eintrag fuer $FQDN."
    echo "Zum Schutz vor Konflikten wird abgebrochen."
    exit 1
  fi
}

find_php_fpm_socket_and_service() {
  PHP_FPM_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' | sort | head -n1 || true)"
  PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service 2>/dev/null | awk '/^php[0-9.]+-fpm\.service/ {print $1; exit}')"
}

create_site() {
  mkdir -p "$WEBROOT"
  chown root:root "$WEBROOT"
  chmod 0755 "$WEBROOT"

  SITE_FILE="$NGINX_SITE_DIR/$FQDN.conf"

  cat > "$SITE_FILE" <<SITE
server {
    listen 80;
    listen [::]:80;
    server_name $FQDN;

    root $WEBROOT;
    index index.php index.html index.htm;

    access_log /var/log/nginx/${FQDN}_access.log;
    error_log  /var/log/nginx/${FQDN}_error.log;

    location /.well-known/acme-challenge/ {
        root $WEBROOT;
        allow all;
        default_type "text/plain";
    }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_FPM_SOCKET};
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }
}
SITE

  ln -sfn "$SITE_FILE" "$NGINX_ENABLED_DIR/$FQDN.conf"
}

test_and_reload_nginx() {
  nginx -t
  systemctl enable nginx >/dev/null 2>&1 || true
  systemctl restart nginx
}

restart_php_fpm_if_present() {
  if [ -n "$PHP_FPM_SERVICE" ]; then
    systemctl enable "$PHP_FPM_SERVICE" >/dev/null 2>&1 || true
    systemctl restart "$PHP_FPM_SERVICE"
  fi
}

obtain_certificate() {
  certbot --nginx \
    -d "$FQDN" \
    -m "$LETSENCRYPT_EMAIL" \
    --agree-tos \
    --no-eff-email \
    --redirect \
    --non-interactive
}

test_renewal() {
  systemctl enable certbot.timer >/dev/null 2>&1 || true
  systemctl start certbot.timer >/dev/null 2>&1 || true
  certbot renew --dry-run
}

show_summary() {
  echo
  echo "Fertig."
  echo "FQDN:            $FQDN"
  echo "Webroot:         $WEBROOT"
  echo "nginx-vHost:     $SITE_FILE"
  echo "PHP-FPM-Socket:  $PHP_FPM_SOCKET"
  echo "PHP-FPM-Service: ${PHP_FPM_SERVICE:-nicht erkannt}"
  echo "Let's Encrypt:   eingerichtet"
  echo
  echo "Bitte pruefen:"
  echo "  https://$FQDN/"
  echo
}

main() {
  need_root
  need_cmd grep
  need_cmd sed
  need_cmd find
  need_cmd awk
  need_cmd hostnamectl

  FQDN="$(ask_fqdn)"
  LETSENCRYPT_EMAIL="$(ask_email)"

  check_dns_hint
  install_packages
  warn_if_apache_present
  verify_php_available
  find_php_fpm_socket_and_service

  if [ -z "$PHP_FPM_SOCKET" ]; then
    echo "Kein PHP-FPM-Socket gefunden. Abbruch."
    exit 1
  fi

  set_system_hostname_if_wanted
  abort_if_server_name_exists
  create_site
  restart_php_fpm_if_present
  test_and_reload_nginx
  obtain_certificate
  test_renewal
  show_summary
}

main "$@"

#!/usr/bin/env bash
# telepraxis_fetch_and_decrypt.sh
#
# Holt verschlsselte Telepraxis-Dateien per SCP, entschlsselt sie lokal
# und lscht nach erfolgreicher Verarbeitung die .enc-Datei lokal und remote.
#
# Getestete Zielidee:
# - Remote-Dateien:   /srv/telepraxis/inbox/*.enc
# - Lokale Ausgabe:   z.B. /Volumes/webroot/inbox/*.json
#
# Voraussetzungen lokal:
#   - bash
#   - ssh
#   - scp
#   - openssl
#   - jq
#   - mktemp
#   - od
#
# macOS:
#   brew install jq
#
# Linux:
#   sudo apt install jq openssl

set -u
set -o pipefail

###############################################################################
# KONFIGURATION
###############################################################################

# Remote
REMOTE_HOST="trallala.de"
REMOTE_USER="root"
REMOTE_DIR="/srv/telepraxis/inbox"

# Lokal
# Hier landen die geholten .enc-Dateien zunchst.
LOCAL_PULL_DIR="/Volumes/webroot/inbox/.encrypted"

# Hier landen die entschlsselten .json-Dateien.
LOCAL_OUT_DIR="/Volumes/webroot/inbox"

# Private Key fr die Inhalts-Entschlsselung
PRIVATE_KEY_FILE="telepraxis_decrypt_private.pem"

# SSH-Key fr SCP/SSH zum Server
SSH_IDENTITY_FILE=".ssh/id_rsa_trallala"

# SSH-Port
SSH_PORT="22"

# Zustzliche SSH-Optionen
SSH_EXTRA_OPTS="-o BatchMode=yes -o StrictHostKeyChecking=yes"

# Dateimuster auf dem Server
REMOTE_FILE_PATTERN="*.enc"

# 1 = alle 5 Sekunden pollen
# 0 = genau einmal laufen und beenden
RUN_FOREVER="1"
SLEEP_SECONDS="5"

# 1 = Remote-Datei nach erfolgreicher Entschlsselung lschen
DELETE_REMOTE_ON_SUCCESS="1"

# 1 = lokale .enc-Datei nach erfolgreicher Entschlsselung lschen
DELETE_LOCAL_ENC_ON_SUCCESS="1"

###############################################################################
# HILFSFUNKTIONEN
###############################################################################

log() {
    printf '%s %s\n' "[$(date '+%Y-%m-%d %H:%M:%S')]" "$*"
}

fail() {
    log "FEHLER: $*"
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || fail "Bentigtes Kommando fehlt: $1"
}

shell_quote() {
    printf "'%s'" "$(printf "%s" "$1" | sed "s/'/'\\\\''/g")"
}

ssh_base_cmd() {
    ssh -i "$SSH_IDENTITY_FILE" -p "$SSH_PORT" $SSH_EXTRA_OPTS "${REMOTE_USER}@${REMOTE_HOST}" "$@"
}

scp_get_cmd() {
    scp -q -i "$SSH_IDENTITY_FILE" -P "$SSH_PORT" $SSH_EXTRA_OPTS "$@"
}

hex_of_file() {
    od -An -vtx1 "$1" | tr -d ' \n'
}

sha256_of_file() {
    openssl dgst -sha256 "$1" | sed 's/^.*= //'
}

decrypt_one_file() {
    # $1 = lokale .enc-Datei
    # $2 = lokales Ausgabeverzeichnis
    local enc_file="$1"
    local out_dir="$2"
    local base_name out_name final_out tmp_out
    local tmpdir expected_sha actual_sha cipher cipher_arg
    local key_hex iv_hex

    base_name="$(basename "$enc_file")"
    out_name="${base_name%.enc}"
    final_out="${out_dir%/}/${out_name}"
    tmp_out="${out_dir%/}/.${out_name}.tmp"

    tmpdir="$(mktemp -d 2>/dev/null || mktemp -d -t telepraxis)"
    [ -d "$tmpdir" ] || return 1

    # Wrapper-Felder extrahieren und base64-dekodieren
    if ! jq -e -r '.ek' "$enc_file" | openssl base64 -d -A -out "$tmpdir/ek.bin" >/dev/null 2>&1; then
        log "Entschlsselung fehlgeschlagen: ek konnte nicht gelesen werden: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! jq -e -r '.iv' "$enc_file" | openssl base64 -d -A -out "$tmpdir/iv.bin" >/dev/null 2>&1; then
        log "Entschlsselung fehlgeschlagen: iv konnte nicht gelesen werden: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! jq -e -r '.ct' "$enc_file" | openssl base64 -d -A -out "$tmpdir/ct.bin" >/dev/null 2>&1; then
        log "Entschlsselung fehlgeschlagen: ct konnte nicht gelesen werden: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! expected_sha="$(jq -e -r '.sha256' "$enc_file" 2>/dev/null)"; then
        log "Entschlsselung fehlgeschlagen: sha256 fehlt: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! cipher="$(jq -e -r '.cipher' "$enc_file" 2>/dev/null)"; then
        log "Entschlsselung fehlgeschlagen: cipher fehlt: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    cipher_arg="$(printf '%s' "$cipher" | tr '[:upper:]' '[:lower:]')"

    # AES-Schlssel mit RSA-Private-Key entschlsseln
    if ! openssl pkeyutl \
        -decrypt \
        -inkey "$PRIVATE_KEY_FILE" \
        -pkeyopt rsa_padding_mode:pkcs1 \
        -in "$tmpdir/ek.bin" \
        -out "$tmpdir/key.bin" >/dev/null 2>&1
    then
        log "RSA-Entschlsselung fehlgeschlagen: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    key_hex="$(hex_of_file "$tmpdir/key.bin")"
    iv_hex="$(hex_of_file "$tmpdir/iv.bin")"

    if [ -z "$key_hex" ] || [ -z "$iv_hex" ]; then
        log "Leerer Schlssel oder IV nach Entschlsselung: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    # Nutzdaten entschlsseln
    if ! openssl enc -d "-${cipher_arg}" \
        -K "$key_hex" \
        -iv "$iv_hex" \
        -in "$tmpdir/ct.bin" \
        -out "$tmpdir/plain.json" >/dev/null 2>&1
    then
        log "AES-Entschlsselung fehlgeschlagen: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    # SHA256 prfen
    actual_sha="$(sha256_of_file "$tmpdir/plain.json")"
    if [ "$actual_sha" != "$expected_sha" ]; then
        log "SHA256-Prfung fehlgeschlagen: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    # JSON validieren
    if ! jq -e . "$tmpdir/plain.json" >/dev/null 2>&1; then
        log "Entschlsseltes Ergebnis ist kein gltiges JSON: $enc_file"
        rm -rf "$tmpdir"
        return 1
    fi

    # Atomisch schreiben
    if ! cp "$tmpdir/plain.json" "$tmp_out"; then
        log "Konnte temporre Zieldatei nicht schreiben: $tmp_out"
        rm -rf "$tmpdir"
        return 1
    fi

    if ! mv -f "$tmp_out" "$final_out"; then
        rm -f "$tmp_out"
        log "Konnte finale Zieldatei nicht schreiben: $final_out"
        rm -rf "$tmpdir"
        return 1
    fi

    chmod 0640 "$final_out" >/dev/null 2>&1 || true

    rm -rf "$tmpdir"
    log "Entschlsselt: $final_out"
    return 0
}

list_remote_files() {
    local remote_dir_q
    remote_dir_q="$(shell_quote "$REMOTE_DIR")"

    ssh_base_cmd "cd ${remote_dir_q} && find . -maxdepth 1 -type f -name '${REMOTE_FILE_PATTERN}' | sed 's#^\./##' | sort" 2>/dev/null
}

delete_remote_file() {
    # $1 = Dateiname relativ zu REMOTE_DIR
    local remote_name="$1"
    local remote_full_q

    remote_full_q="$(shell_quote "${REMOTE_DIR%/}/${remote_name}")"
    ssh_base_cmd "rm -f -- ${remote_full_q}" >/dev/null 2>&1
}

fetch_remote_file() {
    # $1 = Dateiname relativ zu REMOTE_DIR
    # $2 = lokaler Zielpfad
    local remote_name="$1"
    local local_path="$2"
    local remote_spec

    remote_spec="${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DIR%/}/${remote_name}"
    scp_get_cmd "$remote_spec" "$local_path"
}

process_one_remote_file() {
    # $1 = Dateiname relativ zu REMOTE_DIR
    local remote_name="$1"
    local local_enc

    local_enc="${LOCAL_PULL_DIR%/}/${remote_name}"

    mkdir -p "$LOCAL_PULL_DIR" "$LOCAL_OUT_DIR" || {
        log "Konnte lokale Verzeichnisse nicht anlegen"
        return 1
    }

    if ! fetch_remote_file "$remote_name" "$local_enc"; then
        log "Konnte Datei nicht holen: $remote_name"
        return 1
    fi

    log "Geholt: $remote_name"

    if ! decrypt_one_file "$local_enc" "$LOCAL_OUT_DIR"; then
        log "Entschlsselung fehlgeschlagen, Datei bleibt erhalten: $local_enc"
        return 1
    fi

    if [ "$DELETE_REMOTE_ON_SUCCESS" = "1" ]; then
        if ! delete_remote_file "$remote_name"; then
            log "Remote-Lschen fehlgeschlagen, lokale .enc bleibt erhalten: $remote_name"
            return 1
        fi
        log "Remote gelscht: $remote_name"
    fi

    if [ "$DELETE_LOCAL_ENC_ON_SUCCESS" = "1" ]; then
        rm -f -- "$local_enc" || {
            log "Warnung: lokale .enc konnte nicht gelscht werden: $local_enc"
            return 1
        }
        log "Lokal gelscht: $local_enc"
    fi

    return 0
}

run_once() {
    local found_any=0
    local remote_name

    while IFS= read -r remote_name; do
        [ -n "$remote_name" ] || continue
        found_any=1
        process_one_remote_file "$remote_name"
    done <<EOF
$(list_remote_files)
EOF

    if [ "$found_any" -eq 0 ]; then
        log "Keine Dateien gefunden."
    fi
}

main() {
    require_cmd bash
    require_cmd ssh
    require_cmd scp
    require_cmd openssl
    require_cmd jq
    require_cmd mktemp
    require_cmd od

    [ -f "$PRIVATE_KEY_FILE" ] || fail "Private Key nicht gefunden: $PRIVATE_KEY_FILE"
    [ -f "$SSH_IDENTITY_FILE" ] || fail "SSH-Key nicht gefunden: $SSH_IDENTITY_FILE"

    mkdir -p "$LOCAL_PULL_DIR" "$LOCAL_OUT_DIR" || fail "Konnte lokale Verzeichnisse nicht anlegen"

    if [ "$RUN_FOREVER" = "1" ]; then
        log "Starte Polling-Schleife alle ${SLEEP_SECONDS}s"
        while true; do
            run_once
            sleep "$SLEEP_SECONDS"
        done
    else
        run_once
    fi
}

main "$@"

#!/usr/bin/env bash
# Sekali saja: hubungkan backup semua aplikasi di VPS ini ke Google Drive pemilik. Hasilnya satu file rclone untuk semua
# aplikasi, /etc/offsite/rclone.conf (root, 600); backup.sh tiap aplikasi memakainya begitu file itu ada.
#   sudo bash deploy/gdrive-setup.sh
# Perlu: OAuth client Google Cloud jenis "Desktop app" (Google Drive API aktif, OAuth consent screen berstatus
# "In production" supaya token tidak kedaluwarsa setelah 7 hari), dan rclone di laptop yang punya browser.
# Scope drive.file: rclone hanya bisa melihat file yang ia buat sendiri, bukan isi Drive yang lain.
# Tidak ada yang diketik di sini yang tampil di layar atau tersimpan di log, kecuali perintah authorize untuk laptop.
set -euo pipefail
umask 077

cd "$(dirname "$0")/.."
# shellcheck source=offsite.sh
. deploy/offsite.sh

[ "$(id -u)" = 0 ] || { echo "Jalankan dengan sudo."; exit 1; }
command -v jq >/dev/null || { echo "jq belum terpasang (apt install jq)."; exit 1; }
CONF="$GDRIVE_CONF_DIR/rclone.conf"

if [ -f "$CONF" ]; then
    read -rp "$CONF sudah ada. Ganti dengan token baru? [y/N] " answer
    [ "$answer" = y ] || [ "$answer" = Y ] || exit 0
fi

read -rsp "Client ID (tidak tampil): " CLIENT_ID; echo
read -rsp "Client secret (tidak tampil): " CLIENT_SECRET; echo
[ -n "$CLIENT_ID" ] && [ -n "$CLIENT_SECRET" ] || { echo "Client ID dan secret wajib diisi."; exit 1; }

BLOB=$(jq -cn --arg id "$CLIENT_ID" --arg secret "$CLIENT_SECRET" '{client_id: $id, client_secret: $secret, scope: "drive.file"}' \
    | base64 -w0 | tr '+/' '-_' | tr -d '=')
cat <<MSG

Jalankan di laptop (macOS: brew install rclone), login dengan akun Google pemilik Drive, lalu salin baris hasil
di antara "--->" dan "<---End paste":

    rclone authorize "drive" "$BLOB"

MSG
read -rsp "Tempel hasilnya di sini (tidak tampil): " PASTED; echo
PASTED=$(printf '%s' "$PASTED" | tr -d '[:space:]')

# rclone versions print either the token JSON or a base64url blob that holds it; accept both.
token_from() {
    jq -ce 'if type == "object" and has("refresh_token") then . elif type == "object" and (.token | type) == "string" then (.token | fromjson) else empty end | select(.refresh_token)' 2>/dev/null
}
TOKEN=$(printf '%s' "$PASTED" | token_from || true)
if [ -z "$TOKEN" ]; then
    padded=$(printf '%s' "$PASTED" | tr -- '-_' '+/')
    while [ $(( ${#padded} % 4 )) -ne 0 ]; do padded="$padded="; done
    TOKEN=$(printf '%s' "$padded" | base64 -d 2>/dev/null | token_from || true)
fi
[ -n "$TOKEN" ] || { echo "Hasil tempel tidak berisi refresh token. Ulangi dari awal."; exit 1; }

mkdir -p "$GDRIVE_CONF_DIR"
chmod 700 "$GDRIVE_CONF_DIR"
NEW="$CONF.new"
{
    echo "[gdrive]"
    echo "type = drive"
    echo "client_id = $CLIENT_ID"
    echo "client_secret = $CLIENT_SECRET"
    echo "scope = drive.file"
    echo "token = $TOKEN"
} > "$NEW"
chmod 600 "$NEW"

# Test with the new file before it replaces the old one: write, list, and delete a small file in the backup folder.
TEST_DIR=$(mktemp -d)
trap 'rm -rf "$TEST_DIR"; rm -f "$NEW"' EXIT
date -Iseconds > "$TEST_DIR/setup-test.txt"
mv "$CONF" "$CONF.old" 2>/dev/null || true
mv "$NEW" "$CONF"
if offsite gdrive "$TEST_DIR" copyto /data/setup-test.txt offsite:setup-test.txt --stats 0 \
    && offsite gdrive "$TEST_DIR" lsf offsite: --include setup-test.txt | grep -q setup-test.txt \
    && offsite gdrive "$TEST_DIR" deletefile offsite:setup-test.txt; then
    rm -f "$CONF.old"
    echo "Google Drive siap: folder $GDRIVE_FOLDER bisa ditulis dan dihapus. Backup malam ini ikut ke Drive."
    echo "Uji restore dari Drive: sudo bash deploy/restore-check.sh"
else
    rm -f "$CONF"
    mv "$CONF.old" "$CONF" 2>/dev/null || true
    echo "Uji tulis ke Google Drive gagal; konfigurasi tidak disimpan."
    exit 1
fi

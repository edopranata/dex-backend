#!/usr/bin/env bash
# Sets the Healthchecks.io ping URL of the nightly backup (BACKUP_PING_URL in the app's offsite.env) without showing it:
# backup.sh pings it after every backup and <url>/fail when one fails, so a night without a backup alerts too.
# The URL is read from a hidden prompt, checked with one real ping, and written next to the other lines of the file
# (R2 keys stay untouched). Run as root in the backend folder:
#   sudo bash deploy/backup-ping-setup.sh
set -euo pipefail
umask 077

cd "$(dirname "$0")/.."
# shellcheck source=offsite.sh
. deploy/offsite.sh
APP=$(basename "$(dirname "$OFFSITE_ENV")")

[ "$(id -u)" -eq 0 ] || { echo "Jalankan sebagai root: sudo bash deploy/backup-ping-setup.sh"; exit 1; }

if [ -f "$OFFSITE_ENV" ] && grep -q '^BACKUP_PING_URL=.' "$OFFSITE_ENV"; then
    read -rp "$APP sudah punya BACKUP_PING_URL. Ganti? [y/N] " answer
    [[ "$answer" =~ ^[Yy]$ ]] || { echo "Tidak diubah."; exit 0; }
fi

read -rsp "URL ping Healthchecks.io untuk backup $APP (tidak tampil): " url
echo
url="${url%/}"
# offsite.sh sources this file, so only plain URL characters are accepted.
if ! [[ "$url" =~ ^https://[A-Za-z0-9._/-]+$ ]]; then
    echo "Format tidak dikenali. Contoh bentuknya: https://hc-ping.com/<uuid>. Tidak ada yang diubah."
    exit 1
fi

if ! curl -fsS -m 10 --retry 3 -o /dev/null "$url"; then
    echo "Ping uji gagal (URL salah atau check sudah dihapus). Tidak ada yang diubah."
    exit 1
fi

install -d -m 700 "$(dirname "$OFFSITE_ENV")"
tmp="$OFFSITE_ENV.new"
{
    [ -f "$OFFSITE_ENV" ] && grep -v '^BACKUP_PING_URL=' "$OFFSITE_ENV" || true
    printf 'BACKUP_PING_URL=%s\n' "$url"
} > "$tmp"
chmod 600 "$tmp"
mv "$tmp" "$OFFSITE_ENV"

echo "Ping backup $APP tersimpan di $OFFSITE_ENV, dan satu ping uji sudah diterima Healthchecks.io."
echo "Backup malam berikutnya mengirim ping sendiri; jadwal check: harian, grace sekitar 1 jam."

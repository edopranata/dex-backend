#!/usr/bin/env bash
# Backup database MySQL dari container ke folder backup host (dijalankan cron harian sebagai root).
# File ini juga terlihat & bisa diunduh di Admin > Backup (folder di-mount ke container app).
# Jika /etc/dex/offsite.env ada, salinannya juga di-upload ke Cloudflare R2 (lihat docs/DEPLOY_VPS.md).
# Restore: gunzip -c FILE.sql.gz | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
set -euo pipefail

# Dumps include password hashes: readable by the app container user (www-data, uid 82) only.
umask 077

cd "$(dirname "$0")/.."
BACKUP_DIR="${BACKUP_DIR:-$(grep -E '^BACKUP_HOST_PATH=' .env | cut -d= -f2)}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/dex}"
KEEP_DAYS="${KEEP_DAYS:-14}"
APP_UID=82
# shellcheck source=offsite.sh
. deploy/offsite.sh

notify() {
    timeout 60 docker compose exec -T app php artisan app:telegram-notify "$1" "$2" </dev/null >/dev/null 2>&1 || true
}
trap 'notify "Backup database gagal" "backup.sh berhenti di baris $LINENO ($(date "+%F %T")). Cek /var/log/dex-backup.log"' ERR

mkdir -p "$BACKUP_DIR"

FILE="$BACKUP_DIR/dex-$(date +%F-%H%M%S).sql.gz"
docker compose exec -T mysql sh -c 'exec mysqldump --single-transaction --no-tablespaces -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" 2>/dev/null' </dev/null | gzip -9 > "$FILE"
find "$BACKUP_DIR" -name 'dex-*.sql.gz' -mtime +"$KEEP_DAYS" -delete

chown -R "$APP_UID:$APP_UID" "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
chmod 600 "$BACKUP_DIR"/*.sql.gz

echo "$(date '+%F %T') backup: $FILE ($(du -h "$FILE" | cut -f1))"

# Off-site copy, once /etc/dex/offsite.env exists. The local backup above is kept either way.
if [ -f "$OFFSITE_ENV" ]; then
    NAME=$(basename "$FILE")
    STATUS="$BACKUP_DIR/offsite-status.json"
    if output=$(offsite "$BACKUP_DIR:ro" copyto "/data/$NAME" "offsite:$NAME" --stats 0 2>&1); then
        offsite_json "$STATUS" ok=true file="$NAME" size="$(stat -c %s "$FILE")" at="$(date -Iseconds)"
        echo "$(date '+%F %T') offsite: R2 $NAME"
    else
        ERROR=$(printf '%s\n' "$output" | tail -n 1)
        offsite_json "$STATUS" ok=false file="$NAME" size="$(stat -c %s "$FILE")" at="$(date -Iseconds)" error="$ERROR"
        echo "$(date '+%F %T') offsite gagal: $output"
        trap - ERR
        notify "Salinan backup ke R2 gagal" "Backup lokal $NAME aman, tetapi upload ke R2 gagal: $ERROR"
        exit 1
    fi
fi

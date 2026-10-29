#!/usr/bin/env bash
# Backup database MySQL dari container ke folder backup host (dijalankan cron harian sebagai root).
# File ini juga terlihat & bisa diunduh di Admin > Backup (folder di-mount ke container app).
# Restore: gunzip -c FILE.sql.gz | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
set -euo pipefail

# Dumps include password hashes: readable by the app container user (www-data, uid 82) only.
umask 077

cd "$(dirname "$0")/.."
BACKUP_DIR="${BACKUP_DIR:-$(grep -E '^BACKUP_HOST_PATH=' .env | cut -d= -f2)}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/dex}"
KEEP_DAYS="${KEEP_DAYS:-14}"
APP_UID=82

notify_failure() {
    timeout 60 docker compose exec -T app php artisan app:telegram-notify "Backup database gagal" \
        "backup.sh berhenti di baris $1 ($(date '+%F %T')). Cek /var/log/dex-backup.log" </dev/null >/dev/null 2>&1 || true
}
trap 'notify_failure $LINENO' ERR

mkdir -p "$BACKUP_DIR"

FILE="$BACKUP_DIR/dex-$(date +%F-%H%M%S).sql.gz"
docker compose exec -T mysql sh -c 'exec mysqldump --single-transaction --no-tablespaces -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" 2>/dev/null' </dev/null | gzip -9 > "$FILE"
find "$BACKUP_DIR" -name 'dex-*.sql.gz' -mtime +"$KEEP_DAYS" -delete

chown -R "$APP_UID:$APP_UID" "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
chmod 600 "$BACKUP_DIR"/*.sql.gz

echo "$(date '+%F %T') backup: $FILE ($(du -h "$FILE" | cut -f1))"

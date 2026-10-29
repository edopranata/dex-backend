#!/usr/bin/env bash
# Backup database MySQL dari container ke /var/backups/dex (dijalankan cron harian).
# Restore: gunzip -c FILE.sql.gz | docker compose exec -T mysql sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"'
set -euo pipefail

cd "$(dirname "$0")/.."
BACKUP_DIR="${BACKUP_DIR:-/var/backups/dex}"
KEEP_DAYS="${KEEP_DAYS:-14}"
mkdir -p "$BACKUP_DIR"

FILE="$BACKUP_DIR/dex-$(date +%F-%H%M).sql.gz"
docker compose exec -T mysql sh -c 'exec mysqldump --single-transaction --no-tablespaces -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' | gzip > "$FILE"
find "$BACKUP_DIR" -name 'dex-*.sql.gz' -mtime +"$KEEP_DAYS" -delete

echo "$(date '+%F %T') backup: $FILE ($(du -h "$FILE" | cut -f1))"

#!/usr/bin/env bash
# Backup database MySQL dari container ke folder backup host (dijalankan cron harian sebagai root).
# File ini juga terlihat & bisa diunduh di Admin > Backup (folder di-mount ke container app).
# Salinannya juga di-upload ke Google Drive dan/atau Cloudflare R2 bila diatur (lihat deploy/offsite.sh).
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

# Optional Healthchecks.io check (BACKUP_PING_URL in /etc/dex/offsite.env), so a day without any backup alerts too.
ping_backup() {
    local url
    url=$(sed -n 's/^BACKUP_PING_URL=//p' "$OFFSITE_ENV" 2>/dev/null || true)
    if [ -n "$url" ]; then
        curl -fsS -m 10 --retry 3 -o /dev/null "$url$1" || true
    fi
}
trap 'notify "Backup database gagal" "backup.sh berhenti di baris $LINENO ($(date "+%F %T")). Cek /var/log/dex-backup.log"; ping_backup /fail' ERR

mkdir -p "$BACKUP_DIR"

FILE="$BACKUP_DIR/dex-$(date +%F-%H%M%S).sql.gz"
# Container metrics are short-lived history: structure only, so a restore stays complete.
docker compose exec -T mysql sh -c 'mysqldump --single-transaction --no-tablespaces --ignore-table="$MYSQL_DATABASE.container_metrics" -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" 2>/dev/null && exec mysqldump --no-data --no-tablespaces -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" container_metrics 2>/dev/null' </dev/null | gzip -9 > "$FILE"
find "$BACKUP_DIR" -name 'dex-*.sql.gz' -mtime +"$KEEP_DAYS" -delete

chown -R "$APP_UID:$APP_UID" "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
chmod 600 "$BACKUP_DIR"/*.sql.gz

echo "$(date '+%F %T') backup: $FILE ($(du -h "$FILE" | cut -f1))"

# Off-site copies to every configured target (Google Drive, R2). The local backup above is kept either way.
TARGETS=$(offsite_targets)
if [ -n "$TARGETS" ]; then
    NAME=$(basename "$FILE")
    STATUS="$BACKUP_DIR/offsite-status.json"
    DONE=""
    FAILED=""
    for target in $TARGETS; do
        label=$(offsite_label "$target")
        if output=$(offsite "$target" "$BACKUP_DIR:ro" copyto "/data/$NAME" "offsite:$NAME" --stats 0 2>&1); then
            DONE="${DONE:+$DONE + }$label"
            echo "$(date '+%F %T') offsite: $label $NAME"
            # Drive has no lifecycle rule like the R2 buckets: old copies are removed here. A failure only logs.
            if [ "$target" = gdrive ] && ! output=$(offsite gdrive "$BACKUP_DIR:ro" delete offsite: --include 'dex-*.sql.gz' --min-age "${OFFSITE_KEEP_DAYS}d" 2>&1); then
                echo "$(date '+%F %T') offsite: pembersihan $label gagal: $output"
            fi
        else
            FAILED="${FAILED:+$FAILED; }$label: $(printf '%s\n' "$output" | tail -n 1)"
            echo "$(date '+%F %T') offsite gagal ($label): $output"
        fi
    done
    if [ -z "$FAILED" ]; then
        offsite_json "$STATUS" ok=true file="$NAME" size="$(stat -c %s "$FILE")" at="$(date -Iseconds)" bucket="$DONE"
    else
        offsite_json "$STATUS" ok=false file="$NAME" size="$(stat -c %s "$FILE")" at="$(date -Iseconds)" bucket="$DONE" error="$FAILED"
        trap - ERR
        notify "Salinan backup ke luar VPS gagal" "Backup lokal $NAME aman, tetapi upload gagal: $FAILED"
        ping_backup /fail
        exit 1
    fi
fi

ping_backup ""

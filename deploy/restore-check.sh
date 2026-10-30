#!/usr/bin/env bash
# Uji restore tanpa menyentuh database live: ambil backup terbaru dari R2 (atau file lokal yang diberikan),
# import ke database sementara dex_restore_check di container MySQL, bandingkan dengan database live,
# lalu hapus lagi. Hasilnya tampil di Admin > Backup. Jalankan sebagai root di folder backend:
#   bash deploy/restore-check.sh                         # backup terbaru di R2
#   bash deploy/restore-check.sh /var/backups/dex/FILE   # file lokal
set -euo pipefail
umask 077

cd "$(dirname "$0")/.."
BACKUP_DIR="${BACKUP_DIR:-$(grep -E '^BACKUP_HOST_PATH=' .env | cut -d= -f2)}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/dex}"
CHECK_DB=dex_restore_check
# shellcheck source=offsite.sh
. deploy/offsite.sh

WORK=$(mktemp -d)

sql() {
    docker compose exec -T mysql sh -c 'exec mysql -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" "$@" 2>/dev/null' sh "$@"
}

cleanup() {
    rm -rf "$WORK"
    sql -e "DROP DATABASE IF EXISTS $CHECK_DB" </dev/null || true
}
trap cleanup EXIT

LIVE_DB=$(docker compose exec -T mysql sh -c 'printf %s "$MYSQL_DATABASE"' </dev/null)
[ "$LIVE_DB" != "$CHECK_DB" ] || { echo "Database live bernama $CHECK_DB; batal."; exit 1; }

if [ $# -ge 1 ]; then
    SOURCE=lokal
    NAME=$(basename "$1")
    cp "$1" "$WORK/$NAME"
else
    SOURCE=R2
    NAME=$(offsite "$WORK" lsf offsite: --include 'dex-*.sql.gz' 2>/dev/null | sort | tail -1)
    [ -n "$NAME" ] || { echo "Tidak ada backup di R2."; exit 1; }
    offsite "$WORK" copyto "offsite:$NAME" "/data/$NAME" --stats 0
fi
echo "Menguji $NAME ($SOURCE, $(du -h "$WORK/$NAME" | cut -f1))"

START=$(date +%s)
sql -e "DROP DATABASE IF EXISTS $CHECK_DB; CREATE DATABASE $CHECK_DB" </dev/null
gunzip -c "$WORK/$NAME" | sql "$CHECK_DB"

count() {
    sql -e "SELECT COUNT(*) FROM $1" </dev/null
}
tables() {
    sql -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$1'" </dev/null
}

TABLES=$(tables "$CHECK_DB")
LIVE_TABLES=$(tables "$LIVE_DB")
MIGRATIONS=$(count "$CHECK_DB.migrations")
LIVE_MIGRATIONS=$(count "$LIVE_DB.migrations")
USERS=$(count "$CHECK_DB.users")
TOKENS=$(count "$CHECK_DB.tokens")
ORDERS=$(count "$CHECK_DB.orders")
SECONDS_TAKEN=$(( $(date +%s) - START ))

OK=false
if [ "$TABLES" = "$LIVE_TABLES" ] && [ "$MIGRATIONS" = "$LIVE_MIGRATIONS" ] && [ "$USERS" -gt 0 ]; then
    OK=true
fi

offsite_json "$BACKUP_DIR/restore-check.json" ok="$OK" file="$NAME" source="$SOURCE" at="$(date -Iseconds)" \
    seconds="$SECONDS_TAKEN" tables="$TABLES" live_tables="$LIVE_TABLES" migrations="$MIGRATIONS" \
    live_migrations="$LIVE_MIGRATIONS" users="$USERS" tokens="$TOKENS" orders="$ORDERS"

printf 'Tabel %s/%s · migrasi %s/%s · users %s · tokens %s · orders %s · %s detik\n' \
    "$TABLES" "$LIVE_TABLES" "$MIGRATIONS" "$LIVE_MIGRATIONS" "$USERS" "$TOKENS" "$ORDERS" "$SECONDS_TAKEN"
if [ "$OK" = true ]; then
    echo "Uji restore LOLOS. Database sementara dihapus."
else
    echo "Uji restore GAGAL: jumlah tabel/migrasi berbeda atau tidak ada pengguna."
    exit 1
fi

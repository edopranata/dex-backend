#!/usr/bin/env bash
# Pulihkan backup production ke database lokal terpisah untuk analisis, tanpa menyentuh database dev:
#   bash dev-restore.sh ../dex-2026-10-31-131827.sql.gz        (ke dex_prod)
#   bash dev-restore.sh FILE.sql.gz nama_database
# Salinannya dibersihkan supaya laptop tidak memakai pengaturan production atau menghubungi pengguna asli.
# APP_KEY production sengaja tidak disalin, jadi data terenkripsi di salinan ini tidak bisa dibaca.
set -euo pipefail
cd "$(dirname "$0")"

file="${1:?Pakai: bash dev-restore.sh FILE.sql.gz [database]}"
target="${2:-dex_prod}"

env_value() { sed -n "s/^$1=//p" .env | tail -1 | sed -E 's/^"(.*)"$/\1/'; }

[ "$(env_value APP_ENV)" = local ] || { echo "Hanya untuk APP_ENV=local." >&2; exit 1; }
[[ "$target" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Nama database tidak valid: $target" >&2; exit 1; }
[ "$target" != "$(env_value DB_DATABASE)" ] || [ "${DEX_RESTORE_OVER_CURRENT:-0}" = 1 ] || {
    echo "$target sedang dipakai aplikasi (DB_DATABASE). Isi ulang hanya jika DEX_RESTORE_OVER_CURRENT=1." >&2
    exit 1
}
gzip -t "$file"

export MYSQL_PWD
MYSQL_PWD="$(env_value DB_PASSWORD)"
db() { mysql -h "$(env_value DB_HOST)" -P "$(env_value DB_PORT)" -u "$(env_value DB_USERNAME)" "$@"; }

db -e "DROP DATABASE IF EXISTS \`$target\`; CREATE DATABASE \`$target\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# mariadb-dump (tombol "Backup sekarang") diawali baris sandbox yang ditolak klien MySQL.
gunzip -c "$file" | sed '1{/enable the sandbox mode/d;}' | db "$target"

db "$target" <<'SQL'
DELETE FROM settings;
DELETE FROM sessions;
DELETE FROM password_reset_tokens;
DELETE FROM personal_access_tokens;
DELETE FROM jobs;
DELETE FROM failed_jobs;
UPDATE users SET telegram_chat_id = NULL, telegram_username = NULL, telegram_linked_at = NULL, two_factor_secret = NULL, two_factor_recovery_codes = NULL, two_factor_confirmed_at = NULL;
SQL

db -N "$target" -e "SELECT 'users', COUNT(*) FROM users UNION ALL SELECT 'tokens', COUNT(*) FROM tokens UNION ALL SELECT 'candles', COUNT(*) FROM candles UNION ALL SELECT 'signals', COUNT(*) FROM signals UNION ALL SELECT 'orders', COUNT(*) FROM orders UNION ALL SELECT 'analyses', COUNT(*) FROM analyses UNION ALL SELECT 'migrations', COUNT(*) FROM migrations"
echo "Selesai: $target. Pakai dengan DB_DATABASE=$target di .env. Jangan jalankan scheduler/queue saat menganalisis."

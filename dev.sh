#!/usr/bin/env bash
# Jalankan seluruh stack untuk development: API, queue worker, scheduler, dan frontend.
# Ctrl+C menghentikan semuanya.
set -euo pipefail

BACKEND="$(cd "$(dirname "$0")" && pwd)"
FRONTEND="$(cd "$BACKEND/../frontend" && pwd)"
pids=()

cleanup() {
    echo ""
    echo "Menghentikan semua proses…"
    kill "${pids[@]}" 2>/dev/null || true
    wait 2>/dev/null || true
}
trap cleanup INT TERM EXIT

run() {
    local name="$1"; shift
    ( cd "$1" && shift && "$@" 2>&1 | sed -u "s/^/[$name] /" ) &
    pids+=($!)
}

run api       "$BACKEND"  php artisan serve --host=127.0.0.1 --port=8000
run queue     "$BACKEND"  php artisan queue:work redis --tries=2 --timeout=600 --sleep=2
run scheduler "$BACKEND"  php artisan schedule:work
run web       "$FRONTEND" npm run dev

echo "Frontend: http://localhost:5173  (login demo: demo@dex.test / password)"
wait

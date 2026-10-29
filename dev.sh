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
    # Horizon's supervisors are separate processes: ask the master to stop them cleanly first.
    if [ "${USE_HORIZON:-0}" = 1 ]; then
        (cd "$BACKEND" && php artisan horizon:terminate >/dev/null 2>&1) || true
    fi
    kill "${pids[@]}" 2>/dev/null || true
    wait 2>/dev/null || true
}
trap cleanup INT TERM EXIT

run() {
    local name="$1"; shift
    ( cd "$1" && shift && "$@" 2>&1 | sed -u "s/^/[$name] /" ) &
    pids+=($!)
}

# Horizon when it is installed (dashboard at /horizon), otherwise plain workers for both queues.
USE_HORIZON=0
if (cd "$BACKEND" && php artisan list --raw 2>/dev/null | grep -q '^horizon '); then
    USE_HORIZON=1
fi

run api       "$BACKEND"  php artisan serve --host=127.0.0.1 --port=8000
if [ "$USE_HORIZON" = 1 ]; then
    run horizon "$BACKEND"  php artisan horizon
else
    run queue   "$BACKEND"  php artisan queue:work redis --queue=default,market-data --tries=2 --timeout=600 --sleep=2
fi
run scheduler "$BACKEND"  php artisan schedule:work
run web       "$FRONTEND" npm run dev

echo "Frontend: http://localhost:5173  (login demo: demo@dex.test / password)"
if [ "$USE_HORIZON" = 1 ]; then
    echo "Horizon:  http://localhost:5173/horizon  (atau Admin → Horizon ↗)"
fi
wait

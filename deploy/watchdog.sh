#!/usr/bin/env bash
# Health watchdog (cron, every 2 minutes, as root). If /up fails twice in a row, bring missing containers
# back (docker compose up -d); if that is not enough, restart the web + app containers.
# Skips while a deploy holds the deploy lock. Log: /var/log/dex-watchdog.log
set -uo pipefail

main() {
    cd "$(dirname "${BASH_SOURCE[0]}")/.." || exit 1

    # Read-only open: the lock file belongs to the deploy user, and Ubuntu's fs.protected_regular stops root
    # from opening someone else's file in /tmp for writing. flock works on a read-only descriptor too.
    if [ -e /tmp/dex-deploy.lock ]; then
        exec 9</tmp/dex-deploy.lock
        flock -n 9 || exit 0
    fi

    local port url state failures
    port="$(grep -E '^WEB_PORT=' .env | cut -d= -f2)"
    url="http://127.0.0.1:${port:-8080}/up"
    state=/run/dex-watchdog.failures

    if curl -fsS -m 10 -o /dev/null "$url" 2>/dev/null; then
        rm -f "$state"
        exit 0
    fi

    failures=$(( $(cat "$state" 2>/dev/null || echo 0) + 1 ))
    echo "$failures" > "$state"
    log "health check gagal ($failures×): $url"

    [ "$failures" -lt 2 ] && exit 0

    log "memulihkan: docker compose up -d"
    docker compose up -d --remove-orphans >/dev/null 2>&1
    sleep 20

    if ! curl -fsS -m 10 -o /dev/null "$url" 2>/dev/null; then
        log "masih gagal: restart web & app"
        docker compose restart web app >/dev/null 2>&1
        sleep 15
    fi

    if curl -fsS -m 10 -o /dev/null "$url" 2>/dev/null; then
        log "pulih"
        rm -f "$state"
        notify "Watchdog: layanan sempat mati" "Health check /up gagal ${failures}× berturut-turut, sudah dipulihkan otomatis."
    else
        log "BELUM pulih, cek manual: docker compose ps && docker compose logs --tail=100 app web"
        # First failed recovery, then every ~30 minutes while it stays down.
        if [ "$failures" -eq 2 ] || [ $(( failures % 15 )) -eq 0 ]; then
            notify "Watchdog: layanan BELUM pulih" "Health check /up gagal ${failures}×. Cek: docker compose ps && docker compose logs --tail=100 app web"
        fi
    fi
}

log() {
    echo "$(date '+%F %T') $*"
}

# Best effort: needs the app container, which is exactly what may be down.
notify() {
    timeout 60 docker compose exec -T app php artisan app:telegram-notify "$1" "$2" </dev/null >/dev/null 2>&1 || true
}

main
exit

#!/usr/bin/env bash
# Tarik kode terbaru kedua repo, build image, lalu ganti container. Jalankan sebagai user deploy:
#   bash /opt/dex/backend/deploy/deploy.sh            (normal)
#   bash /opt/dex/backend/deploy/deploy.sh --no-pull  (build dari kode yang sudah ada)
# Urutan: build -> migrasi -> app/scheduler/web (situs langsung pakai versi baru) -> horizon paling akhir,
# supaya situs tidak pernah menunggu worker queue menyelesaikan job-nya.
set -euo pipefail

# Wrapped in a function so bash parses the whole script before git pull can change this file.
main() {
    local backend frontend
    backend="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    frontend="$(cd "$backend/../frontend" && pwd)"

    # One deploy at a time (pushes to both repos can arrive together). ci-deploy.sh already holds it.
    if [ "${DEX_DEPLOY_LOCKED:-0}" != "1" ]; then
        exec 9>/tmp/dex-deploy.lock
        flock 9
    fi

    if [ "${1:-}" != "--no-pull" ]; then
        git -C "$backend" pull --ff-only -q origin main
        git -C "$frontend" pull --ff-only -q origin main
    fi
    echo "backend  $(git -C "$backend" log -1 --format='%h %s')"
    echo "frontend $(git -C "$frontend" log -1 --format='%h %s')"

    cd "$backend"
    docker compose build

    docker compose up -d --wait mysql redis
    docker compose up --no-deps --exit-code-from migrate migrate
    docker compose up -d --no-deps app scheduler web

    # Queue workers last: stopping them may wait for running jobs (stop_grace_period), the site doesn't care.
    docker compose up -d --no-deps horizon
    docker compose up -d --remove-orphans
    docker image prune -f >/dev/null

    local port url
    port="$(grep -E '^WEB_PORT=' .env | cut -d= -f2)"
    url="http://127.0.0.1:${port:-8080}/up"
    for _ in $(seq 1 30); do
        if curl -fsS -o /dev/null "$url"; then
            docker compose ps --format 'table {{.Service}}\t{{.Status}}'
            echo "Deploy selesai: aplikasi sehat ($url)."
            return 0
        fi
        sleep 2
    done

    echo "Aplikasi tidak merespons di $url" >&2
    docker compose logs --tail=50 app web >&2
    return 1
}

main "$@"
exit

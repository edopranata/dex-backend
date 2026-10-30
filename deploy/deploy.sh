#!/usr/bin/env bash
# Tarik kode terbaru kedua repo, build image, lalu ganti container. Jalankan sebagai user deploy:
#   bash /opt/dex/backend/deploy/deploy.sh            (normal)
#   bash /opt/dex/backend/deploy/deploy.sh --no-pull  (build dari kode yang sudah ada)
#   bash /opt/dex/backend/deploy/deploy.sh --force    (deploy walau commit sama dengan deploy terakhir)
# Urutan: build -> migrasi -> app/web (situs langsung pakai versi baru) -> scheduler -> horizon paling akhir,
# supaya situs tidak pernah menunggu scheduler atau worker queue berhenti.
set -euo pipefail

# Wrapped in a function so bash parses the whole script before git pull can change this file.
main() {
    local backend frontend pull=1 force=0 arg
    backend="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    frontend="$(cd "$backend/../frontend" && pwd)"
    # Commits of the last successful deploy: a queued deploy with nothing new is skipped.
    local stamp="$backend/../.deployed"

    for arg in "$@"; do
        case "$arg" in
            --no-pull) pull=0 ;;
            --force) force=1 ;;
        esac
    done

    # One deploy at a time (pushes to both repos can arrive together). ci-deploy.sh already holds it.
    if [ "${DEX_DEPLOY_LOCKED:-0}" != "1" ]; then
        exec 9>/tmp/dex-deploy.lock
        flock 9
    fi

    if [ "$pull" = 1 ]; then
        local script="$backend/deploy/deploy.sh" before
        before="$(sha1sum "$script")"
        git -C "$backend" pull --ff-only -q origin main
        git -C "$frontend" pull --ff-only -q origin main

        # This run was parsed from the old file: when the pull changed it, continue with the new one.
        if [ "$(sha1sum "$script")" != "$before" ]; then
            echo "deploy.sh berubah, lanjut dengan versi terbaru."
            DEX_DEPLOY_LOCKED=1 exec bash "$script" --no-pull "$@"
        fi
    fi

    local commits
    commits="$(git -C "$backend" rev-parse HEAD) $(git -C "$frontend" rev-parse HEAD)"
    echo "backend  $(git -C "$backend" log -1 --format='%h %s')"
    echo "frontend $(git -C "$frontend" log -1 --format='%h %s')"

    if [ "$force" = 0 ] && [ "$commits" = "$(cat "$stamp" 2>/dev/null)" ]; then
        echo "Commit ini sudah ter-deploy sebelumnya, tidak ada yang perlu dibangun ulang."
        return 0
    fi

    cd "$backend"
    step "Build image" docker compose build
    step "Database & Redis" docker compose up -d --wait mysql redis
    step "Migrasi" docker compose up --no-deps --exit-code-from migrate migrate
    step "Ganti app & web" docker compose up -d --no-deps app web
    # On its own: a running schedule:run finishes its minute (up to the 10 s stop timeout), and in the same
    # command the new app container would wait for it, taking the site down meanwhile.
    step "Ganti scheduler" docker compose up -d --no-deps scheduler

    # Queue workers last: stopping them may wait for running jobs (stop_grace_period), the site doesn't care.
    step "Ganti horizon" docker compose up -d --no-deps horizon
    step "Sinkron sisa container" docker compose up -d --remove-orphans
    docker image prune -f >/dev/null

    local port url
    port="$(grep -E '^WEB_PORT=' .env | cut -d= -f2)"
    url="http://127.0.0.1:${port:-8080}/up"
    for _ in $(seq 1 30); do
        if curl -fsS -o /dev/null "$url"; then
            echo "$commits" > "$stamp"
            # Keep the bot's webhook and "/" command menu in step with the deployed code (non-fatal).
            docker compose exec -T app php artisan app:telegram-sync </dev/null || echo "Sinkron Telegram gagal (deploy tetap berhasil)." >&2
            docker compose ps --format 'table {{.Service}}\t{{.Status}}'
            echo "Deploy selesai dalam ${SECONDS} detik: aplikasi sehat ($url)."
            return 0
        fi
        sleep 2
    done

    echo "Aplikasi tidak merespons di $url" >&2
    docker compose logs --tail=50 app web >&2
    return 1
}

# Run one deploy step and report how long it took (shown in the GitHub Actions log).
step() {
    local name="$1" started=$SECONDS
    shift
    echo "▶ $name"
    "$@"
    echo "✓ $name ($(( SECONDS - started )) detik)"
}

main "$@"
exit

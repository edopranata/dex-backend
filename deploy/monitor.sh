#!/usr/bin/env bash
# Runs on the host (systemd: dex-monitor.service) and sends container status and resource use to the app.
# The app has no access to Docker; it answers with the interval chosen in Admin > Pengaturan.
# Only the fields below leave Docker: never the whole inspect output, which holds environment secrets.
set -uo pipefail

INSPECT_FORMAT='{"name":{{json .Name}},"project":{{json (index .Config.Labels "com.docker.compose.project")}},"service":{{json (index .Config.Labels "com.docker.compose.service")}},"state":{{json .State.Status}},"health":{{if .State.Health}}{{json .State.Health.Status}}{{else}}null{{end}},"exit_code":{{.State.ExitCode}},"restarts":{{.RestartCount}},"started_at":{{json .State.StartedAt}}}'

# Wrapped in a function so bash parses the whole script before git pull can change this file.
main() {
    local backend token port url interval=15 started answer remaining
    backend="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    token="$(grep -E '^MONITOR_TOKEN=' "$backend/.env" | cut -d= -f2-)"
    port="$(grep -E '^WEB_PORT=' "$backend/.env" | cut -d= -f2)"
    url="http://127.0.0.1:${port:-8080}/api/internal/container-metrics"

    if [ -z "$token" ]; then
        echo "MONITOR_TOKEN belum ada di .env" >&2
        exit 1
    fi

    while :; do
        started=$SECONDS

        if answer="$(sample | curl -fsS -m 10 -X POST "$url" -H 'Content-Type: application/json' -H 'Accept: application/json' -H @<(printf 'Authorization: Bearer %s\n' "$token") --data-binary @-)"; then
            answer="$(jq -r '.interval_seconds // empty' <<<"$answer" 2>/dev/null)"
            if [[ "$answer" =~ ^[0-9]+$ ]] && [ "$answer" -ge 10 ] && [ "$answer" -le 60 ]; then
                interval=$answer
            fi
        fi

        remaining=$(( interval - (SECONDS - started) ))
        sleep $(( remaining > 1 ? remaining : 1 ))
    done
}

sample() {
    local inspect stats total available disk_total disk_used load
    inspect="$(docker ps -aq | xargs -r docker inspect --format "$INSPECT_FORMAT")"
    stats="$(timeout 8 docker stats --no-stream --format '{{json .}}')"
    total=$(awk '/^MemTotal:/ {print $2 * 1024}' /proc/meminfo)
    available=$(awk '/^MemAvailable:/ {print $2 * 1024}' /proc/meminfo)
    read -r disk_total disk_used < <(df -B1 --output=size,used / | tail -1)
    read -r load _ < /proc/loadavg

    jq -n --arg inspect "$inspect" --arg stats "$stats" \
        --argjson total "$total" --argjson available "$available" \
        --argjson disk_total "$disk_total" --argjson disk_used "$disk_used" \
        --argjson load "$load" --argjson cores "$(nproc)" \
        '{inspect: $inspect, stats: $stats, host: {memory_total: $total, memory_available: $available, disk_total: $disk_total, disk_used: $disk_used, load: $load, cores: $cores}}'
}

main "$@"
exit

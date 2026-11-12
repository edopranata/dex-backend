#!/usr/bin/env bash
# Forced command for the GitHub Actions SSH key (see server-setup.sh): the key can run this and nothing else.
# The workflow asks for "deploy <backend|frontend> <commit>", the commit whose tests or build just passed. Only that
# repo moves, and only up to that commit, so a deploy of one repo never ships untested code of the other (12 Nov
# 2026: a frontend deploy pulled backend main while its tests had failed). A plain "deploy" keeps the old behaviour.
# Takes the deploy lock first (pushes to both repos arrive together), updates the backend first when it is the one
# deploying so a changed deploy.sh takes effect in the same run, then hands over while keeping the lock.
set -euo pipefail

main() {
    cd "$(dirname "${BASH_SOURCE[0]}")/.."

    local request="${SSH_ORIGINAL_COMMAND:-deploy}" repo="" commit=""

    if [[ "$request" =~ ^deploy\ (backend|frontend)\ ([0-9a-f]{40})$ ]]; then
        repo="${BASH_REMATCH[1]}"
        commit="${BASH_REMATCH[2]}"
    elif [ "$request" != "deploy" ]; then
        echo "Perintah tidak dikenal: $request" >&2
        exit 2
    fi

    exec 9>/tmp/dex-deploy.lock
    flock 9

    if [ -z "$repo" ]; then
        git pull --ff-only -q origin main
        DEX_DEPLOY_LOCKED=1 exec bash deploy/deploy.sh
    fi

    if [ "$repo" = "backend" ]; then
        git fetch -q origin main
        # An older commit than the one already here is a no-op, never a step back.
        git merge --ff-only -q "$commit"
    fi

    DEX_DEPLOY_LOCKED=1 exec bash deploy/deploy.sh "--ref=$repo:$commit"
}

main
exit

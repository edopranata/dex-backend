#!/usr/bin/env bash
# Forced command for the GitHub Actions SSH key (see server-setup.sh): the key can run this and nothing else.
# Takes the deploy lock first (pushes to both repos arrive together), updates the backend repo so a
# changed deploy.sh takes effect in the same run, then hands over while keeping the lock.
set -euo pipefail

main() {
    cd "$(dirname "${BASH_SOURCE[0]}")/.."

    exec 9>/tmp/dex-deploy.lock
    flock 9

    git pull --ff-only -q origin main
    DEX_DEPLOY_LOCKED=1 exec bash deploy/deploy.sh
}

main
exit

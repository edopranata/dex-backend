#!/usr/bin/env bash
# Forced command for the GitHub Actions SSH key (see server-setup.sh): the key can run this and nothing else.
# Updates the backend repo first so a changed deploy.sh takes effect in the same run.
set -euo pipefail

main() {
    cd "$(dirname "${BASH_SOURCE[0]}")/.."
    git pull --ff-only -q
    exec bash deploy/deploy.sh
}

main
exit

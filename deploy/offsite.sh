# Shared by backup.sh, restore-check.sh and gdrive-setup.sh (source it): rclone in a throwaway container, so nothing
# is installed on the host. Two off-site targets, each used once it is configured:
#
# Google Drive (since 22 Nov 2026): one rclone config for every app on this VPS, /etc/offsite/rclone.conf (root, 600),
# remote [gdrive] with scope drive.file (rclone sees only the files it created). Written by deploy/gdrive-setup.sh.
# Copies go to gdrive:simulator-backup/dex and are removed there after OFFSITE_KEEP_DAYS (Drive has no lifecycle rule).
#
# Cloudflare R2: keys in /etc/dex/offsite.env (root, 600), never in .env, which every app container receives:
#   R2_ACCESS_KEY_ID=...
#   R2_SECRET_ACCESS_KEY=...
#   R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
#   R2_BUCKET=dex-bucket
#   BACKUP_PING_URL=https://hc-ping.com/<uuid>   (opsional, Healthchecks.io; berlaku juga tanpa R2)
# The R2 buckets delete copies after 30 days by their own lifecycle rule.

OFFSITE_ENV="${OFFSITE_ENV:-/etc/dex/offsite.env}"
GDRIVE_CONF_DIR="${GDRIVE_CONF_DIR:-/etc/offsite}"
GDRIVE_FOLDER="simulator-backup/dex"
OFFSITE_KEEP_DAYS="${OFFSITE_KEEP_DAYS:-30}"
RCLONE_IMAGE="rclone/rclone:1.76.0"

# offsite_targets: the configured targets, one per line, Google Drive first.
offsite_targets() {
    if [ -f "$GDRIVE_CONF_DIR/rclone.conf" ] && grep -q '^\[gdrive\]' "$GDRIVE_CONF_DIR/rclone.conf"; then
        echo gdrive
    fi
    if [ -f "$OFFSITE_ENV" ] && grep -q '^R2_ACCESS_KEY_ID=.' "$OFFSITE_ENV"; then
        echo r2
    fi
}

# offsite_label TARGET: a short name for logs and Admin > Backup.
offsite_label() {
    if [ "$1" = gdrive ]; then
        echo "Google Drive $GDRIVE_FOLDER"
    else
        echo "R2 $(sed -n 's/^R2_BUCKET=//p' "$OFFSITE_ENV" 2>/dev/null || true)"
    fi
}

# offsite TARGET HOST_DIR[:ro] ARGS...: run rclone with HOST_DIR mounted at /data; the target is the remote "offsite:".
# R2 keys are passed to docker by name only, so they never show up in the process list. The Drive config directory is
# mounted writable, because rclone saves the refreshed access token there.
offsite() (
    target=$1
    shift
    dir=${1%:ro}
    mode=""
    [ "$dir" != "$1" ] && mode=":ro"
    shift
    if [ "$target" = gdrive ]; then
        export RCLONE_CONFIG_OFFSITE_TYPE=alias RCLONE_CONFIG_OFFSITE_REMOTE="gdrive:$GDRIVE_FOLDER"
        docker run --rm -v "$dir:/data$mode" -v "$GDRIVE_CONF_DIR:/config/rclone" \
            -e RCLONE_CONFIG_OFFSITE_TYPE -e RCLONE_CONFIG_OFFSITE_REMOTE \
            "$RCLONE_IMAGE" "$@" --drive-use-trash=false --retries 3 --low-level-retries 5
        return
    fi
    set -a
    # shellcheck disable=SC1090
    . "$OFFSITE_ENV"
    set +a
    export RCLONE_CONFIG_R2_TYPE=s3 RCLONE_CONFIG_R2_PROVIDER=Cloudflare RCLONE_CONFIG_R2_NO_CHECK_BUCKET=true
    export RCLONE_CONFIG_R2_ACCESS_KEY_ID="$R2_ACCESS_KEY_ID" RCLONE_CONFIG_R2_SECRET_ACCESS_KEY="$R2_SECRET_ACCESS_KEY"
    export RCLONE_CONFIG_R2_ENDPOINT="$R2_ENDPOINT"
    export RCLONE_CONFIG_OFFSITE_TYPE=alias RCLONE_CONFIG_OFFSITE_REMOTE="r2:${R2_BUCKET:-dex-bucket}"
    docker run --rm -v "$dir:/data$mode" \
        -e RCLONE_CONFIG_R2_TYPE -e RCLONE_CONFIG_R2_PROVIDER -e RCLONE_CONFIG_R2_NO_CHECK_BUCKET \
        -e RCLONE_CONFIG_R2_ACCESS_KEY_ID -e RCLONE_CONFIG_R2_SECRET_ACCESS_KEY -e RCLONE_CONFIG_R2_ENDPOINT \
        -e RCLONE_CONFIG_OFFSITE_TYPE -e RCLONE_CONFIG_OFFSITE_REMOTE \
        "$RCLONE_IMAGE" "$@" --retries 3 --low-level-retries 5
)

# offsite_json FILE KEY=VALUE...: write a small status file for Admin > Backup, readable by the app (uid 82).
# Values are strings unless they are true/false or a number.
offsite_json() {
    local file=$1 pair key value json=""
    shift
    for pair in "$@"; do
        key=${pair%%=*}
        value=${pair#*=}
        if [[ ! $value =~ ^(true|false|-?[0-9]+)$ ]]; then
            value="\"$(printf '%s' "$value" | tail -c 400 | tr -d '\000-\037' | sed 's/\\/\\\\/g; s/"/\\"/g')\""
        fi
        json+="${json:+,}\"$key\":$value"
    done
    printf '{%s}\n' "$json" > "$file"
    chown 82:82 "$file"
    chmod 600 "$file"
}

# Shared by backup.sh and restore-check.sh (source it): rclone against Cloudflare R2 in a throwaway
# container, so nothing is installed on the host.
#
# Keys live in /etc/dex/offsite.env (root, 600), never in .env, which every app container receives:
#   R2_ACCESS_KEY_ID=...
#   R2_SECRET_ACCESS_KEY=...
#   R2_ENDPOINT=https://<account-id>.r2.cloudflarestorage.com
#   R2_BUCKET=dex-bucket

OFFSITE_ENV="${OFFSITE_ENV:-/etc/dex/offsite.env}"
RCLONE_IMAGE="rclone/rclone:1.68.2"

# offsite HOST_DIR[:ro] ARGS...: run rclone with HOST_DIR mounted at /data; the bucket is the remote "offsite:".
# Keys are passed to docker by name only, so they never show up in the process list.
offsite() (
    dir=${1%:ro}
    mode=""
    [ "$dir" != "$1" ] && mode=":ro"
    shift
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

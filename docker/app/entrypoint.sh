#!/bin/sh
# Cache config/routes from the container's environment, then hand over to the real command.
set -e

# The app container publishes public/ (built frontend, favicon, ...) to the volume nginx serves from.
# Assets of earlier builds stay a week, so browser tabs opened before a deploy can still load them.
if [ -n "${PUBLISH_PUBLIC_TO:-}" ]; then
    cp -R public/. "$PUBLISH_PUBLIC_TO"/
    find "$PUBLISH_PUBLIC_TO/assets" -type f -mtime +7 2>/dev/null | while read -r file; do
        [ -e "public/assets/$(basename "$file")" ] || rm -f "$file"
    done
fi

php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan event:cache --no-interaction >/dev/null

exec "$@"

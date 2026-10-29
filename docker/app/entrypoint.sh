#!/bin/sh
# Cache config/routes from the container's environment, then hand over to the real command.
set -e

php artisan config:cache --no-interaction >/dev/null
php artisan route:cache --no-interaction >/dev/null
php artisan event:cache --no-interaction >/dev/null

exec "$@"

# Build context is the folder that holds both repositories side by side:
#   <root>/backend   (github.com/edopranata/dex-backend, contains this file)
#   <root>/frontend  (github.com/edopranata/dex-frontend)
#
# One Dockerfile, three targets:
#   frontend  build the Vue app into backend/public (npm run build:laravel)
#   app       Laravel on PHP-FPM, including the built frontend (also runs queue + scheduler)
#   web       Nginx serving backend/public and passing everything else to PHP-FPM

FROM node:22-alpine AS frontend
WORKDIR /build/frontend
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY frontend/ ./
RUN mkdir -p ../backend/public && npm run build:laravel


FROM php:8.4-fpm-alpine AS app

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql redis bcmath intl opcache pcntl zip \
    # mariadb-dump for "Backup sekarang" in the admin panel (connector-c adds MySQL 8 auth plugins)
    && apk add --no-cache mariadb-client mariadb-connector-c

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependencies first so code changes don't invalidate this layer.
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY backend/ ./
COPY --from=frontend /build/backend/public/ ./public/
RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/backups bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY backend/docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY backend/docker/app/fpm.conf /usr/local/etc/php-fpm.d/zz-pool.conf
COPY backend/docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

USER www-data

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]


FROM nginx:1.27-alpine AS web
COPY backend/docker/web/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public

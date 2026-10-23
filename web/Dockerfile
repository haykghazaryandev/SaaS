# syntax=docker/dockerfile:1
# Bookball (Laravel 12 + Inertia/Vue) image for Railway.
# Stages: composer deps -> Vite assets (needs vendor/tightenco/ziggy) -> FrankenPHP runtime.
# Railway builds this from the GitHub repo (service root directory = web/); the four
# Railway services (web, worker, scheduler, reverb) all run this image (see .railway/railway.ts).

# ---------- Stage 1: PHP dependencies ----------
FROM composer:2 AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --no-autoloader --ignore-platform-reqs

# ---------- Stage 2: front-end assets ----------
FROM node:22-alpine AS assets
WORKDIR /app

# Vite bakes these into the JS bundle. Railway passes service variables to the
# build as build args, but only when declared with ARG in the stage that uses them.
ARG VITE_APP_NAME=Bookball
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# resources/js/app.ts imports ZiggyVue from vendor/tightenco/ziggy.
COPY --from=deps /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
COPY resources ./resources
COPY lang ./lang
COPY public ./public
COPY vite.config.ts tsconfig.json tailwind.config.js components.json ./
RUN npm run build

# ---------- Stage 3: PHP runtime ----------
FROM dunglas/frankenphp:1-php8.3 AS app

RUN install-php-extensions pdo_pgsql pgsql redis intl bcmath zip gd pcntl opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY --from=deps /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# Optimised autoloader (post-autoload-dump scripts run package:discover + filament:upgrade,
# which publishes the gitignored Filament assets), then the env-independent caches.
# config:cache is NOT done here: it depends on the Railway variables (docker/entrypoint.sh).
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && php artisan route:cache \
    && php artisan view:cache \
    && php artisan event:cache \
    && mkdir -p storage/app/public storage/app/private storage/logs \
                storage/framework/cache/data storage/framework/sessions storage/framework/views \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh

# Production php.ini plus opcache tuned for a read-only deploy.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'opcache.enable=1\nopcache.validate_timestamps=0\nopcache.memory_consumption=192\nopcache.max_accelerated_files=20000\nupload_max_filesize=20M\npost_max_size=20M\n' \
       > "$PHP_INI_DIR/conf.d/zz-bookball.ini"

# Caddy config: serves public/ and proxies Reverb's /app and /apps paths to the reverb service.
COPY docker/Caddyfile /etc/caddy/Caddyfile
ENV SERVER_ROOT=/app/public
EXPOSE 8080

# The entrypoint picks the process: web (default), worker, scheduler or reverb.
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["web"]

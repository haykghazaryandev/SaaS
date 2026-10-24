#!/bin/sh
# Container entrypoint for Railway. One image, four processes, chosen by the first argument:
#   web        FrankenPHP serving public/ on $PORT (default CMD)
#   worker     php artisan queue:work
#   scheduler  php artisan schedule:work
#   reverb     php artisan reverb:start on $PORT
# Anything else is executed as given (e.g. "php artisan migrate --force").
#
# Railway start commands run in exec form and replace the Dockerfile ENTRYPOINT, so
# the worker/scheduler/reverb services use "/app/docker/entrypoint.sh worker" etc.
set -e

cd /app

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Run 'php artisan key:generate --show' and add it to the Railway service variables." >&2
    exit 1
fi

# Storage symlink for public uploads (public/storage -> storage/app/public).
[ -L public/storage ] || php artisan storage:link --force >/dev/null 2>&1 || true

# Settings file for this environment (e.g. .env.staging), chosen by Railway's environment name
# (set by Railway itself) or APP_ENV. Railway variables still win over the file.
ENV_NAME="${RAILWAY_ENVIRONMENT_NAME:-$APP_ENV}"
if [ ! -f .env ] && [ -n "$ENV_NAME" ] && [ -f ".env.$ENV_NAME" ]; then
    cp ".env.$ENV_NAME" .env
    echo "Settings file: .env.$ENV_NAME"
else
    echo "Settings file: none for '$ENV_NAME' (only Railway variables)"
fi

# Config from Railway variables (and the settings file above).
# View/event caches are built into the image (Dockerfile); config and routes depend on env.
php artisan config:cache
# routes after config: Livewire's endpoint (/livewire-<hash>, the admin panel) is derived from APP_KEY
php artisan route:cache

# The web process prepares everything before it serves: migrations, seeders (they only add
# what is missing; production gets only the admin) and the Telegram webhook + bot menu.
# Safe on every start. DEPLOY_ON_START=false turns it off, SEED_ON_DEPLOY=false skips seeding.
if [ "${1:-web}" = "web" ] && [ "${DEPLOY_ON_START:-true}" = "true" ]; then
    php artisan app:deploy
fi

PORT="${PORT:-8080}"

case "${1:-web}" in
    web)
        # Caddy listens on the port Railway assigns; Railway's edge terminates TLS.
        export SERVER_NAME=":$PORT"
        exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile
        ;;
    worker)
        # No --max-time: there is no supervisor here, so the process must not exit on its own.
        # Railway restarts it on failure, and `php artisan queue:restart` after a deploy is
        # unnecessary because every deploy starts a fresh container.
        exec php artisan queue:work --tries=3 --backoff=10 --sleep=1
        ;;
    scheduler)
        exec php artisan schedule:work
        ;;
    reverb)
        # [::] binds IPv6 and IPv4: Railway's private network (reverb.railway.internal) is IPv6.
        exec php artisan reverb:start --host='[::]' --port="$PORT"
        ;;
    *)
        exec "$@"
        ;;
esac

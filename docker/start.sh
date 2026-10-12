#!/bin/sh
# Container start-up: prepare configuration, migrate the database, start the scheduler and Apache.
set -e
cd /var/www/html

# Render's generateValue produces a base64-encoded 256-bit key; Laravel expects the "base64:" prefix.
if [ -n "${APP_KEY:-}" ] && [ "${APP_KEY#base64:}" = "$APP_KEY" ]; then
    export APP_KEY="base64:${APP_KEY}"
fi
# Use the public address Render assigns unless APP_URL was set explicitly.
if [ -z "${APP_URL:-}" ] && [ -n "${RENDER_EXTERNAL_URL:-}" ]; then
    export APP_URL="$RENDER_EXTERNAL_URL"
fi

# Writable runtime folders (the container filesystem starts fresh on every deploy/restart).
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private

# Database schema (safe to run on every start). Retries while a new database is still starting.
attempt=1
until php artisan migrate --force --no-interaction; do
    if [ "$attempt" -ge 10 ]; then
        echo "Database not reachable after $attempt attempts." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    echo "Waiting for the database (attempt $attempt)…" >&2
    sleep 5
done

# Optional fictional demo data for test deployments only (refused when APP_ENV=production).
if [ "${LOAD_DEMO_DATA:-false}" = "true" ]; then
    php artisan db:seed --class=DemoSeeder --force --no-interaction || echo "Demo data not loaded." >&2
fi

php artisan optimize --no-interaction
# Apache runs as www-data and must be able to write compiled views, cache, sessions and uploads.
chown -R www-data:www-data storage bootstrap/cache

# Scheduler (expires reservations, refreshes alerts) runs alongside the web server.
su -s /bin/sh www-data -c "php artisan schedule:work --no-interaction" > /proc/1/fd/1 2>&1 &

exec apache2-foreground

#!/bin/sh
set -eu
if [ -z "${APP_KEY:-}" ]; then echo "ERROR: APP_KEY is required and must remain persistent." >&2; exit 1; fi
for variable in DB_HOST DB_DATABASE DB_USERNAME; do eval "value=\${$variable:-}"; if [ -z "$value" ]; then echo "ERROR: $variable is required." >&2; exit 1; fi; done
mkdir -p /media /config storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chown www-data:www-data /media /config 2>/dev/null || true
if ! su -s /bin/sh www-data -c 'test -w /media && test -w /config'; then echo "ERROR: /media and /config must be writable by uid 33." >&2; exit 1; fi
attempt=1
until php artisan migrate --force --no-interaction; do
    if [ "$attempt" -ge 12 ]; then echo "ERROR: Database unavailable after 12 attempts." >&2; exit 1; fi
    echo "Database unavailable; retrying in 5 seconds ($attempt/12)..." >&2; attempt=$((attempt+1)); sleep 5
done
php artisan optimize
exec "$@"

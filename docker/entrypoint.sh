#!/bin/sh
set -eu
PUID="${PUID:-1000}"
PGID="${PGID:-1000}"
UMASK="${UMASK:-002}"
case "$PUID:$PGID" in *[!0-9:]*) echo "ERROR: PUID and PGID must be numeric." >&2; exit 1 ;; esac
umask "$UMASK"
groupmod -o -g "$PGID" www-data
usermod -o -u "$PUID" -g "$PGID" www-data

mkdir -p /media /config storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chown www-data:www-data /media /config 2>/dev/null || true
if ! su -s /bin/sh www-data -c 'test -w /media && test -w /config'; then echo "ERROR: /media and /config must be writable by PUID $PUID and PGID $PGID." >&2; exit 1; fi

if [ -z "${APP_KEY:-}" ]; then
    key_file=/config/app.key
    if [ -s "$key_file" ]; then
        APP_KEY="$(cat "$key_file")"
    else
        APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        printf '%s\n' "$APP_KEY" > "$key_file"
        chmod 0600 "$key_file"
        chown www-data:www-data "$key_file" 2>/dev/null || true
        echo "Generated a persistent Laravel encryption key in /config/app.key."
    fi
    export APP_KEY
fi

DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_CONNECTION
case "$DB_CONNECTION" in
    sqlite)
        DB_DATABASE="${DB_DATABASE:-/config/auroraarchive.sqlite}"
        export DB_DATABASE
        case "$DB_DATABASE" in /config/*) ;; *) echo "ERROR: SQLite DB_DATABASE must be inside persistent /config." >&2; exit 1 ;; esac
        touch "$DB_DATABASE"
        chown www-data:www-data "$DB_DATABASE" 2>/dev/null || true
        ;;
    mysql|mariadb)
        for variable in DB_HOST DB_DATABASE DB_USERNAME; do eval "value=\${$variable:-}"; if [ -z "$value" ]; then echo "ERROR: $variable is required for $DB_CONNECTION." >&2; exit 1; fi; done
        ;;
    *) echo "ERROR: DB_CONNECTION must be sqlite, mysql, or mariadb." >&2; exit 1 ;;
esac

attempt=1
php artisan config:clear
until php artisan migrate --force --no-interaction; do
    if [ "$attempt" -ge 12 ]; then echo "ERROR: Database unavailable after 12 attempts." >&2; exit 1; fi
    echo "Database unavailable; retrying in 5 seconds ($attempt/12)..." >&2; attempt=$((attempt+1)); sleep 5
done
php artisan optimize
exec "$@"

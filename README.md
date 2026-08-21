# AuroraArchive

AuroraArchive is a private YouTube archiver and local streaming library built on Laravel 13. One production container contains nginx, PHP-FPM, the database queue worker, scheduler, yt-dlp, Deno, FFmpeg, and Supervisor. The only external service is MySQL/MariaDB.

## Portainer

Create one container:

- Image: `ghcr.io/mxr156/auroraarchive:latest`
- Port: host `8080` → container `80`
- Volumes: `/host/media` → `/media`; `/host/config` → `/config`
- Restart policy: `unless-stopped`
- Environment: copy the production values from `.env.example`

Required values are `APP_URL`, `APP_KEY`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. Keep `APP_KEY` unchanged because it encrypts YouTube credentials. Generate it once with an existing PHP checkout:

```sh
php artisan key:generate --show
```

Alternatively, after building the image locally:

```sh
docker run --rm --entrypoint php ghcr.io/mxr156/auroraarchive:latest artisan key:generate --show
```

Startup validates required configuration and writable mounts, retries database access for up to one minute, runs `php artisan migrate --force`, optimizes Laravel, then starts all supervised processes. Logs are sent to Docker stdout/stderr.

## Development and testing

```sh
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
composer run dev
php artisan test --compact
vendor/bin/pint --format agent
npm run build
```

## TubeSync analysis

Configure `TUBESYNC_DB_*` with a database account that has SELECT-only permission, then run:

```sh
php artisan tubesync:import --dry-run
```

The command only queries `information_schema` and reports available tables. A full mapping requires TubeSync `SHOW CREATE TABLE` output for its source and media tables, plus the old library-root to `/media` path mapping.

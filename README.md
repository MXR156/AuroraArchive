# AuroraArchive

AuroraArchive is a private YouTube archiver and local streaming library built on Laravel 13. One production container contains nginx, PHP-FPM, the database queue worker, scheduler, yt-dlp, Deno, FFmpeg, and Supervisor. It can use either a persistent SQLite database in `/config` or an external MySQL/MariaDB server.

## Portainer

Create one container:

- Image: `ghcr.io/mxr156/auroraarchive:latest`
- Port: host `8080` → container `80`
- Volumes: `/host/media` → `/media`; `/host/config` → `/config`
- Restart policy: `unless-stopped`
- Environment: copy the production values from `.env.example`

`APP_URL` and `APP_KEY` are always required. Keep `APP_KEY` unchanged because it encrypts YouTube credentials. Generate it once with an existing PHP checkout:

```sh
php artisan key:generate --show
```

Alternatively, after building the image locally:

```sh
docker run --rm --entrypoint php ghcr.io/mxr156/auroraarchive:latest artisan key:generate --show
```

### Database option 1: persistent SQLite

This is the default and requires no external database server:

```env
DB_CONNECTION=sqlite
DB_DATABASE=/config/auroraarchive.sqlite
DB_BUSY_TIMEOUT=5000
DB_JOURNAL_MODE=WAL
```

The `/config` volume is mandatory in this mode. Startup creates the database file when absent, and migrations run automatically. WAL mode and a busy timeout are enabled for safe web/worker/scheduler concurrency. Back up the host directory mapped to `/config`.

### Database option 2: external MySQL/MariaDB

```env
DB_CONNECTION=mysql
DB_HOST=192.168.1.20
DB_PORT=3306
DB_DATABASE=auroraarchive
DB_USERNAME=auroraarchive
DB_PASSWORD=your-password
```

Startup validates the selected database configuration, retries database access for up to one minute, runs `php artisan migrate --force`, optimizes Laravel, then starts all supervised processes. Logs are sent to Docker stdout/stderr.

## Pulling the private image

The included GitHub Actions workflow publishes the image using the repository's built-in `GITHUB_TOKEN`. Because the source repository and its linked package are private, Portainer must authenticate to GHCR before it can pull the image.

1. In GitHub, create a **personal access token (classic)** with `read:packages` permission.
2. In Portainer, open **Registries**, add a custom registry, and use:
   - Registry URL: `ghcr.io`
   - Username: `MXR156`
   - Password/token: the personal access token
3. Select that registry when creating the container and use `ghcr.io/mxr156/auroraarchive:latest`.

The token only needs package-read access for deployment. Do not put it in AuroraArchive's container environment; Portainer stores it as registry authentication.

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

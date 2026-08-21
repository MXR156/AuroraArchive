# AuroraArchive

AuroraArchive is a private YouTube archiver and local streaming library built on Laravel 13. One production container contains nginx, PHP-FPM, the database queue worker, scheduler, yt-dlp, Deno, FFmpeg, and Supervisor. It uses persistent SQLite by default or an optional external MySQL/MariaDB server.

## Simple Docker deployment

The built-in SQLite deployment only needs three environment variables:

```env
PUID=1000
PGID=1000
TZ=Europe/London
```

Run it with:

```sh
docker run \
  -d \
  --name auroraarchive \
  -e PUID=1000 \
  -e PGID=1000 \
  -e TZ=Europe/London \
  -v /some/directory/auroraarchive-config:/config \
  -v /some/directory/auroraarchive-media:/media \
  -p 8080:80 \
  --stop-timeout 7200 \
  ghcr.io/mxr156/auroraarchive:latest
```

AuroraArchive generates its Laravel encryption key on first startup and stores it in `/config/app.key`. The key is reused automatically after container updates or recreation. Supplying `APP_KEY` manually remains supported but is not required.

The `/config` volume contains the encryption key, SQLite database, and persistent configuration. `/media` contains downloaded media and may point to local or NAS storage. Keep `/config` on local storage when using SQLite.

## Portainer

- Image: `ghcr.io/mxr156/auroraarchive:latest`
- Port: host `8080` to container `80`
- Volumes: `/host/config` to `/config`; `/host/media` to `/media`
- Environment: `PUID`, `PGID`, and `TZ`
- Restart policy: `unless-stopped`
- Stop timeout: `7200` seconds

## External MySQL/MariaDB

Add these variables when using an external database:

```env
DB_CONNECTION=mysql
DB_HOST=192.168.1.20
DB_PORT=3306
DB_DATABASE=auroraarchive
DB_USERNAME=auroraarchive
DB_PASSWORD=your-password
```

The normal `PUID`, `PGID`, and `TZ` variables are still used. Startup validates the selected database, retries access, runs safe pending migrations, and then starts all supervised processes.

## Pulling the private image

The GitHub Actions workflow publishes the image using the repository's built-in `GITHUB_TOKEN`. For a private package, configure Portainer with a **Custom registry**:

- Registry URL: `ghcr.io`
- Username: `MXR156`
- Password: a classic GitHub PAT with `read:packages`

Then select that registry and deploy `mxr156/auroraarchive:latest`. Registry credentials belong in Portainer, not the AuroraArchive environment.

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

Configure `TUBESYNC_DB_*` with a SELECT-only database account, then run:

```sh
php artisan tubesync:import --dry-run
```

The command only inspects the TubeSync schema. A full mapping requires `SHOW CREATE TABLE` output for its source and media tables plus the old library-root to `/media` path mapping.

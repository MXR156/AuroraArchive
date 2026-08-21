# syntax=docker/dockerfile:1.7
FROM node:24-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
RUN npm run build

FROM composer:2 AS dependencies
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --no-scripts

FROM php:8.5-fpm-bookworm
ARG TARGETARCH
LABEL org.opencontainers.image.source="https://github.com/MXR156/AuroraArchive" \
      org.opencontainers.image.title="AuroraArchive" \
      org.opencontainers.image.description="A private, self-hosted YouTube archiver and local streaming library."
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx supervisor ffmpeg curl unzip procps passwd libzip-dev libicu-dev ca-certificates \
    && rm -rf /var/lib/apt/lists/*

# PHP 8.5 already includes PDO SQLite and OPcache. Reinstalling OPcache fails
# because it is built into PHP 8.5 rather than emitted as a shared module.
RUN docker-php-ext-install -j1 pdo_mysql intl pcntl zip

RUN case "$TARGETARCH" in amd64) YTDLP_ARCH=""; DENO_ARCH="x86_64" ;; arm64) YTDLP_ARCH="_aarch64"; DENO_ARCH="aarch64" ;; *) exit 1 ;; esac \
    && curl -fsSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux${YTDLP_ARCH}" -o /usr/local/bin/yt-dlp \
    && chmod 0755 /usr/local/bin/yt-dlp \
    && curl -fsSL "https://github.com/denoland/deno/releases/latest/download/deno-${DENO_ARCH}-unknown-linux-gnu.zip" -o /tmp/deno.zip \
    && unzip /tmp/deno.zip -d /usr/local/bin && chmod 0755 /usr/local/bin/deno \
    && apt-get purge -y --auto-remove unzip \
    && rm -rf /var/lib/apt/lists/* /tmp/deno.zip /var/www/html/*
WORKDIR /var/www/html
ENV APP_NAME=AuroraArchive \
    APP_ENV=production \
    APP_DEBUG=false \
    APP_URL=http://localhost \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=info \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/config/auroraarchive.sqlite \
    DB_BUSY_TIMEOUT=5000 \
    DB_JOURNAL_MODE=WAL \
    DB_SYNCHRONOUS=NORMAL \
    DB_TRANSACTION_MODE=IMMEDIATE \
    QUEUE_CONNECTION=database \
    DB_QUEUE_RETRY_AFTER=7500 \
    CACHE_STORE=database \
    SESSION_DRIVER=database \
    MEDIA_ROOT=/media \
    AURORAARCHIVE_CONFIG_ROOT=/config \
    TZ=Europe/London \
    PUID=1000 \
    PGID=1000 \
    UMASK=002
COPY --from=dependencies /app/vendor ./vendor
COPY . .
COPY --from=frontend /app/public/build ./public/build
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/auroraarchive.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/auroraarchive.ini
COPY docker/entrypoint.sh /usr/local/bin/auroraarchive-entrypoint
RUN rm -f bootstrap/cache/*.php && php artisan package:discover --ansi \
    && chmod 0755 /usr/local/bin/auroraarchive-entrypoint \
    && mkdir -p /media /config storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data /var/www/html /media /config
EXPOSE 80
VOLUME ["/media", "/config"]
HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 CMD curl -fsS http://127.0.0.1/up || exit 1
ENTRYPOINT ["auroraarchive-entrypoint"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]

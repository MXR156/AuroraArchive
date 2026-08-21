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
RUN apt-get update && apt-get install -y --no-install-recommends nginx supervisor ffmpeg curl unzip procps libzip-dev libicu-dev libsqlite3-dev ca-certificates \
    && docker-php-ext-install pdo_mysql pdo_sqlite intl opcache pcntl zip \
    && case "$TARGETARCH" in amd64) YTDLP_ARCH=""; DENO_ARCH="x86_64" ;; arm64) YTDLP_ARCH="_aarch64"; DENO_ARCH="aarch64" ;; *) exit 1 ;; esac \
    && curl -fsSL "https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp_linux${YTDLP_ARCH}" -o /usr/local/bin/yt-dlp \
    && chmod 0755 /usr/local/bin/yt-dlp \
    && curl -fsSL "https://github.com/denoland/deno/releases/latest/download/deno-${DENO_ARCH}-unknown-linux-gnu.zip" -o /tmp/deno.zip \
    && unzip /tmp/deno.zip -d /usr/local/bin && chmod 0755 /usr/local/bin/deno \
    && apt-get purge -y --auto-remove unzip \
    && rm -rf /var/lib/apt/lists/* /tmp/deno.zip /var/www/html/*
WORKDIR /var/www/html
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

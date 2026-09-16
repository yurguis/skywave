# syntax=docker/dockerfile:1

# ---- Composer dependencies -------------------------------------------------
FROM composer:2.10.3 AS vendor
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-autoloader

COPY src ./src
RUN composer dump-autoload --no-dev --optimize --no-interaction

# ---- Runtime: nginx + php-fpm, non-root -----------------------------------
FROM php:8.5.10-fpm-alpine3.24

# ffmpeg is for live playback; build with --build-arg WITH_FFMPEG=0 to leave it out.
ARG WITH_FFMPEG=1

# tini reaps the detached ffmpeg processes live playback starts.
RUN apk add --no-cache nginx tini \
    && if [ "$WITH_FFMPEG" = "1" ]; then apk add --no-cache ffmpeg; fi \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && rm -f /usr/local/etc/php-fpm.d/*.conf \
    && mkdir -p /data && chown www-data:www-data /data

COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/www.conf
COPY docker/php.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/nginx.conf.template /etc/hdhomerun/nginx.conf.template
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/hdhomerun-entrypoint

WORKDIR /app
COPY --from=vendor /app/vendor ./vendor
COPY composer.json analyze.php hdhomerun.php ./
COPY src ./src
COPY public ./public
COPY tools ./tools

USER www-data

# The guide database; mount a volume at /data to keep it across containers.
ENV HTTP_PORT=8080 \
    GUIDE_DB=/data/guide.sqlite
EXPOSE 8080

# The php-fpm base image stops containers with SIGQUIT; the entrypoint shell (and the
# simulator) expect SIGTERM, which Docker otherwise only follows with a SIGKILL.
STOPSIGNAL SIGTERM

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD wget -q -O /dev/null "http://127.0.0.1:${HTTP_PORT}/fpm-ping" || exit 1

ENTRYPOINT ["/sbin/tini", "--", "hdhomerun-entrypoint"]

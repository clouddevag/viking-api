# Laravel API — single container, for platforms that route one HTTP port per
# service (Railway, Render, Fly).
#
# The docker-compose image splits php-fpm and nginx across two containers,
# which those platforms cannot express. FrankenPHP is one process that speaks
# HTTP directly, so the same image serves the API, the queue worker and the
# Reverb websocket server with only the start command changed.

# ---------------------------------------------------------------- stage: deps
FROM composer:2 AS vendor

WORKDIR /app

# Copied first so a source-only change does not invalidate the dependency layer.
COPY backend/composer.json backend/composer.lock ./

RUN composer install \
      --no-dev \
      --no-scripts \
      --no-autoloader \
      --prefer-dist \
      --no-interaction

COPY backend/ .

RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# -------------------------------------------------------------- stage: runtime
FROM dunglas/frankenphp:1-php8.4-alpine AS runtime

# `install-php-extensions` ships with the FrankenPHP images and resolves the
# build headers itself, so there is no .build-deps dance here.
RUN install-php-extensions \
      pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache sockets redis

WORKDIR /app

COPY --from=vendor /app /app
COPY docker/php/php.ini /usr/local/etc/php/conf.d/viking.ini
COPY docker/railway/entrypoint.sh /usr/local/bin/entrypoint

RUN chmod +x /usr/local/bin/entrypoint \
    && mkdir -p storage/framework/cache/data storage/framework/sessions \
               storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

# Railway terminates TLS at its edge, so Caddy must not try to obtain its own
# certificate — it would fail the healthcheck while waiting on ACME.
ENV CADDY_GLOBAL_OPTIONS="auto_https off"

# FrankenPHP serves $SERVER_ROOT relative to the working directory.
ENV SERVER_ROOT=public/

# Overridden by the platform; the entrypoint rewrites SERVER_NAME from $PORT.
ENV PORT=8080

ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

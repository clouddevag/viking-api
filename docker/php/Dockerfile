# Laravel API — PHP 8.4 FPM.
#
# Two stages: composer resolves dependencies with the full toolchain, the
# runtime image carries only what PHP needs to serve. Keeps the final image
# free of composer, git and build headers.

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
FROM php:8.4-fpm-alpine AS runtime

# gd needs the image headers; intl needs icu; redis and pcntl are required by
# the queue worker and Reverb.
RUN apk add --no-cache \
      icu-libs libzip libpng libjpeg-turbo freetype oniguruma \
    && apk add --no-cache --virtual .build-deps \
      $PHPIZE_DEPS icu-dev libzip-dev libpng-dev libjpeg-turbo-dev \
      freetype-dev oniguruma-dev linux-headers \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
      pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache sockets \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear

WORKDIR /var/www/html

COPY --from=vendor /app /var/www/html

COPY docker/php/php.ini /usr/local/etc/php/conf.d/viking.ini
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

# www-data already exists in the base image; only ownership needs fixing.
RUN mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data

EXPOSE 9000

ENTRYPOINT ["entrypoint"]
CMD ["php-fpm"]

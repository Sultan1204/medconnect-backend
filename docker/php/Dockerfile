# syntax=docker/dockerfile:1.7
#
# MedConnect Saudi - PHP runtime image.
#   target "dev"  : used by compose.yaml, source code is bind-mounted.
#   target "prod" : self-contained image with code + optimized autoloader.

ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-fpm-alpine AS base

RUN set -eux; \
    apk add --no-cache icu-libs libpq libzip fcgi tzdata; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl bcmath pcntl posix zip opcache; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf

ENV TZ=UTC
WORKDIR /var/www/html

HEALTHCHECK --interval=30s --timeout=5s --retries=3 \
    CMD SCRIPT_NAME=/ping SCRIPT_FILENAME=/ping REQUEST_METHOD=GET cgi-fcgi -bind -connect 127.0.0.1:9000 || exit 1

# ---------------------------------------------------------------------------
FROM base AS dev
COPY docker/php/conf.d/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
USER www-data

# ---------------------------------------------------------------------------
FROM base AS prod
COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY --chown=www-data:www-data . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && rm -f .env \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data

# syntax=docker/dockerfile:1.7
#
# MedConnect Saudi - single-container image for temporary hosted demos (Render etc.).
# nginx + php-fpm + queue worker + scheduler under supervisord.
# For real production use the split images in docker/php (see docs/deployment.md).
ARG PHP_VERSION=8.3

FROM php:${PHP_VERSION}-fpm-alpine

RUN set -eux; \
    apk add --no-cache icu-libs libpq libzip tzdata nginx supervisor; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev libzip-dev linux-headers; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl bcmath pcntl posix zip opcache; \
    pecl install redis; \
    docker-php-ext-enable redis; \
    apk del .build-deps; \
    rm -rf /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/conf.d/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/conf.d/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY docker/php/php-fpm.d/zz-app.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/render/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/render/supervisord.conf /etc/supervisord.conf
COPY docker/render/start.sh /usr/local/bin/start.sh

ENV TZ=UTC
WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev \
    && rm -f .env \
    && chmod +x /usr/local/bin/start.sh \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 8080
CMD ["/usr/local/bin/start.sh"]

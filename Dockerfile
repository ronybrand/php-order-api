# syntax=docker/dockerfile:1
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

FROM php:8.5-fpm-alpine
RUN apk add --no-cache --virtual .build-deps postgresql-dev linux-headers $PHPIZE_DEPS \
    && apk add --no-cache libpq \
    && docker-php-ext-install pdo_pgsql sockets \
    && apk del .build-deps
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /var/www
COPY --from=vendor /app /var/www
RUN mkdir -p storage/framework/cache storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache
USER www-data
EXPOSE 9000
CMD ["php-fpm"]

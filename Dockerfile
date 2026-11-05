# syntax=docker/dockerfile:1
FROM composer@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

FROM php@sha256:9236fd373d3bcda0659cf94838a744bd79791419292958963f8c6b34a0071554
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

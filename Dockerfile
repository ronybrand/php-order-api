# syntax=docker/dockerfile:1
# The tag rides along with the digest: it names the image the digest belongs to (Composer 2), which a bare digest
# hides from anyone reading the file.
FROM composer:2@sha256:da624c2c705d54e2f5a9bb77a5dc6c7d091711433ebefd7035672e84c2010fee AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-scripts

# The tag rides along with the digest: it names the image the digest belongs to (PHP 8.5, FPM, Alpine) and the variant
# the apk and docker-php-ext-install calls below are written for.
FROM php:8.5-fpm-alpine@sha256:f1bf3e9358dcde762d06df6d54fb213344491a2b31db0aef456ffe6274c91dc6
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

FROM php:7.4-alpine

RUN set -ex \
    apk update && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS && \
    pecl update-channels && \
    pecl install redis-5.3.4 && \
    docker-php-ext-enable redis && \
    apk del .build-deps && \
    rm -rf /var/cache/apk/* /tmp/*

WORKDIR "/var/www/"

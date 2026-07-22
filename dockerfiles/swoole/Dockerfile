ARG SWOOLE_VERSION=6.2
ARG PHP_VERSION=8.5

FROM phpswoole/swoole:${SWOOLE_VERSION}-php${PHP_VERSION}-alpine

# PHP extension Redis and pdo_mysql are already installed.
RUN set -ex \
    apk update && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS && \
    docker-php-ext-install mysqli && \
    apk del .build-deps && \
    rm -rf /var/cache/apk/*

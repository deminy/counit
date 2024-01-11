FROM phpswoole/swoole:4.8-php7.4-alpine

RUN set -ex \
    apk update && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS && \
    pecl update-channels && \
    pecl install redis-stable && \
    docker-php-ext-enable redis && \
    docker-php-ext-install mysqli pdo_mysql && \
    apk del .build-deps && \
    rm -rf /var/cache/apk/*

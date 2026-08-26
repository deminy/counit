ARG SWOOLE_VERSION=6.2
ARG PHP_VERSION=8.5

FROM phpswoole/swoole:${SWOOLE_VERSION}-php${PHP_VERSION}-alpine

# PHP extension Redis and pdo_mysql are already installed. pcntl is installed so PHPUnit's
# --enforce-time-limit (which needs pcntl_alarm()) can be exercised locally; see the
# "Compatibility with PHPUnit" section of README.md.
RUN set -ex \
    apk update && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS && \
    docker-php-ext-install mysqli pcntl && \
    apk del .build-deps && \
    rm -rf /var/cache/apk/*

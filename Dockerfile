FROM phpswoole/swoole:4.7-php7.4-alpine

RUN set -ex \
    apk update && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS && \
    pecl update-channels && \
    pecl install redis-5.3.4 && \
    docker-php-ext-enable redis && \
    echo "swoole.use_shortname=Off" >> /usr/local/etc/php/conf.d/docker-php-ext-swoole.ini && \
    apk del .build-deps && \
    rm -rf /var/cache/apk/* /tmp/*

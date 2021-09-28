#!/usr/bin/env php
<?php
/**
 * This script is used to start a Dockerized web server for testing the curl extension under PHPUnit/counit. The web
 * server can send slow HTTP responses back to the client.
 *
 * @see https://github.com/deminy/counit/blob/master/docker-compose.yml
 */

declare(strict_types=1);

$http = new Swoole\Http\Server('0.0.0.0', 9501);
$http->on(
    'request',
    function (Swoole\Http\Request $request, Swoole\Http\Response $response) {
        $seconds = (int) ($request->get['seconds'] ?? 0);
        if ($seconds > 0) {
            Swoole\Coroutine::sleep($seconds);
        }
        $response->end('OK');
    }
);
$http->start();

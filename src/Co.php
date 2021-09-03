<?php

declare(strict_types=1);

namespace Deminy\Counit;

use Swoole\Coroutine;

/**
 * This class allows unit tests to run in parallel (using Swoole) or in blocking mode (default behavior).
 */
class Co
{
    public static function go(callable $callable)
    {
        if (Coroutine::getCid() === -1) {
            $callable();
            return 1;
        }
        $id = Coroutine::create($callable);
        return $id > 0 ? $id : false;
    }

    public static function sleep(int $seconds)
    {
        if (Coroutine::getCid() === -1) {
            \sleep($seconds);
        } else {
            Coroutine::sleep($seconds);
        }
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit;

use Swoole\Coroutine;

/**
 * This class allows unit tests to run in parallel (using Swoole) or in blocking mode (default behavior).
 */
class Counit
{
    /**
     * @return int return 0 if not running inside a coroutine; otherwise, return the coroutine ID, or -1 when failed
     *             creating a new coroutine to run the tests
     */
    public static function create(callable $callable): int
    {
        if (Coroutine::getCid() === -1) {
            $callable();
            return 0;
        }
        $id = Coroutine::create($callable);
        return ($id !== false) ? $id : -1;
    }

    public static function sleep(int $seconds): void
    {
        if (Coroutine::getCid() === -1) {
            \sleep($seconds);
        } else {
            Coroutine::sleep($seconds);
        }
    }
}

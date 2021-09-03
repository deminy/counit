<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase;
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
        if (self::runningWithCounit()) {
            $id = Coroutine::create($callable);
            return ($id !== false) ? $id : -1;
        }

        $callable();
        return 0;
    }

    public static function sleep(int $seconds): void
    {
        if (self::runningWithCounit()) {
            Coroutine::sleep($seconds);
        } else {
            \sleep($seconds);
        }
    }

    /**
     * To suppress warning message "This test did not perform any assertions", and to make the counters match.
     */
    public static function addToAssertionCount(TestCase $test, int $count): void
    {
        if (self::runningWithCounit()) {
            $test->addToAssertionCount($count);
        }
    }

    protected static function runningWithCounit(): bool
    {
        return Coroutine::getCid() !== -1;
    }
}

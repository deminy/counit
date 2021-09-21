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
     * @param int $count an optional parameter to suppress warning message "This test did not perform any assertions",
     *                   and to make the counters match
     * @return int return 0 if not running inside a coroutine; otherwise, return the coroutine ID, or -1 when failed
     *             creating a new coroutine to run the tests
     */
    public static function create(callable $callable, int $count = 0): int
    {
        if (self::runningWithCounit()) {
            if ($count > 0) {
                $trace = debug_backtrace();
                if (!empty($trace[1]['object']) && ($trace[1]['object'] instanceof TestCase)) {
                    $test = $trace[1]['object'];
                    /* @var TestCase $test */
                    $test->addToAssertionCount($count);
                } else {
                    throw new Exception(sprintf('Method "%s" should be called directly in a test method of a %s object.', __METHOD__, TestCase::class));
                }
            }

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

    protected static function runningWithCounit(): bool
    {
        return Coroutine::getCid() !== -1;
    }
}

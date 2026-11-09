<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for PHPUnit 13.4's --timeout, a time limit for the whole test run. PHPUnit
 * checks the deadline as each test finishes and arms an alarm for the remaining time while a test
 * runs; under counit a test "finished" at its first yield, so the limit never fired. While
 * --timeout is set, every test is now joined (see VerdictSequencing). Run by the compatibility
 * workflow with `--timeout 3`: the first test passes, the second is aborted when the run's limit
 * is exceeded, and the run exits with code 124 -- in both modes.
 *
 * @internal
 * @coversNothing
 */
class RunTimeLimitTest extends TestCase
{
    public function testFirst(): void
    {
        sleep(2);
        self::assertTrue(true);
    }

    public function testSecond(): void
    {
        sleep(2);
        self::assertTrue(true);
    }
}

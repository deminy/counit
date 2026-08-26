<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for --enforce-time-limit in the manual approach: while enforcement is active,
 * Counit::create() joins the coroutine at its first yield, so PHPUnit's pcntl_alarm() guard times
 * the real test duration and the timeout is reported natively -- risky verdict, --fail-on-risky
 * honored, no deferred end-of-run block. Run by the compatibility workflow with
 * `--enforce-time-limit --default-time-limit=1`, which asserts the exact blocking-mode summary
 * with and without Swoole. See TimeLimitAutomaticTest for the automatic-approach counterpart and
 * the reasoning behind the generous sleep durations.
 *
 * @internal
 * @coversNothing
 */
class TimeLimitManualTest extends TestCase
{
    public function testWithinLimit(): void
    {
        Counit::create(function (): void {
            self::assertTrue(true);
        }, 1);
    }

    public function testExceedsDefaultLimit(): void
    {
        Counit::create(function (): void {
            Counit::sleep(3);

            // Must never be reached under a 1-second limit; see TimeLimitAutomaticTest.
            self::assertTrue(true);
        }, 1);
    }
}

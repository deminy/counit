<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a warning expectation satisfied only after the test's first yield.
 * PHPUnit 8/9 convert warnings to exceptions through an error handler that TestResult::run()
 * registers around runBare() -- on the main coroutine. Without the expectation-join, that
 * handler was already unregistered by the time the test's coroutine resumed, so the warning was
 * never converted and the test failed with a false "Warning is thrown" report. The join keeps
 * TestResult::run() waiting -- handler still registered -- so the warning converts and the
 * expectation verifies exactly as in blocking mode. Uses expectException() with PHPUnit's
 * Warning class, the spelling available across the whole PHPUnit 8.0-9.6 range. Run by the
 * compatibility workflow, which asserts the exact summary and exit code, identically with and
 * without Swoole.
 *
 * @internal
 * @coversNothing
 */
class WarningAfterYieldTest extends TestCase
{
    public function testExpectedWarningAfterYield(): void
    {
        $this->expectException(\PHPUnit\Framework\Error\Warning::class);
        sleep(1);
        trigger_error('delayed warning', E_USER_WARNING);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * The manual approach's counterpart of PostYieldDiagnosticsTest: the callable runs in a
 * coroutine of its own, so its post-yield diagnostics fire while PHPUnit's converting handler
 * -- registered by TestResult::run() on the main coroutine -- is long unregistered; counit's
 * delegating handler covers them identically (see Diagnostics). The compatibility workflow
 * asserts each mode's exact output: blocking converts at the call site and errors the tests
 * (exit code 2), Swoole routes the same converted exceptions through the deferred-failure block
 * (exit code 1) -- and above all the run FAILS in both, where it used to pass silently.
 *
 * @internal
 * @coversNothing
 */
class PostYieldDiagnosticsManualTest extends TestCase
{
    public function testNoticeAfterYield(): void
    {
        Counit::create(function (): void {
            Counit::sleep(1);

            trigger_error('late user notice (manual)', E_USER_NOTICE);

            self::assertTrue(true);
        }, 1);
    }

    public function testWarningAfterYield(): void
    {
        Counit::create(function (): void {
            Counit::sleep(1);

            trigger_error('late user warning (manual)', E_USER_WARNING);

            self::assertTrue(true);
        }, 1);
    }
}

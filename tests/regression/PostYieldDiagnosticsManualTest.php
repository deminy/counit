<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * The manual approach's counterpart of PostYieldDiagnosticsTest: the callable runs in a
 * coroutine of its own, so its post-yield diagnostics fire while the main coroutine -- and
 * PHPUnit's converting handler window -- have long moved on; counit's delegating handler covers
 * them identically (see Diagnostics). Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary with and without Swoole, plus the --fail-on-deprecation/--fail-on-warning
 * exit codes.
 *
 * @internal
 * @coversNothing
 */
class PostYieldDiagnosticsManualTest extends TestCase
{
    public function testDeprecationAfterYield(): void
    {
        Counit::create(function (): void {
            Counit::sleep(1);

            trigger_error('late user deprecation (manual)', E_USER_DEPRECATED);

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

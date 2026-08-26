<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the "This test did not perform any assertions" risky check in the manual
 * approach: the requested credit (`Counit::create(..., 1)`) used to unconditionally hide a test
 * that genuinely performs no assertions. See UselessTestTest for the mechanics; the same
 * credit-decline and deferred-verdict paths apply here through the shared Counit::create() seam.
 * Run by the compatibility workflow in three flag states, asserting the exact blocking-mode
 * summaries and exit codes with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class UselessTestManualTest extends TestCase
{
    public function testNoAssertionsAfterYield(): void
    {
        Counit::create(function (): void {
            Counit::sleep(1);
        }, 1);
    }

    public function testAssertsAfterYield(): void
    {
        Counit::create(function (): void {
            Counit::sleep(1);

            self::assertTrue(true);
        }, 1);
    }
}

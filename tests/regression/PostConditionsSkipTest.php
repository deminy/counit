<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the skip-on-failure half of the manual-approach post-condition semantics:
 * blocking PHPUnit runs the post-condition phase only when the test body succeeded -- a body
 * Throwable jumps straight from runTest() to runBare()'s catch ladder. Under counit, before the
 * fix, a body failing only after a yield was reported as passed (the failure could merely be
 * deferred to the end-of-run block) and the phase ran anyway. With the join, the body's failure
 * propagates out of Counit::create() synchronously: the test fails natively with the BODY's
 * message, the hook below must never run, and no deferred block appears. Run by the compatibility
 * workflow, which asserts exactly that (exit code, summary, message, absence of the deferred
 * marker) with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class PostConditionsSkipTest extends TestCase
{
    protected function assertPostConditions(): void
    {
        self::fail('post-conditions must not run after a failed body');
    }

    public function testBodyFailsAfterYield(): void
    {
        Counit::create(function (): void {
            Counit::sleep(1);

            self::fail('late failure');
        }, 1);
    }
}

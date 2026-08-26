<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for counit's core exit-code guarantee: a test failing only after a sleep/IO
 * yield was already reported as passed by PHPUnit, so the failure can only surface through the
 * deferred end-of-run block -- and that block MUST force exit code 1. The guarantee silently
 * broke when the exit-code alignment (the warnings-only correction) was added after the deferred
 * check: with the failure living only in counit's deferred list, the TestResult still read
 * all-successful and the alignment flipped the forced 1 back to 0 -- a green exit for a failing
 * run. The compatibility workflow asserts both mode-specific outcomes: a native failure in
 * blocking mode, the deferred block plus exit 1 under Swoole.
 *
 * @internal
 * @coversNothing
 */
class LateFailureTest extends TestCase
{
    public function testFailsOnlyAfterYield(): void
    {
        usleep(200000);

        self::assertTrue(false, 'deliberate failure after the first yield');
    }
}

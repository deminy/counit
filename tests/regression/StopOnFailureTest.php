<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for --stop-on-failure (standing in for the whole --stop-on-* family). The
 * option makes PHPUnit decide before each test whether to start it at all -- under counit a
 * post-yield failure used to become known only after the whole loop had finished, so the run
 * never stopped: every test ran, and the failure surfaced only at the end. While any
 * verdict-sequencing option is active, counit now joins every test at its first yield (see
 * VerdictSequencing), so the first test's failure is final before the second test is scheduled
 * and PHPUnit stops natively, exactly as in blocking mode. Run by the compatibility workflow
 * with mode-identical assertions (the declaration order guarantees the failing test runs
 * first).
 *
 * @internal
 * @coversNothing
 */
class StopOnFailureTest extends TestCase
{
    public function testAFailsAfterYield(): void
    {
        self::assertTrue(true);
        sleep(1);
        self::fail('deliberate failure after the yield');
    }

    public function testBNeverReached(): void
    {
        self::assertTrue(true);
        sleep(1);
    }

    public function testCNeverReached(): void
    {
        self::assertTrue(true);
        sleep(1);
    }
}

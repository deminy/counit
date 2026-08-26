<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a skip signalled from tearDown() after the test body passed. Upstream
 * PHPUnit 8/9 honors it as a GENUINE skip -- the run exits 0 -- unlike PHPUnit 10+, where such
 * a skip fails the test (which is why the 1.x branch routes it into the deferred FAILURE block
 * with exit code 1). This branch must never adopt that treatment: a hook skip stays benign
 * here. Run by the compatibility workflow, which asserts exit code 0 and the exact
 * blocking-mode summary in BOTH modes -- the pre-yield skip is honored natively, the post-yield
 * one through the late-skip replay into the TestResult (Skipped: 2, no notice).
 *
 * @internal
 * @coversNothing
 */
class TearDownSkipTest extends TestCase
{
    protected function tearDown(): void
    {
        if (strpos($this->getName(false), 'SkipFromTearDown') !== false) {
            $this->markTestSkipped('deliberate skip from tearDown()');
        }
    }

    public function testSyncSkipFromTearDown(): void
    {
        self::assertTrue(true);
    }

    public function testPostYieldSkipFromTearDown(): void
    {
        sleep(1);
        self::assertTrue(true);
    }
}

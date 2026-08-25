<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a skip signalled from tearDown() after the test body passed: blocking
 * PHPUnit FAILS such a test (SkippedWithMessageException is an AssertionFailedError, caught by
 * runBare()'s tearDown-phase handling), it never becomes a skip. Under the coroutine runner the
 * test's verdict was already emitted at the body's first yield, so the closest match is the
 * deferred-failure path: the skip is reported after the summary and forces exit code 1 -- it
 * must not be dropped silently. Run by the compatibility workflow, which asserts each mode's
 * expected output and that both exit non-zero.
 *
 * @internal
 * @coversNothing
 */
class TearDownSkipTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->name() === 'testSkipFromTearDown') {
            $this->markTestSkipped('deliberate skip from tearDown()');
        }
    }

    public function testSkipFromTearDown(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    public function testUnaffected(): void
    {
        self::assertTrue(true);
    }
}

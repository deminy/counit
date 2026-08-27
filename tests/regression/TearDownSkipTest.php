<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a skip signalled from tearDown() after the test body passed: blocking
 * PHPUnit FAILS such a test (SkippedWithMessageException is an AssertionFailedError, caught by
 * runBare()'s tearDown-phase handling), it never becomes a skip. Under the coroutine runner the
 * test's verdict was already emitted at the body's first yield; the relocated hook's Throwable
 * is now replayed as PHPUnit's own Test\Failed event (see LateFailures -- the
 * AssertionFailedError classification is upstream's own), so the summary, listing and exit code
 * match blocking mode exactly. Run by the compatibility workflow with mode-identical
 * assertions.
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

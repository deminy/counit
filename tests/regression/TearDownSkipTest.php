<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a skip signalled from tearDown() after the test body passed. PHPUnit up to
 * 12.5.42 / 13.4.5 FAILS such a test (SkippedWithMessageException is an AssertionFailedError,
 * caught by the tearDown-phase handling); as of 12.5.43 / 13.4.6 it is reported as skipped. Under
 * the coroutine runner the test's verdict was already emitted at the body's first yield; the
 * relocated hook's outcome is replayed as PHPUnit's own event (Test\Failed through LateFailures,
 * or Test\Skipped through LateSkips), following whichever rule the installed PHPUnit applies, so
 * the summary, listing and exit code match blocking mode. Run by the compatibility workflow with
 * mode-identical assertions.
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

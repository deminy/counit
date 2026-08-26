<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Deliberately failing counterpart of MockExpectationTest: an expectation that is genuinely
 * never satisfied must still FAIL the test natively -- with PHPUnit's own message, after the
 * truly finished body, never through the deferred end-of-run block. (Before the fix this shape
 * failed coincidentally right: the premature verification at the first yield already saw the
 * "never invoked" end state.) Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary, message and exit code with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class MockFailureTest extends TestCase
{
    public function testGenuinelyNeverInvoked(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(3);

        usleep(200000);
    }
}

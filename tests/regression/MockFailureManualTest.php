<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Deliberately failing counterpart of MockExpectationManualTest: an expectation that is
 * genuinely never satisfied must still FAIL the test natively -- with PHPUnit's own message,
 * after the truly finished body, never through the deferred end-of-run block. (Before the fix
 * this shape failed coincidentally right: the premature verification at the first yield already
 * saw the "called 0 times" end state.) Run by the compatibility workflow, which asserts the
 * exact blocking-mode summary, message and exit code with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class MockFailureManualTest extends TestCase
{
    public function testGenuinelyNeverInvoked(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(3);

        Counit::create(function (): void {
            Counit::sleep(1);
        }, 1);
    }
}

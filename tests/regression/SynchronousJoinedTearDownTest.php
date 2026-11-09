<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for tearDown() running twice. A test whose body finishes before any yield and
 * is then joined -- here because of a mock with an invocation-count expectation -- used to get its
 * relocated hooks replayed inside the coroutine AND handed back to PHPUnit, which ran them again.
 * A body that never yields now hands its hooks back to PHPUnit only. Run by the compatibility
 * workflow with mode-identical assertions: the second test checks that tearDown() ran once.
 *
 * @internal
 * @coversNothing
 */
class SynchronousJoinedTearDownTest extends TestCase
{
    private static int $tearDowns = 0;

    protected function tearDown(): void
    {
        if ($this->name() === 'testMockExpectationMetWithoutYield') {
            self::$tearDowns++;
        }
    }

    public function testMockExpectationMetWithoutYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(1);

        self::assertSame(1, $mock->count());
    }

    public function testTearDownRanOnce(): void
    {
        self::assertSame(1, self::$tearDowns, 'tearDown() must run exactly once for the previous test.');
    }
}

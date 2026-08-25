<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * PHPUnit counts a mock object's verification (one assertion per mock that has matchers) on the
 * test object itself -- verifyMockObjects() does $this->numAssertions++ -- instead of through its
 * static assertion counter. Under counit that verification happens inside the test's coroutine,
 * after PHPUnit already read the test's count, so without counit's AssertionCountListener those
 * assertions would silently disappear from the run's reported total.
 *
 * @internal
 * @coversNothing
 */
class MockVerificationAutomaticTest extends TestCase
{
    /**
     * The mock is only called after the test yielded, so PHPUnit verifies it inside the coroutine,
     * long after it read this test's assertion count. Two assertions: the assertSame() below and
     * the mock verification.
     */
    public function testDelayedMockVerificationIsCounted(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(42);

        sleep(1);

        self::assertCount(42, $mock);
    }

    /**
     * The same test without a yield: everything happens before PHPUnit reads the count, so this one
     * is counted the same way with and without counit -- it guards against over-correcting.
     */
    public function testImmediateMockVerificationIsCounted(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(42);

        self::assertCount(42, $mock);
    }
}

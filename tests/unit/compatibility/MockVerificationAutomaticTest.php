<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * A test that has registered a mock carrying an invocation-count rule is joined at its first
 * yield (see MockExpectations), so PHPUnit's ->expects() verification runs against the truly
 * finished body. These tests pin the assertion-counting halves of that: the verification's
 * assertion is counted exactly once, as under blocking PHPUnit, whether the body yields (the
 * joined path) or runs to completion synchronously (the native path).
 *
 * @internal
 * @coversNothing
 */
class MockVerificationAutomaticTest extends TestCase
{
    /**
     * Two assertions: the assertCount() and the mock verification. The mock makes the test join
     * at its yield, so both are counted natively, after the finished body; neither the credit nor
     * the late-count correction may count them a second time.
     */
    public function testMockSatisfiedBeforeYieldIsCounted(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(42);

        self::assertCount(42, $mock);

        sleep(1);
    }

    /**
     * The same without a yield: everything happens before PHPUnit reads the count. Guards against
     * over-correcting on the synchronous path.
     */
    public function testImmediateMockVerificationIsCounted(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(42);

        self::assertCount(42, $mock);
    }
}

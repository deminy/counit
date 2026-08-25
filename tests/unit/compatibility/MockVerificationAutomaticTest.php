<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Mock ->expects() verification runs on the main coroutine as soon as the test body first yields,
 * so a mock must be satisfied before the first yield (a call made only after a sleep/IO yield is
 * verified too early and fails -- a documented limitation). These tests pin the supported pattern:
 * verification of an already-satisfied mock passes and its assertion is counted exactly as under
 * blocking PHPUnit, whether or not the body yields afterwards.
 *
 * @internal
 * @coversNothing
 */
class MockVerificationAutomaticTest extends TestCase
{
    /**
     * Two assertions: the assertCount() and the mock verification. The verification happens at the
     * body's first yield -- before PHPUnit reports the test -- so it is counted within the test's
     * own window; the late-count correction must not count it a second time.
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

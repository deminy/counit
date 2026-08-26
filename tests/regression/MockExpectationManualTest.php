<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for mock ->expects() in the manual approach: such a test's runBare() is
 * PHPUnit's own, running on the main coroutine, so verifyMockObjects() used to fire at the
 * callable's first yield -- an expectation satisfied only later failed prematurely, and (because
 * a passing verification also strips the mock's invocation mocker) a violation after the yield
 * passed silently. A test with a registered mock carrying a matcher is now joined at its first
 * yield (see MockExpectations), so PHPUnit verifies the truly finished body. The last test pins
 * why the join covers matcher-less doubles too: PHPUnit 8/9 verify-and-reset EVERY registered
 * mock, so a createMock() used as a plain stub had its willReturn() configuration stripped at
 * the first yield -- its post-yield call silently returned null before the fix. Run by the
 * compatibility workflow, which asserts the exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class MockExpectationManualTest extends TestCase
{
    public function testSatisfiedOnlyAfterYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(3);

        Counit::create(function () use ($mock): void {
            Counit::sleep(1);

            $mock->count();
        }, 1);
    }

    public function testExactlyTwoSpanningYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::exactly(2))->method('count')->willReturn(3);

        Counit::create(function () use ($mock): void {
            $mock->count();

            Counit::sleep(1);

            $mock->count();
        }, 1);
    }

    public function testSatisfiedBeforeYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(3);

        Counit::create(function () use ($mock): void {
            $mock->count();

            Counit::sleep(1);
        }, 1);
    }

    public function testMockWithoutExpectationsKeepsConfiguredValue(): void
    {
        $stub = $this->createMock(\Countable::class);
        $stub->method('count')->willReturn(7);

        Counit::create(function () use ($stub): void {
            Counit::sleep(1);

            self::assertSame(7, $stub->count());
        }, 1);
    }
}

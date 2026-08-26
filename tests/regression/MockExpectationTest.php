<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for mock ->expects() satisfied only after a yield in the automatic approach:
 * PHPUnit verifies every registered mock from runBare(), right after runTest() returns -- which
 * under counit used to be the body's first yield, so an expectation satisfied only later failed
 * prematurely ("was never invoked"). A test with a registered mock carrying an invocation-count
 * rule is now joined at its first yield (see MockExpectations), so PHPUnit verifies the truly
 * finished body. One assertion per test: the verification's own. Run by the compatibility
 * workflow, which asserts the exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class MockExpectationTest extends TestCase
{
    public function testSatisfiedOnlyAfterYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(3);

        usleep(200000);

        $mock->count();
    }

    public function testExactlyTwoSpanningYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::exactly(2))->method('count')->willReturn(3);

        $mock->count();

        usleep(200000);

        $mock->count();
    }

    public function testSatisfiedBeforeYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(3);

        $mock->count();

        usleep(200000);
    }
}

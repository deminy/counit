<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * The manual approach's counterpart of MockExpectationTest: the mock is registered before the
 * Counit::create() call, so it is visible at the join decision when the callable first yields;
 * the joined body then satisfies the expectation before PHPUnit verifies it. The requested
 * assertion credit is not applied on the join path -- the verification's own assertion is the
 * test's one and only. Run by the compatibility workflow, which asserts the exact blocking-mode
 * summary with and without Swoole.
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
}

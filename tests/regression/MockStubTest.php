<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Guard for the other half of the join predicate: stubs are never verified by PHPUnit
 * (createStub() does not even register with the mock registry), so they must not trigger the
 * MockExpectations join -- these tests keep their concurrency and their stubbed return values
 * across yields, unchanged by the fix. Run by the compatibility workflow, which asserts the
 * exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class MockStubTest extends TestCase
{
    public function testStubKeepsWorkingAcrossYield(): void
    {
        $stub = self::createStub(\Countable::class);
        $stub->method('count')->willReturn(7);

        usleep(200000);

        self::assertSame(7, $stub->count());
    }

    public function testStubConfiguredAfterYield(): void
    {
        $stub = self::createStub(\Countable::class);

        usleep(200000);

        $stub->method('count')->willReturn(9);

        self::assertSame(9, $stub->count());
    }
}

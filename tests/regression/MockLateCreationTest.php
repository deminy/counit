<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Pin of the documented RESIDUAL, so nobody silently "fixes" the matrix row into a false claim
 * of completeness: a mock created only AFTER the test's first yield is invisible at the join
 * decision (the registry is empty at that instant), and PHPUnit's own verification loop -- plus
 * the registry clear at the bottom of runBare() -- has already run, so the mock is never
 * verified at all. Blocking mode fails this test ("was never invoked", 2 assertions); under
 * Swoole it passes with 1 assertion (the assertTrue() -- the verification's assertion never
 * happens). The compatibility workflow asserts both divergent outcomes explicitly, per mode.
 *
 * @internal
 * @coversNothing
 */
class MockLateCreationTest extends TestCase
{
    public function testMockCreatedAfterYieldIsNotVerified(): void
    {
        self::assertTrue(true);

        usleep(200000);

        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(3);
    }
}

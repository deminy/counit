<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Pin of the documented RESIDUAL, so nobody silently "fixes" it into a false claim of
 * completeness: a mock created only AFTER the callable's first yield is invisible at the join
 * decision (the registry is empty at that instant), and PHPUnit's verification on the main
 * coroutine has already run by the time the coroutine registers it -- the mock is never verified
 * at all. Blocking mode fails this test (2 assertions); under Swoole it passes with 1 (the
 * assertTrue() -- the verification's assertion never happens). The compatibility workflow
 * asserts both divergent outcomes explicitly, per mode. The automatic approach has no such
 * residual on this branch: its whole runBare(), verification included, runs inside the
 * coroutine.
 *
 * @internal
 * @coversNothing
 */
class MockLateCreationManualTest extends TestCase
{
    public function testMockCreatedAfterYieldIsNotVerified(): void
    {
        self::assertTrue(true);

        Counit::create(function (): void {
            Counit::sleep(1);

            $mock = $this->createMock(\Countable::class);
            $mock->expects(self::once())->method('count')->willReturn(3);
        }, 1);
    }
}

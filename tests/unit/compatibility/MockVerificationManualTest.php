<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * The manual approach's counterpart: PHPUnit verifies the mock on the main coroutine, as soon as
 * the test body yields, so a mock has to be satisfied before the first yield. Its verification is
 * then counted within the test's own window and must not be corrected a second time.
 *
 * @internal
 * @coversNothing
 */
class MockVerificationManualTest extends TestCase
{
    /**
     * Two assertions: the assertCount() below, and the mock verification.
     */
    public function testMockSatisfiedBeforeYieldIsCounted(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(42);

        Counit::create(function () use ($mock): void {
            self::assertCount(42, $mock);

            Counit::sleep(1);
        }, 1);
    }
}

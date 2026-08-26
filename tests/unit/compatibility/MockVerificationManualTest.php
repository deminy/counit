<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * The manual approach's counterpart: the mock registered before the Counit::create() call makes
 * the test join at the callable's first yield (see MockExpectations), so PHPUnit verifies the
 * truly finished body. The requested assertion credit is not applied on the join path -- the
 * body's assertion and the verification are both counted natively and must not be corrected
 * again.
 *
 * @internal
 * @coversNothing
 */
class MockVerificationManualTest extends TestCase
{
    /**
     * Two assertions: the assertCount() and the mock verification.
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

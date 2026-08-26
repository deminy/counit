<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Composition guard: a post-condition-customizing class already got mock verification right
 * through its own join (the README noted it as incidental), and adding the MockExpectations
 * predicate alongside must keep that true -- the join fires once, PHPUnit runs verification and
 * the post-condition phase after the truly finished body, and both assertions are counted. Run
 * by the compatibility workflow, which asserts the exact blocking-mode summary with and without
 * Swoole.
 *
 * @internal
 * @coversNothing
 */
class MockPostConditionInterplayTest extends TestCase
{
    protected function assertPostConditions(): void
    {
        self::assertTrue(true);
    }

    public function testMockSatisfiedAfterYieldWithCustomPostConditions(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::once())->method('count')->willReturn(3);

        usleep(200000);

        $mock->count();
    }
}

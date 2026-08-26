<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Same premature-verification regression as MockExpectationTest, with the mock created in
 * setUp(): registration happens before the test method even starts, so it must be just as
 * visible at the join decision as an in-body createMock(). A single test on purpose -- a
 * setUp()-created mock with an expectation applies to every test of its class, so sharing it
 * across scenario tests self-inflicts failures even under plain blocking PHPUnit. Run by the
 * compatibility workflow, which asserts the exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class MockSetUpTest extends TestCase
{
    private MockObject&\Countable $mock;

    protected function setUp(): void
    {
        $this->mock = $this->createMock(\Countable::class);
        $this->mock->expects(self::once())->method('count')->willReturn(3);
    }

    public function testSetUpMockSatisfiedOnlyAfterYield(): void
    {
        usleep(200000);

        $this->mock->count();
    }
}

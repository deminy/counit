<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Pin of the automatic approach's mock behavior on this branch, which needs no join: the whole
 * runBare() -- verifyMockObjects() included -- runs inside the test's coroutine, so verdicts are
 * always derived from the truly finished body. A never() violation after a yield therefore
 * throws at call time inside the coroutine: blocking mode fails the test natively; under Swoole
 * the throw happens after PHPUnit already reported the test, so it lands in the deferred
 * end-of-run block with exit code 1 -- this branch's documented post-yield failure model, never
 * a silent pass. The compatibility workflow asserts both mode-specific outcomes explicitly.
 *
 * @internal
 * @coversNothing
 */
class MockAutomaticViolationTest extends TestCase
{
    public function testNeverViolatedOnlyAfterYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::never())->method('count');

        usleep(200000);

        $mock->count();
    }
}

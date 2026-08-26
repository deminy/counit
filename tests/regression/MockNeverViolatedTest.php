<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * The most important guard of the mock set: expects($this->never()) violated only after a yield
 * used to PASS SILENTLY with exit code 0 -- the premature verification at the first yield found
 * nothing wrong and then STRIPPED the mock's invocation mocker, so even the call-time throw was
 * gone. With the join, the still-armed rule throws at call time inside the joined body and the
 * test fails natively with PHPUnit's own message, exactly as in blocking mode. A future
 * regression here fails quietly -- exit 0, green summary -- which is why the compatibility
 * workflow asserts the exact failing summary, message and exit code with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class MockNeverViolatedTest extends TestCase
{
    public function testNeverViolatedOnlyAfterYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::never())->method('count');

        usleep(200000);

        $mock->count();
    }
}

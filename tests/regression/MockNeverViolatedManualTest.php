<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * The most important guard of the mock set: a manual-approach expects($this->never()) violated
 * only after a yield used to PASS SILENTLY with exit code 0 -- the premature verification at the
 * callable's first yield found nothing wrong and then STRIPPED the mock's invocation mocker, so
 * even the call-time throw was gone. With the join, the still-armed rule throws at call time
 * inside the joined body and the test fails natively with PHPUnit's own message, exactly as in
 * blocking mode. A future regression here fails quietly -- exit 0, green summary -- which is why
 * the compatibility workflow asserts the exact failing summary, message and exit code with and
 * without Swoole.
 *
 * @internal
 * @coversNothing
 */
class MockNeverViolatedManualTest extends TestCase
{
    public function testNeverViolatedOnlyAfterYield(): void
    {
        $mock = $this->createMock(\Countable::class);
        $mock->expects(self::never())->method('count');

        Counit::create(function () use ($mock): void {
            Counit::sleep(1);

            $mock->count();
        }, 1);
    }
}

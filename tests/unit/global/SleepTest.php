<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * @internal
 * @coversNothing
 */
class SleepTest extends TestCase
{
    public function dataSleep(): array
    {
        return [
            [1, '1 second has elapsed.'],
            [2, '2 seconds have elapsed.'],
            [3, '3 seconds have elapsed.'],
            [5, '5 seconds have elapsed.'],
        ];
    }

    /**
     * To test and see if the PHP function sleep() works as should.
     *
     * @dataProvider dataSleep
     * @covers \sleep()
     */
    public function testSleep(int $seconds, string $message): void
    {
        $startTime = time();
        sleep($seconds);
        $endTime = time();

        self::assertEqualsWithDelta($seconds, ($endTime - $startTime), 1, $message);
    }
}

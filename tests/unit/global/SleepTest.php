<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
#[CoversFunction('sleep')]
class SleepTest extends TestCase
{
    /**
     * @return array<array{0: int, 1: string}>
     */
    public static function dataSleep(): array
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
     */
    #[DataProvider('dataSleep')]
    public function testSleep(int $seconds, string $message): void
    {
        $startTime = time();
        sleep($seconds);
        $endTime = time();

        self::assertEqualsWithDelta($seconds, ($endTime - $startTime), 1, $message);
    }
}

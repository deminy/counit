<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
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

    #[DataProvider('dataSleep')]
    public function testSleep(int $seconds, string $message): void
    {
        Counit::create(
            function () use ($seconds, $message) {
                $startTime = time();
                Counit::sleep($seconds);
                $endTime = time();

                self::assertEqualsWithDelta($seconds, ($endTime - $startTime), 1, $message);
            },
            1 // The wrapped function call has one delayed assertion in it.
        );
    }
}

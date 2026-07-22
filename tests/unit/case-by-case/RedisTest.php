<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use Deminy\Counit\Helper;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversNothing]
class RedisTest extends TestCase
{
    /**
     * @return array<array{0: int, 1: string}>
     */
    public static function dataRedis(): array
    {
        return [
            [1, 'The entry expires in 1 second.'],
            [2, 'The entry expires in 2 seconds.'],
            [3, 'The entry expires in 3 seconds.'],
            [5, 'The entry expires in 5 seconds.'],
        ];
    }

    #[DataProvider('dataRedis')]
    public function testRedis(int $seconds, string $message): void
    {
        Counit::create(
            function () use ($seconds, $message) {
                $redis = new \Redis();
                $redis->connect('redis');

                $key = Helper::getNewKey();
                $redis->setex($key, $seconds, 'dummy');
                self::assertSame('dummy', $redis->get($key), 'The new entry should have been added successfully.');
                Counit::sleep($seconds + 1);
                self::assertFalse($redis->get($key), $message);

                $redis->close();
            },
            2 // The wrapped function call has two delayed assertions in it.
        );
    }
}

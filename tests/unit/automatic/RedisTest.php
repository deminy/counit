<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Helper;
use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
#[CoversMethod(\Redis::class, 'setex')]
class RedisTest extends TestCase
{
    /**
     * To test and see if the PHP method \Redis::setex() has TTL set as expected.
     */
    #[DataProvider('dataRedis')]
    public function testRedis(int $seconds, string $message): void
    {
        $redis = new \Redis();
        $redis->connect('redis');

        $key = Helper::getNewKey();
        $redis->setex($key, $seconds, 'dummy');
        self::assertSame('dummy', $redis->get($key), 'The new entry should have been added successfully.');
        sleep($seconds + 1);
        self::assertFalse($redis->get($key), $message);

        $redis->close();
    }

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
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use Deminy\Counit\Helper;
use PHPUnit\Framework\TestCase;
use Redis;

/**
 * @internal
 * @coversNothing
 */
class RedisTest extends TestCase
{
    public function dataRedis(): array
    {
        return [
            [1, 'The entry expires in 1 second.'],
            [2, 'The entry expires in 2 seconds.'],
            [3, 'The entry expires in 3 seconds.'],
            [5, 'The entry expires in 5 seconds.'],
        ];
    }

    /**
     * @dataProvider dataRedis
     */
    public function testRedis(int $seconds, string $message): void
    {
        Counit::create(
            function () use ($seconds, $message) {
                $redis = new Redis();
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

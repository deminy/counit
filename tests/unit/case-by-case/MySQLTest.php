<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use mysqli;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class MySQLTest extends TestCase
{
    public function dataRedis(): array
    {
        return [
            [1, 'MySQL sends a response back in 1 second.'],
            [2, 'MySQL sends a response back in 2 seconds.'],
            [3, 'MySQL sends a response back in 3 seconds.'],
            [5, 'MySQL sends a response back in 5 seconds.'],
        ];
    }

    /**
     * @dataProvider dataRedis
     */
    public function testRedis(int $seconds, string $message): void
    {
        Counit::create(
            function () use ($seconds, $message) {
                $mysqli = new mysqli('mysql', 'username', 'password', 'test');
                $stmt = $mysqli->prepare("SELECT SLEEP({$seconds})");

                $startTime = time();
                $stmt->execute();
                $endTime = time();
                self::assertEqualsWithDelta($seconds, ($endTime - $startTime), 1, $message);

                $stmt->close();
                $mysqli->close();
            },
            1 // The wrapped function call has one delayed assertion in it.
        );
    }
}

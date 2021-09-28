<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use mysqli;

/**
 * @internal
 * @coversNothing
 */
class MySQLTest extends TestCase
{
    public function dataMySQL(): array
    {
        return [
            [1, 'MySQL sends a response back in 1 second.'],
            [2, 'MySQL sends a response back in 2 seconds.'],
            [3, 'MySQL sends a response back in 3 seconds.'],
            [5, 'MySQL sends a response back in 5 seconds.'],
        ];
    }

    /**
     * To test and see if the MySQL function sleep() works as expected.
     *
     * @dataProvider dataMySQL
     */
    public function testMySQL(int $seconds, string $message): void
    {
        $mysqli = new mysqli('mysql', 'username', 'password', 'test');
        $stmt   = $mysqli->prepare("SELECT SLEEP({$seconds})");

        $startTime = time();
        $stmt->execute();
        $endTime = time();
        self::assertEqualsWithDelta($seconds, ($endTime - $startTime), 1, $message);

        $stmt->close();
        $mysqli->close();
    }
}

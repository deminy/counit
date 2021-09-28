<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CurlTest extends TestCase
{
    public function dataCurl(): array
    {
        return [
            [1, 'The web server sends a response back in 1 second.'],
            [2, 'The web server sends a response back in 2 seconds.'],
            [3, 'The web server sends a response back in 3 seconds.'],
            [5, 'The web server sends a response back in 5 seconds.'],
        ];
    }

    /**
     * To test and see if the curl extension works as expected.
     *
     * @dataProvider dataCurl
     */
    public function testCurl(int $seconds, string $message): void
    {
        Counit::create(
            function () use ($seconds, $message) {
                $ch = curl_init("http://web:9501?seconds={$seconds}");
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

                $startTime = time();
                $body      = curl_exec($ch);
                $endTime   = time();

                curl_close($ch);

                self::assertEqualsWithDelta($seconds, ($endTime - $startTime), 1, $message);
                self::assertSame('OK', $body, "{$message} The response is OK.");
            },
            2 // The wrapped function call has two delayed assertions in it.
        );
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * expectException() with the throw happening after the first yield, in the manual approach:
 * the expectation is declared before Counit::create() is called, so create() sees it registered
 * once the callable reaches its first yield and joins the coroutine -- the Throwable is
 * rethrown synchronously into PHPUnit's native verification, with no test changes needed.
 * (Before the join, the manual approach failed prematurely with "exception not thrown" plus a
 * deferred duplicate.)
 *
 * @internal
 * @coversNothing
 */
class DelayedExceptionManualCompatibilityTest extends TestCase
{
    public function testExpectedExceptionClassAfterYield(): void
    {
        $this->expectException(\RuntimeException::class);
        Counit::create(function (): void {
            Counit::sleep(1);

            throw new \RuntimeException('delayed manual boom');
        });
    }

    public function testExpectedExceptionMessageAfterYield(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('delayed manual boom');
        Counit::create(function (): void {
            Counit::sleep(1);

            throw new \RuntimeException('delayed manual boom');
        });
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * expectException() and friends where the expected exception is thrown only AFTER the test's
 * first yield, in the automatic approach. PHPUnit verifies an exception expectation the moment
 * the test-method invocation returns; on this branch the automatic approach already verified
 * such throws natively (the whole runBare() runs inside the coroutine), and with the
 * expectation-join in Counit::create() the verification now also happens synchronously -- these
 * behave exactly as under blocking PHPUnit, for every expectation flavor supported across
 * PHPUnit 8.0 through 9.6.
 *
 * @internal
 * @coversNothing
 */
class DelayedExceptionCompatibilityTest extends TestCase
{
    public function testExpectedExceptionClassAfterYield(): void
    {
        $this->expectException(\RuntimeException::class);
        sleep(1);

        throw new \RuntimeException('delayed boom');
    }

    public function testExpectedExceptionMessageAfterYield(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('delayed boom');
        sleep(1);

        throw new \RuntimeException('delayed boom');
    }

    public function testExpectedExceptionCodeAfterYield(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(7);
        sleep(1);

        throw new \RuntimeException('delayed boom', 7);
    }

    public function testExpectedExceptionObjectAfterYield(): void
    {
        $this->expectExceptionObject(new \RuntimeException('delayed boom', 7));
        sleep(1);

        throw new \RuntimeException('delayed boom', 7);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * expectException() and friends where the expected exception is thrown only AFTER the test's
 * first yield, in the automatic approach. Such a test used to fail prematurely with "exception
 * not thrown" (PHPUnit verifies the expectation as soon as invokeTestMethod() returns -- the
 * body's first yield under counit) while the real Throwable could only be deferred. counit now
 * detects the registered expectation at the first yield and joins the coroutine (see
 * Counit::create() and ExceptionExpectations), so PHPUnit's native verification receives the
 * real Throwable -- these behave exactly as under blocking PHPUnit, for every expectation
 * flavor.
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

    public function testExpectedExceptionMessageMatchesAfterYield(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/^delayed/');
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

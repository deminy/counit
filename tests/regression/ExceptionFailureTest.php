<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the failing shapes of a post-yield exception expectation in the
 * automatic approach, all reported exactly as under blocking PHPUnit now that an
 * expectation-carrying test's coroutine is joined at its first yield: a mismatched Throwable
 * fails with the real comparison message, an expectation whose exception never arrives fails
 * natively once the body has truly finished, and a tearDown() throwing the very class the test
 * expects ERRORS the test -- tearDown() runs inside runBare(), strictly after runTest()
 * verified the expectation, so a hook Throwable can never satisfy the body's expectation. Run
 * by the compatibility workflow, which asserts the exact summary, exit code, and the absence of
 * the deferred block, identically with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class ExceptionFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->getName(false) === 'testExpectedClassAlsoThrownFromTearDown') {
            throw new \RuntimeException('deliberate tearDown() throw of the very class the test expects');
        }
    }

    public function testMismatchedExceptionAfterYield(): void
    {
        $this->expectException(\RuntimeException::class);
        sleep(1);

        throw new \LogicException('deliberate mismatch');
    }

    public function testExpectedExceptionNeverThrown(): void
    {
        $this->expectException(\RuntimeException::class);
        sleep(1);
        // Deliberately returns without throwing: the failure must be PHPUnit's own native
        // "exception not thrown", rendered only after the body has truly finished.
    }

    public function testExpectedClassAlsoThrownFromTearDown(): void
    {
        $this->expectException(\RuntimeException::class);
        sleep(1);

        throw new \RuntimeException('the body throw that satisfies the expectation');
    }

    public function testIndependentStillRuns(): void
    {
        self::assertTrue(true);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use Deminy\Counit\Helper;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * To test and check compatibility with PHPUnit's process isolation, in the "case-by-case style".
 * The class-level #[RunTestsInSeparateProcesses] attribute is covered here; it isolates every test
 * method of the class, the same way #[RunInSeparateProcess] isolates a single one.
 *
 * In the child process Counit::create() is not coroutine-friendly, so it invokes the callable
 * directly and ignores its assertion-credit argument -- exactly what it does when the Swoole
 * extension is missing. The counts therefore match plain PHPUnit without any correction.
 *
 * @internal
 * @coversNothing
 */
#[RunTestsInSeparateProcesses]
class ProcessIsolationCaseByCaseTest extends TestCase
{
    /**
     * The child process runs outside any coroutine, so counit falls back to blocking mode there.
     */
    public function testIsolatedTestRunsInBlockingMode(): void
    {
        Counit::create(
            function (): void {
                self::assertFalse(Helper::isCoroutineFriendly(), 'A test running in a separate process is not executed inside a coroutine.');
            }
        );
    }

    /**
     * To trigger an immediate assertion and a delayed assertion within the same isolated test case.
     * The assertion credit passed to Counit::create() is ignored in the child process, so both
     * assertions -- and only those two -- are reported.
     */
    public function testIsolatedImmediateAndDelayedAssertions(): void
    {
        Counit::create(
            function (): void {
                self::assertTrue(true, 'An immediate assertion is triggered when start running the test case.');
                Counit::sleep(1);
                self::assertTrue(true, 'A delayed assertion is triggered.');
            },
            1 // The wrapped function call has one delayed assertion in it.
        );
    }

    /**
     * To expect an exception thrown out from an isolated test case.
     */
    public function testIsolatedExpectedException(): void
    {
        self::expectException(\Exception::class);
        throw new \Exception();
    }
}

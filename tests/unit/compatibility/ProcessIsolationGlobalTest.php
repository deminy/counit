<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Helper;
use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * To test and check compatibility with PHPUnit's process isolation, in the "global style".
 *
 * A test marked #[RunInSeparateProcess] never reaches counit's TestCase::invokeTestMethod(): PHPUnit
 * hands it to SeparateProcessTestRunner instead, which runs it in a child process and imports the
 * child's result (including its assertion count) back into the parent run. The child is plain,
 * non-coroutine PHP, so the test body runs in blocking mode there -- an isolated test simply does
 * not benefit from counit, but it must still behave, and be counted, exactly as under PHPUnit.
 *
 * @internal
 * @coversNothing
 */
class ProcessIsolationGlobalTest extends TestCase
{
    /**
     * The child process runs outside any coroutine, so counit falls back to blocking mode there.
     */
    #[RunInSeparateProcess]
    public function testIsolatedTestRunsInBlockingMode(): void
    {
        self::assertFalse(Helper::isCoroutineFriendly(), 'A test running in a separate process is not executed inside a coroutine.');
    }

    /**
     * To trigger an immediate assertion and a delayed assertion within the same isolated test case.
     * Both are performed in the child process, so both must be reported -- neither the up-front
     * assertion credit nor the end-of-run correction applies to an isolated test.
     */
    #[RunInSeparateProcess]
    public function testIsolatedImmediateAndDelayedAssertions(): void
    {
        self::assertTrue(true, 'An immediate assertion is triggered when start running the test case.');
        sleep(1);
        self::assertTrue(true, 'A delayed assertion is triggered.');
    }

    /**
     * To expect an exception thrown out from an isolated test case.
     */
    #[RunInSeparateProcess]
    public function testIsolatedExpectedException(): void
    {
        self::expectException(\Exception::class);
        throw new \Exception();
    }

    /**
     * The three test cases below run in the declared order and belong together: they cover the one
     * way process isolation can interfere with counit's assertion counting.
     *
     * This first one leaves a coroutine pending (it yields on sleep(), so PHPUnit closes this test's
     * assertion-counting window and moves on while the two assertions below have yet to run).
     */
    public function testCoroutineTestBeforeAnIsolatedTest(): void
    {
        sleep(1);
        self::assertTrue(true, 'A delayed assertion is triggered.');
        self::assertTrue(true, 'Another delayed assertion is triggered.');
    }

    /**
     * This one deliberately keeps its child process alive longer than the sleep above, so the
     * coroutine left pending by the previous test becomes ready to resume while PHPUnit is waiting
     * on the child. Spawning that child and reading its result must not yield the main coroutine:
     * no assertion-counting window is open then, so anything the resumed coroutine asserts would be
     * wiped by the next test's counter reset and vanish from the run's reported total. That is why
     * Helper::coroutineHookFlags() excludes SWOOLE_HOOK_PROC.
     */
    #[RunInSeparateProcess]
    public function testIsolatedTestOutlastingThatCoroutine(): void
    {
        sleep(2);
        self::assertTrue(true, 'An assertion is triggered in the child process.');
    }

    /**
     * And this one closes the window: its counter reset is what would swallow the two assertions
     * above if the isolated test had yielded. The suite's reported assertion total is therefore the
     * check here -- it must be identical with and without Swoole.
     */
    public function testCoroutineTestAfterAnIsolatedTest(): void
    {
        self::assertTrue(true, 'An immediate assertion is triggered.');
    }
}

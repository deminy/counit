<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the after-test hooks of a test whose setUp() throws or skips: the test
 * body never reaches invokeTestMethod(), so the relocated (in-coroutine) replay never runs --
 * counit must hand the hooks back to PHPUnit's native invocation instead, which runs them
 * synchronously with blocking semantics: tearDown() still runs, a Throwable it raises after a
 * failed setUp() is swallowed (the setUp() error stands), and the class's next yielding test
 * gets the relocated replay again. Run by the compatibility workflow, which asserts the exact
 * summary, exit code, and the absence of the deferred block, identically with and without
 * Swoole.
 *
 * @internal
 * @coversNothing
 */
class SetUpFailureHooksTest extends TestCase
{
    private static bool $tearDownRanForErroredSetUp = false;

    private static bool $tearDownRanForSkippedSetUp = false;

    protected function setUp(): void
    {
        if ($this->name() === 'testSetUpErrors') {
            throw new \RuntimeException('deliberate setUp() failure');
        }
        if ($this->name() === 'testSetUpSkips') {
            $this->markTestSkipped('deliberate setUp() skip');
        }
    }

    protected function tearDown(): void
    {
        if ($this->name() === 'testSetUpErrors') {
            self::$tearDownRanForErroredSetUp = true;

            // Blocking PHPUnit swallows this: an exception raised in tearDown() is only passed on
            // when no exception was raised before it, and setUp() already errored the test.
            throw new \RuntimeException('a tearDown() failure after a failed setUp() must be swallowed');
        }
        if ($this->name() === 'testSetUpSkips') {
            self::$tearDownRanForSkippedSetUp = true;
        }
    }

    /**
     * Must come first: the takeover is lazy (it happens at the class's first invokeTestMethod()
     * call), so without a test that actually runs before them, the aborted tests below would
     * still see PHPUnit's untouched native hooks and hide the regression this file guards.
     */
    public function testTakeoverHappensFirst(): void
    {
        self::assertTrue(true);
    }

    public function testSetUpErrors(): void
    {
        self::fail('the body of a test whose setUp() failed must not run');
    }

    public function testSetUpSkips(): void
    {
        self::fail('the body of a test whose setUp() skipped must not run');
    }

    /**
     * A normal yielding test right after the aborted ones: the takeover must be back in place for
     * it, i.e. its (here: no-op) tearDown() replays inside the coroutine, not at the first yield.
     */
    public function testYieldingTestStillRuns(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    public function testTearDownRanForBothAbortedTests(): void
    {
        self::assertTrue(self::$tearDownRanForErroredSetUp, 'tearDown() must run for a test whose setUp() failed');
        self::assertTrue(self::$tearDownRanForSkippedSetUp, 'tearDown() must run for a test whose setUp() skipped');
    }
}

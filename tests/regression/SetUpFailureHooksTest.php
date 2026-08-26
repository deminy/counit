<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the after-test hooks of a test whose setUp() throws or skips. On this
 * branch the whole runBare() -- setUp(), body, tearDown() -- runs inside the test's coroutine,
 * so PHPUnit's own hook handling applies untouched: tearDown() still runs (a guard test asserts
 * the side effect), a Throwable it raises after the failed setUp() is swallowed (the setUp()
 * error stands), and the output is identical with and without Swoole. This holds by
 * architecture rather than by any counit code, which is exactly why it deserves a guard: the
 * 1.x branch needed a dedicated fix for the same behavior, and this test pins that no future
 * change on this branch loses it. Run by the compatibility workflow, which asserts the exact
 * summary, exit code, and the absence of the deferred block, identically in both modes.
 *
 * @internal
 * @coversNothing
 */
class SetUpFailureHooksTest extends TestCase
{
    /**
     * @var bool
     */
    private static $tearDownRanForErroredSetUp = false;

    /**
     * @var bool
     */
    private static $tearDownRanForSkippedSetUp = false;

    protected function setUp(): void
    {
        if ($this->getName(false) === 'testSetUpErrors') {
            throw new \RuntimeException('deliberate setUp() failure');
        }
        if ($this->getName(false) === 'testSetUpSkips') {
            $this->markTestSkipped('deliberate setUp() skip');
        }
    }

    protected function tearDown(): void
    {
        if ($this->getName(false) === 'testSetUpErrors') {
            self::$tearDownRanForErroredSetUp = true;

            // PHPUnit swallows this: an exception raised in tearDown() is only passed on when no
            // exception was raised before it, and setUp() already errored the test.
            throw new \RuntimeException('a tearDown() failure after a failed setUp() must be swallowed');
        }
        if ($this->getName(false) === 'testSetUpSkips') {
            self::$tearDownRanForSkippedSetUp = true;
        }
    }

    public function testSetUpErrors(): void
    {
        self::fail('the body of a test whose setUp() failed must not run');
    }

    public function testSetUpSkips(): void
    {
        self::fail('the body of a test whose setUp() skipped must not run');
    }

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

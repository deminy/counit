<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase;
use SebastianBergmann\Invoker\Invoker;

/**
 * Whether PHPUnit will enforce per-test time limits in this run (--enforce-time-limit / the
 * enforceTimeLimit XML attribute) -- the input to counit's join decision.
 *
 * PHPUnit 8/9 time a limited test by wrapping the whole runBare() call in a
 * pcntl_alarm()/SIGALRM guard (TestResult::run() hands [$test, 'runBare'] to
 * SebastianBergmann\Invoker\Invoker), and the alarm is disarmed the moment runBare() returns.
 * Under counit that used to be the body's first yield: the measured window covered milliseconds,
 * an over-limit test simply passed -- and worse, on the join paths that DO keep runBare() alive
 * (a @depends producer, an expectException() test), the still-armed alarm's signal was dispatched
 * into whichever coroutine resumed first, aborting an unrelated test. So while enforcement is
 * active, every test's coroutine is joined at its first yield (see TestCase::runBare() and
 * Counit::create()): runBare() then spans the real test duration, PHPUnit's own Invoker does all
 * the timing and reporting -- a native RiskyTestError, --fail-on-risky honored -- and, with no
 * concurrent test coroutines left, the alarm can only ever fire within the timed test's own
 * runBare() window. The run is serialized for the duration: with the option, counit gives
 * PHPUnit's timings and PHPUnit's speed.
 *
 * The decision is deliberately per RUN, not per test (unlike TestResult's own
 * shouldTimeLimitBeEnforced(), which additionally requires a positive default time limit or a
 * @small/@medium/@large size on the test): if only the limited tests were joined, tests without
 * a limit could still be in flight while a limited test's alarm is armed -- re-opening the
 * stray-signal window this join exists to close. The cost is over-joining in one degenerate
 * configuration (an explicit defaultTimeLimit of 0 with mostly unsized tests), where the run
 * serializes without PHPUnit enforcing anything on the unsized tests.
 *
 * PHPUnit 8/9 have no extension seam that hands over the run's configuration, so the state is
 * read lazily from the first test object seen -- TestResult::enforcesTimeLimit() is public on
 * both supported PHPUnit lines -- and cached for the rest of the run.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class TimeLimit
{
    /**
     * Whether PHPUnit enforces time limits in this run; null until first resolved from a test's
     * TestResult.
     *
     * @var bool|null
     */
    private static $enforced;

    /**
     * @var bool
     */
    private static $noticeIssued = false;

    /**
     * Whether tests must be joined at their first yield because PHPUnit enforces time limits in
     * this run. Pass the test at hand when one is available; a call without one (e.g. a nested
     * Counit::create() from a helper) uses the cached answer, and reports false until any test
     * resolved it -- degrading to counit's pre-existing (non-joining) behavior, never breaking a
     * run.
     *
     * Mirrors the run-level conditions of TestResult::shouldTimeLimitBeEnforced(): the flag
     * itself, the pcntl extension and the Invoker class (without either, PHPUnit silently skips
     * enforcement, so joining would serialize the run for nothing), and no active Xdebug
     * debugging session.
     */
    public static function enforcedForRun(?TestCase $test = null): bool
    {
        if (self::$enforced === null) {
            if (!$test instanceof TestCase) {
                return false;
            }

            $testResult = $test->getTestResultObject();
            if ($testResult === null) {
                return false;
            }

            self::$enforced = $testResult->enforcesTimeLimit()
                && extension_loaded('pcntl')
                && function_exists('pcntl_signal')
                && function_exists('pcntl_async_signals')
                && function_exists('pcntl_alarm')
                && class_exists(Invoker::class)
                && !(extension_loaded('xdebug') && function_exists('xdebug_is_debugger_active') && xdebug_is_debugger_active());

            if (self::$enforced) {
                self::announceSerializedRun();
            }
        }

        return self::$enforced;
    }

    /**
     * Announces -- once, to STDERR (excluded from the coroutine hooks, so it cannot yield) -- that
     * the run is serialized because time limits are enforced. Silenced by
     * COUNIT_SILENCE_TEARDOWN_NOTICE=1, like the other counit notices.
     */
    private static function announceSerializedRun(): void
    {
        if (self::$noticeIssued || getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') !== false) {
            self::$noticeIssued = true;

            return;
        }
        self::$noticeIssued = true;

        fwrite(STDERR, 'counit notice: --enforce-time-limit is active, so every test is joined at its first yield to let PHPUnit time and enforce the limit natively -- the run is serialized (PHPUnit\'s timings, PHPUnit\'s speed, no concurrency). Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.' . PHP_EOL);
    }
}

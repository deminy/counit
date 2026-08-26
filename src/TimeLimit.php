<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Whether PHPUnit will enforce per-test time limits in this run (--enforce-time-limit / the
 * enforceTimeLimit XML attribute) -- the input to counit's join decision.
 *
 * PHPUnit times a limited test by wrapping the whole runBare() call in a pcntl_alarm()/SIGALRM
 * guard (SebastianBergmann\Invoker\Invoker, called from TestRunner::runTestWithTimeout()), and
 * disarms the alarm the moment runBare() returns. Under counit that used to be the body's first
 * yield: the measured window covered milliseconds, an over-limit test simply passed -- and worse,
 * on the join paths that DO keep runBare() alive, the still-armed alarm's signal was dispatched
 * into whichever coroutine resumed first, aborting an unrelated test. So while enforcement is
 * active, every test's coroutine is joined at its first yield (see TestCase::invokeTestMethod()
 * and Counit::create()): runBare() then spans the real test duration, PHPUnit's own Invoker does
 * all the timing and reporting -- native risky verdict, --fail-on-risky honored -- and, with no
 * concurrent test coroutines left, the alarm can only ever fire within the timed test's own
 * runBare() window. The run is serialized for the duration: with the option, counit gives
 * PHPUnit's timings and PHPUnit's speed.
 *
 * The decision is deliberately per RUN, not per test (unlike TestRunner::shouldTimeLimitBeEnforced(),
 * which additionally requires a positive --default-time-limit or a #[Small]/#[Medium]/#[Large]
 * size on the test): if only the limited tests were joined, tests without a limit could still be
 * in flight while a limited test's alarm is armed -- re-opening the stray-signal window this join
 * exists to close. The cost is over-joining in one degenerate configuration (an explicit
 * defaultTimeLimit of 0 with mostly unsized tests), where the run serializes without PHPUnit
 * enforcing anything on the unsized tests.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class TimeLimit
{
    private static bool $requested = false;

    private static bool $enforceable = false;

    private static bool $noticeIssued = false;

    /**
     * Remembers the run's time-limit configuration; called from CounitExtension::bootstrap() with
     * the same Configuration instance PHPUnit's TestRunner reads. Before this runs, enforcedForRun()
     * reports false -- degrading to counit's pre-existing (non-joining) behavior, never breaking a
     * run whose extension did not bootstrap.
     */
    public static function initialize(Configuration $configuration): void
    {
        self::$requested = $configuration->enforceTimeLimit();

        // Mirrors Invoker::canInvokeWithTimeout() without depending on the package directly:
        // without pcntl (e.g. on Windows), PHPUnit itself silently skips enforcement, so joining
        // would serialize the run for nothing.
        self::$enforceable = extension_loaded('pcntl')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_async_signals')
            && function_exists('pcntl_alarm');
    }

    /**
     * Whether tests must be joined at their first yield because PHPUnit enforces time limits in
     * this run. The Xdebug condition mirrors TestRunner::shouldTimeLimitBeEnforced(), which skips
     * enforcement while a debugging session is active.
     */
    public static function enforcedForRun(): bool
    {
        return self::$requested
            && self::$enforceable
            && !(extension_loaded('xdebug') && function_exists('xdebug_is_debugger_active') && xdebug_is_debugger_active());
    }

    /**
     * Announces -- once, to STDERR (excluded from the coroutine hooks, so it cannot yield) -- that
     * the run is serialized because time limits are enforced. Called from
     * CounitExtension::bootstrap() when running under Swoole with enforcement active; silenced by
     * COUNIT_SILENCE_TEARDOWN_NOTICE=1, like the other counit notices.
     */
    public static function announceSerializedRun(): void
    {
        if (self::$noticeIssued || getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') !== false) {
            self::$noticeIssued = true;

            return;
        }
        self::$noticeIssued = true;

        fwrite(STDERR, 'counit notice: --enforce-time-limit is active, so every test is joined at its first yield to let PHPUnit time and enforce the limit natively -- the run is serialized (PHPUnit\'s timings, PHPUnit\'s speed, no concurrency). Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.' . PHP_EOL);
    }
}

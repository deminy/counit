<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\ComparisonFailureBuilder;
use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Framework\AssertionFailedError;

/**
 * The deferred half of test FAILURES: a body assertion/exception, a relocated
 * tearDown()/#[After] hook throwable (a hook *skip* included -- a test failure under blocking
 * PHPUnit, never a skip), or a Counit::defer() cleanup throwable that happened only after the
 * test's first yield, replayed through PHPUnit's own Test\Failed/Test\Errored events at the end
 * of the run.
 *
 * By the time such a Throwable exists, PHPUnit has already reported the test as passed, so the
 * verdict cannot be made native -- but everything PHPUnit derives from a failure comes from the
 * EVENTS, and the collector still honors events emitted from the extension's ExecutionFinished
 * subscriber (the same late-emit seam LateSkips, UselessTests and HandlerIsolation use). A
 * replayed verdict therefore lands in the FAILURES!/ERRORS! summary counts, the failure/error
 * listings (with the real location), the test-run history's defect map, and the run's native
 * exit code -- exactly as in blocking mode -- instead of counit's bolt-on end-of-run block with
 * its forced exit code 1. The block remains as the fail-soft path: whatever could not be
 * emitted (changed PHPUnit internals, a nested create() with no test object in scope) is still
 * printed there and still forces a non-zero exit code.
 *
 * Classification mirrors blocking PHPUnit's own catch clauses: an AssertionError or
 * AssertionFailedError (PHPUnit's SkippedWithMessageException from a hook included -- it extends
 * AssertionFailedError) becomes Test\Failed, carrying the comparison failure for the diff
 * output; everything else becomes Test\Errored. One verdict per test, first wins (a body failure
 * beats a later cleanup failure, exactly as blocking PHPUnit reports one verdict per test); a
 * second Throwable of the same test stays in the script's block.
 *
 * Three compensations, all shared with LateSkips: the collector's `prepared` flag is set for the
 * emit's duration (Collector::testErrored() bumps numberOfTestsRun for unprepared tests --
 * testFailed() does not, but the flag covers both), the JUnit logger is shielded (its
 * handleFault() path dies on the unwound testsuite stack exactly like the skip path -- see
 * JunitXmlCorrector::shieldLogger()), and the test's event value object is stashed at deferral
 * time (ID-string matching silently misses data-provider tests). What a late event cannot
 * change is documented in the README: the recorded per-test status ("passed" -- the JUnit XML
 * verdict is corrected separately by JunitXmlCorrector), `--stop-on-failure`/`--stop-on-error`
 * reactivity, and the F/E progress markers (appended after the progress line).
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class LateFailures
{
    /**
     * Keyed by the Counit::$deferredFailures key, so a successful emit can drop exactly that
     * entry from the `counit` script's end-of-run block; insertion order is emission order.
     *
     * @var array<string, array{test: Test, throwable: \Throwable}>
     */
    private static array $pending = [];

    /**
     * Test IDs a verdict was already deferred for -- first verdict wins.
     *
     * @var array<string, true>
     */
    private static array $seenTests = [];

    /**
     * Every verdict recorded here (emitted natively or not), for JunitXmlCorrector to write the
     * matching <failure>/<error> element into the report -- the corrector path works even when
     * the event could not be emitted.
     *
     * @var list<array{test: Test, throwable: \Throwable}>
     */
    private static array $forReport = [];

    /**
     * A failure/error that happened after its test was reported. $key is the entry's key in
     * Counit::$deferredFailures. No-op without a test value object (the entry then stays in the
     * script's block) or when the test already has a deferred verdict.
     */
    public static function markDeferred(?Test $test, string $key, \Throwable $throwable): void
    {
        if ($test === null || isset(self::$seenTests[$test->id()])) {
            return;
        }

        self::$seenTests[$test->id()]              = true;
        self::$pending[$key]                       = ['test' => $test, 'throwable' => $throwable];
        self::$forReport[]                         = ['test' => $test, 'throwable' => $throwable];
    }

    /**
     * The recorded verdicts, for JunitXmlCorrector.
     *
     * @return list<array{test: Test, throwable: \Throwable}>
     */
    public static function forReport(): array
    {
        return self::$forReport;
    }

    /**
     * Emits the verdicts PHPUnit could not reach on its own. Called from the ExecutionFinished
     * subscriber, after every coroutine has drained -- after LateSkips::emitDeferred() (a
     * skipped test's verdict precedes any failure bookkeeping) and before
     * UselessTests::emitDeferred() (the extension's own Errored subscriber marks the replayed
     * tests aborted, exempting them from the no-assertions pass, mirroring blocking PHPUnit).
     */
    public static function emitDeferred(): void
    {
        if (self::$pending === []) {
            return;
        }

        $previous = LateSkips::pretendPrepared(true);
        if ($previous === null) {
            // The count compensation is unavailable (changed PHPUnit internals): an Errored
            // emit would inflate the Tests: count, so leave the verdicts to the script's block.
            return;
        }

        $restoreJunitLogger = JunitXmlCorrector::shieldLogger();

        try {
            foreach (self::$pending as $key => $deferred) {
                $throwable = $deferred['throwable'];

                try {
                    if ($throwable instanceof \AssertionError || $throwable instanceof AssertionFailedError) {
                        EventFacade::emitter()->testFailed($deferred['test'], ThrowableBuilder::from($throwable), ComparisonFailureBuilder::from($throwable));
                    } else {
                        EventFacade::emitter()->testErrored($deferred['test'], ThrowableBuilder::from($throwable));
                    }
                } catch (\Throwable) {
                    // A logger rejected the replay: leave the entry to the script's block --
                    // degraded, never a crashed run (nor a silently dropped failure).
                    continue;
                }

                // Reported natively now: the script's block must not repeat it (nor force exit
                // code 1 over the native exit code).
                unset(Counit::$deferredFailures[$key]);
            }

            self::$pending = [];
        } finally {
            if ($restoreJunitLogger !== null) {
                $restoreJunitLogger();
            }

            LateSkips::pretendPrepared($previous);
        }
    }
}

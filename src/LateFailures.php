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
 * tearDown()/#[After] hook throwable (a hook *skip* or *incomplete* included under PHPUnit's
 * older after-test hook rules, which fail the test for it -- as of 12.5.43 / 13.4.6 those go
 * through LateSkips instead; see TestCase::AFTER_HOOK_RULES_*), or a Counit::defer() cleanup
 * throwable that happened only after the test's first yield, replayed through PHPUnit's own
 * Test\Failed/Test\Errored events at the end of the run.
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
 * AssertionFailedError (PHPUnit's SkippedWithMessageException from a hook included, under the
 * older hook rules -- it extends AssertionFailedError), or an exception of a type the test
 * registered as a failure (registerFailureType()), becomes Test\Failed, carrying the comparison
 * failure for the diff output; everything else becomes Test\Errored. One verdict per test, first
 * wins, as PHPUnit reports as of 12.5.43 / 13.4.6; a second Throwable of the same test stays in
 * the script's block. (Before 12.5.43 / 13.4.6 PHPUnit counts a test twice when both its body and
 * an after-test hook fail -- a documented residual.)
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
     * @var array<string, array{test: Test, throwable: \Throwable, failure: bool}>
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
     * @var list<array{test: Test, throwable: \Throwable, failure: bool}>
     */
    private static array $forReport = [];

    /**
     * A failure/error that happened after its test was reported. $key is the entry's key in
     * Counit::$deferredFailures. No-op without a test value object (the entry then stays in the
     * script's block) or when the test already has a deferred verdict.
     */
    public static function markDeferred(?Test $test, string $key, \Throwable $throwable, bool $registeredFailure = false): void
    {
        if ($test === null || isset(self::$seenTests[$test->id()])) {
            return;
        }

        $failure = $registeredFailure || self::isAssertionLevel($throwable);

        self::$seenTests[$test->id()] = true;
        self::$pending[$key]          = ['test' => $test, 'throwable' => $throwable, 'failure' => $failure];
        self::$forReport[]            = ['test' => $test, 'throwable' => $throwable, 'failure' => $failure];
    }

    /**
     * The recorded verdicts, for JunitXmlCorrector.
     *
     * @return list<array{test: Test, throwable: \Throwable, failure: bool}>
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
                    if ($deferred['failure']) {
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

    /**
     * PHPUnit's own classification: an assertion-level Throwable fails the test, anything else
     * errors it -- unless the test registered its type as a failure (see markDeferred()).
     */
    private static function isAssertionLevel(\Throwable $throwable): bool
    {
        return $throwable instanceof \AssertionError || $throwable instanceof AssertionFailedError;
    }
}

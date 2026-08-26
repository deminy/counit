<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Framework\IncompleteTest;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;

/**
 * The deferred half of markTestSkipped()/markTestIncomplete(): skip/incomplete verdicts reached
 * only after the test's first yield, replayed through PHPUnit's own events at the end of the run.
 *
 * A skip is THROWN mid-body, so -- unlike an exception, output or mock expectation -- there is
 * nothing registered at the body's first yield for a join predicate to detect: by the time the
 * Throwable exists, PHPUnit has already reported the test as passed, and the verdict cannot be
 * made native. It can still be reported natively-in-effect, though: everything PHPUnit derives
 * from a skip/incomplete -- the Skipped:/Incomplete: summary counts, the --display-skipped/
 * --display-incomplete listings, and the --fail-on-skipped/--fail-on-incomplete exit codes --
 * comes from the Test\Skipped/Test\MarkedIncomplete EVENTS, not from the test's recorded status,
 * and the result collector still honors an event emitted from the extension's ExecutionFinished
 * subscriber (it is only read after that subscriber returns -- the same late-emit seam
 * UselessTests and HandlerIsolation use). What a late event cannot change is documented in the
 * README: the recorded per-test status (JUnit XML and the result cache still say "passed"), the
 * S/I progress markers (appended after the progress line -- the printer rides these very
 * events), and --stop-on-skipped/--stop-on-incomplete reactivity.
 *
 * One compensation is needed: Collector::testSkipped() increments numberOfTestsRun when it fires
 * outside a prepared test, which at this point would inflate the reported Tests: count by one
 * per late skip. The collector's own `prepared` flag -- the exact guard it checks -- is set for
 * the duration of the emit. If PHPUnit's internals ever change under that reflection, nothing is
 * emitted and the `counit` script's end-of-run notice reports the skips as before -- degraded,
 * never wrong.
 *
 * The test's event value object is stashed at deferral time rather than re-derived from its ID:
 * matching ID strings silently misses data-provider tests ("with data set #1" formats differ
 * between surfaces), while the object handed to the emitter here is the same one every native
 * event for the test carried.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class LateSkips
{
    /**
     * Keyed by test ID; the first verdict wins (a body skip beats a later cleanup skip, exactly
     * as blocking PHPUnit's precedence works).
     *
     * @var array<string, array{test: Test, key: string, throwable: \Throwable}>
     */
    private static array $pending = [];

    /**
     * A markTestSkipped()/markTestIncomplete() call that happened after the test was reported.
     * $key is the description Counit::$deferredSkips filed it under, so a successful emit can
     * drop the entry from the `counit` script's end-of-run notice.
     */
    public static function markDeferred(?Test $test, string $key, \Throwable $throwable): void
    {
        if ($test !== null && !isset(self::$pending[$test->id()])) {
            self::$pending[$test->id()] = ['test' => $test, 'key' => $key, 'throwable' => $throwable];
        }
    }

    /**
     * Emits the verdicts PHPUnit could not reach on its own. Called from the ExecutionFinished
     * subscriber, after every coroutine has drained.
     */
    public static function emitDeferred(): void
    {
        if (self::$pending === []) {
            return;
        }

        $previous = self::pretendPrepared(true);
        if ($previous === null) {
            // The count compensation is unavailable (changed PHPUnit internals): emitting would
            // inflate the Tests: count, so leave the verdicts to the script's notice instead.
            return;
        }

        foreach (self::$pending as $deferred) {
            $throwable = $deferred['throwable'];

            if ($throwable instanceof IncompleteTest) {
                // The real Throwable makes the incomplete listing's file:line point at the
                // test, as in blocking mode.
                EventFacade::emitter()->testMarkedAsIncomplete($deferred['test'], ThrowableBuilder::from($throwable));
            } else {
                $message = $throwable->getMessage();

                EventFacade::emitter()->testSkipped($deferred['test'], $message === '' ? 'Skipped after the test had already been reported' : $message);
            }

            // Reported natively now, so the script's notice must not repeat (or contradict) it.
            unset(Counit::$deferredSkips[$deferred['key']]);
        }

        self::$pending = [];

        self::pretendPrepared($previous);
    }

    /**
     * Sets the result collector's `prepared` flag and returns its previous value, or null when
     * PHPUnit's internals have changed (in which case nothing is emitted rather than the Tests:
     * count breaking).
     */
    private static function pretendPrepared(bool $prepared): ?bool
    {
        try {
            $collector = (new \ReflectionProperty(TestResultFacade::class, 'collector'))->getValue();

            if (!is_object($collector)) {
                return null;
            }

            $property = new \ReflectionProperty($collector, 'prepared');
            $previous = $property->getValue($collector);
            $property->setValue($collector, $prepared);

            return is_bool($previous) ? $previous : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

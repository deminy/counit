<?php

declare(strict_types=1);

namespace Deminy\Counit;

/**
 * Re-persists PHPUnit's test-run history (PHPUnit 13; the result cache on PHPUnit 12.5) after
 * the drain and the late emits, and replaces its recorded per-test times with the real
 * wall-clock durations.
 *
 * PHPUnit persists the history file when the root test suite finishes -- which is BEFORE the
 * runner's ExecutionFinished event, hence before counit's drain and before every late-emitted
 * verdict. Under Swoole that file was corrupted three ways: a test failing only after its first
 * yield emitted Passed, whose handler REMOVES the test's defect entry -- actively erasing a
 * defect a previous (e.g. blocking) run had recorded, exactly the information
 * `--order-by=defects` exists to use; the late Failed/Errored/Skipped/ConsideredRisky verdicts
 * never reached the file at all; and every recorded time was time-to-first-yield (0.001s for a
 * 1s test), feeding `--order-by=duration` and duration-based CI sharding noise.
 *
 * The fix leans on PHPUnit doing most of the work itself: the history handler subscribes to the
 * same dispatcher the late emits go through, so by the time HistoryCorrector runs, the IN-MEMORY
 * history already carries the late verdicts -- all that is missing is a second persist(), which
 * is safe by design (the writer merges under an exclusive lock and tracks changed entries). The
 * times are overwritten from Counit's measured durations first (skipping tests whose verdict is
 * a late skip -- blocking PHPUnit deliberately keeps a skipped test's old duration), and the
 * prune-vs-plain persist choice mirrors the handler's own flag. Everything is reached through
 * the same dispatcher-subscriber walk JunitXmlCorrector uses and fails soft: on any shape
 * mismatch the file simply stays as PHPUnit wrote it.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class HistoryCorrector
{
    /**
     * Re-persists the run's history with the late verdicts and measured durations; call from
     * ExecutionFinished, after the drain and after every late emit.
     */
    public static function correct(): void
    {
        try {
            // PHPUnit 13 spells it "test run history"; PHPUnit 12.5 "result cache". Same shape:
            // an abstract subscriber holding the handler, which holds the store. Class names as
            // strings, since each version ships only its own set.
            $subscriberClass = 'PHPUnit\Runner\TestRunHistory\Subscriber';
            $storeProperty   = 'testRunHistory';
            $idClass         = 'PHPUnit\Runner\TestRunHistory\TestRunHistoryId';
            if (!class_exists($subscriberClass)) {
                $subscriberClass = 'PHPUnit\Runner\ResultCache\Subscriber';
                $storeProperty   = 'cache';
                $idClass         = 'PHPUnit\Runner\ResultCache\ResultCacheId';
            }
            if (!class_exists($subscriberClass) || !class_exists($idClass)) {
                return;
            }

            $idFactory = [$idClass, 'fromTest'];
            // PHPStan resolves the installed PHPUnit's class pair and calls this check
            // redundant; it guards the other version's pair, which this vendor tree cannot see.
            if (!is_callable($idFactory)) { // @phpstan-ignore function.alreadyNarrowedType
                return;
            }

            $handler = null;
            foreach (JunitXmlCorrector::registeredSubscribers() as $subscriber) {
                if ($subscriber instanceof $subscriberClass) {
                    $handler = (new \ReflectionProperty($subscriberClass, 'handler'))->getValue($subscriber);

                    break;
                }
            }
            if (!is_object($handler)) {
                return; // No history/result cache configured for this run.
            }

            $store = (new \ReflectionProperty($handler, $storeProperty))->getValue($handler);
            if (!is_object($store)) {
                return;
            }

            $setTime = [$store, 'setTime'];
            $persist = [$store, 'persist'];
            if (!is_callable($setTime) || !is_callable($persist)) {
                return;
            }

            // A late-SKIPPED test's measured duration is its time-to-skip; blocking PHPUnit
            // deliberately does not record a skipped test's duration (its handler's
            // testWasSkipped guard -- which incomplete tests do NOT trip: their duration is
            // recorded upstream, so it is corrected here like any other).
            $lateSkipped = [];
            foreach (LateSkips::forReport() as $deferred) {
                if (!$deferred['throwable'] instanceof \PHPUnit\Framework\IncompleteTest) {
                    $lateSkipped[$deferred['test']->id()] = true;
                }
            }

            foreach (Counit::measuredDurations() as $testId => $duration) {
                if (!isset($lateSkipped[$testId])) {
                    $setTime($idFactory($duration['test']), round($duration['seconds'], 3));
                }
            }

            // PHPUnit 13 prunes on persist when the run executed every test; mirror its choice.
            $persistAndPrune = [$store, 'persistAndPrune'];
            $reflection      = new \ReflectionObject($handler);
            if ($reflection->hasProperty('pruneOnPersist')
                && (new \ReflectionProperty($handler, 'pruneOnPersist'))->getValue($handler) === true
                && is_callable($persistAndPrune)) {
                $persistAndPrune();
            } else {
                $persist();
            }
        } catch (\Throwable) {
            // PHPUnit's internals have changed; leave the file as PHPUnit wrote it.
        }
    }
}

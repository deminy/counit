<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\DeferringDispatcher;
use PHPUnit\Event\DirectDispatcher;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Logging\JUnit\JunitXmlLogger;
use PHPUnit\Logging\JUnit\Subscriber as JunitSubscriber;
use PHPUnit\Util\Xml;

/**
 * Corrects the per-testcase `assertions` attributes (and the testsuite aggregates computed from
 * them) in the JUnit XML report before it is written.
 *
 * The JUnit logger captures each test's count from the `Test\Finished` event -- the value PHPUnit
 * reads at the test's first yield. Unlike the run summary, the XML has no end-of-run total
 * correction, so under Swoole it carried the up-front assertion credit (inflating every
 * automatic-approach test's count, even in fully synchronous suites), missed the assertions
 * counted directly on the test object after its report, and attributed assertions performed
 * after a yield to whatever test's counting window happened to be open. Each testcase is
 * rewritten here with Counit::correctedAssertionCountFor()'s number, which reconstructs the
 * test's own count via segment accounting (see Attribution) -- exact whenever the test's yields
 * are observable, an undercount (never an overcount) otherwise.
 *
 * The logger creates a `<testcase>` element for every test *attempt* (at Test\PreparationStarted)
 * -- including tests that are skipped or fail before they are prepared and therefore never emit
 * Test\Finished. Elements are matched to recorded tests by their class and name attributes
 * (stamped from the same TestMethod accessors the recording uses, data-set/repetition suffixes
 * included), so an element with no record is simply left as PHPUnit wrote it.
 *
 * The logger buffers the whole report in one DOMDocument and only writes it when its own
 * ExecutionFinished subscriber runs -- which is after counit's, because extensions bootstrap
 * before log writers register. The document is reached through the event dispatcher's subscriber
 * list via reflection; on any failure (changed PHPUnit internals, no JUnit logging configured)
 * the report is simply left as PHPUnit produced it.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
class JunitXmlCorrector
{
    /**
     * Per test-class name and test name: the IDs of its finished tests, in emission order. The
     * name (from TestMethod::name()) already carries the data-set, repetition and attempt
     * suffixes, and PHPUnit refuses to add the same test file to a second testsuite (a runner
     * warning: "Cannot add file ... as it was already added to test suite ..."), so each queue
     * holds one entry in practice -- the same test never runs twice under one ID, which is also
     * what keeps the per-ID ledgers in Counit collision-free. The list is pure defense: should an
     * unforeseen path ever produce two elements sharing a class and name, they would pair with
     * their records in document order instead of corrupting each other.
     *
     * @var array<string, array<string, list<string>>>
     */
    private static array $testIdsByClass = [];

    /**
     * Called by CounitExtension's Test\Finished subscriber for every finished test method.
     */
    public static function recordTest(string $className, string $name, string $testId): void
    {
        self::$testIdsByClass[$className][$name][] = $testId;
    }

    /**
     * Rewrites the JUnit report's assertion counts; call once every test coroutine has drained.
     */
    public static function correct(): void
    {
        try {
            $document = self::junitDocument();
            if ($document === null) {
                return; // No JUnit logging configured for this run.
            }

            $queues = self::$testIdsByClass;

            // Stage every change first, then write: nothing below can realistically throw, but a
            // half-corrected report would be worse than an uncorrected one.
            /** @var list<array{\DOMElement, int}> $corrections */
            $corrections = [];
            /** @var list<array{\DOMElement, float}> $timeCorrections */
            $timeCorrections = [];

            foreach ($document->getElementsByTagName('testcase') as $testCase) {
                if (!$testCase->hasAttribute('class')) {
                    continue; // e.g. a PHPT test; not produced by a TestCase object.
                }

                $className = $testCase->getAttribute('class');
                $name      = $testCase->getAttribute('name');
                if (!isset($queues[$className][$name]) || $queues[$className][$name] === []) {
                    // No recorded test for this element: it never emitted Test\Finished (PHPUnit
                    // only emits that event for prepared tests, so a test skipped or failed in
                    // setUp() has an element but no record). Leave it as PHPUnit wrote it.
                    continue;
                }
                $testId = array_shift($queues[$className][$name]);

                $corrected = Counit::correctedAssertionCountFor($testId);
                if ($corrected !== null && $testCase->hasAttribute('assertions')) {
                    $corrections[] = [$testCase, $corrected];
                }

                // The logger's `time` attribute measured Prepared->Finished, which for a
                // non-joined test is time-to-first-yield (0.001s for a 1s test); replace it with
                // the coroutine's measured wall-clock duration -- approximately what a blocking
                // run reports. See Counit::recordTestDuration().
                $duration = Counit::durationFor($testId);
                if ($duration !== null && $testCase->hasAttribute('time')) {
                    $timeCorrections[] = [$testCase, $duration];
                }
            }

            foreach ($corrections as [$testCase, $corrected]) {
                $testCase->setAttribute('assertions', (string) $corrected);
            }

            foreach ($timeCorrections as [$testCase, $duration]) {
                $testCase->setAttribute('time', sprintf('%F', $duration));
            }

            self::writeDeferredVerdicts($document);

            // Recompute every testsuite aggregate from its (now corrected) descendant testcases.
            // The failures/errors/skipped aggregates count the verdict elements just written (and
            // PHPUnit's own), so the report's counters match its contents; the time aggregate is
            // only recomputed when a testcase time was actually rewritten (sums of per-test
            // durations, blocking's own semantics -- under concurrency they overlap, so the sum
            // exceeds the run's real wall time exactly as blocking's total would).
            foreach ($document->getElementsByTagName('testsuite') as $testSuite) {
                foreach (['assertions' => null, 'failures' => 'failure', 'errors' => 'error', 'skipped' => 'skipped'] as $attribute => $verdictElement) {
                    if (!$testSuite->hasAttribute($attribute)) {
                        continue;
                    }

                    $total = 0;
                    foreach ($testSuite->getElementsByTagName('testcase') as $testCase) {
                        if ($verdictElement === null) {
                            $total += (int) $testCase->getAttribute('assertions');
                        } elseif ($testCase->getElementsByTagName($verdictElement)->length > 0) {
                            $total++;
                        }
                    }
                    $testSuite->setAttribute($attribute, (string) $total);
                }

                if ($timeCorrections !== [] && $testSuite->hasAttribute('time')) {
                    $total = 0.0;
                    foreach ($testSuite->getElementsByTagName('testcase') as $testCase) {
                        $total += (float) $testCase->getAttribute('time');
                    }
                    $testSuite->setAttribute('time', sprintf('%F', $total));
                }
            }
        } catch (\Throwable) {
            // PHPUnit's internals have changed; leave the report as PHPUnit produced it.
        }
    }

    /**
     * Puts the JUnit logger (when one is registered) into a state where a replayed
     * skipped/incomplete/failed/errored event is harmless, returning a restore callback -- or
     * null when there is no JUnit logger, or its internals no longer match (the emitters'
     * per-emit catch then still turns a crash into their fallback path). The logger's testsuite
     * stack has already unwound when ExecutionFinished fires and its own `prepared` flag is
     * false, so an unshielded replayed event dies in handleFinish() on the empty stack, killing
     * the run with exit code 255 and a zero-byte report. With `prepared` set, the handlers only
     * append a verdict element to `currentTestCase` -- pointed at a DETACHED decoy element, so
     * the report is not touched (writeDeferredVerdicts() writes the real elements instead) --
     * and increment the current level's skipped/errors/failures counter, all snapshotted and
     * restored (the run is over; the counters are never written out).
     *
     * @internal this method is not covered by the backward compatibility promise for counit
     */
    public static function shieldLogger(): ?callable
    {
        try {
            $logger = self::junitLogger();
            if ($logger === null) {
                return null;
            }

            $reflection = new \ReflectionObject($logger);
            foreach (['document', 'prepared', 'currentTestCase', 'testSuiteSkipped', 'testSuiteErrors', 'testSuiteFailures', 'testSuiteLevel'] as $name) {
                if (!$reflection->hasProperty($name)) {
                    return null;
                }
            }

            $document = (new \ReflectionProperty($logger, 'document'))->getValue($logger);
            if (!$document instanceof \DOMDocument) {
                return null;
            }

            $prepared        = new \ReflectionProperty($logger, 'prepared');
            $currentTestCase = new \ReflectionProperty($logger, 'currentTestCase');
            $counterNames    = ['testSuiteSkipped', 'testSuiteErrors', 'testSuiteFailures'];

            $oldPrepared = $prepared->getValue($logger);
            $oldCurrent  = $currentTestCase->getValue($logger);
            $level       = (new \ReflectionProperty($logger, 'testSuiteLevel'))->getValue($logger);

            if (!is_bool($oldPrepared) || !is_int($level)) {
                return null;
            }

            $counterProperties = [];
            $oldCounters       = [];
            foreach ($counterNames as $name) {
                $property = new \ReflectionProperty($logger, $name);
                $counters = $property->getValue($logger);
                if (!is_array($counters)) {
                    return null;
                }

                $counterProperties[$name] = $property;
                $oldCounters[$name]       = $counters;
            }

            $prepared->setValue($logger, true);
            $currentTestCase->setValue($logger, $document->createElement('testcase'));
            foreach ($counterProperties as $name => $property) {
                $counters         = $oldCounters[$name];
                $counters[$level] ??= 0;
                $property->setValue($logger, $counters);
            }

            return static function () use ($logger, $prepared, $currentTestCase, $counterProperties, $oldPrepared, $oldCurrent, $oldCounters): void {
                $prepared->setValue($logger, $oldPrepared);
                $currentTestCase->setValue($logger, $oldCurrent);
                foreach ($counterProperties as $name => $property) {
                    $property->setValue($logger, $oldCounters[$name]);
                }
            };
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The JUnit logger instance, reached through the event dispatcher's subscriber list:
     * Facade -> DeferringDispatcher -> DirectDispatcher -> subscribers -> any JUnit subscriber ->
     * its logger. Null when no JUnit logging is configured. Public so LateSkips can shield the
     * logger's state while replaying deferred verdicts; see LateSkips::emitDeferred().
     */
    public static function junitLogger(): ?JunitXmlLogger
    {
        foreach (self::registeredSubscribers() as $subscriber) {
            if (!$subscriber instanceof JunitSubscriber) {
                continue;
            }

            $logger = (new \ReflectionProperty(JunitSubscriber::class, 'logger'))->getValue($subscriber);
            if ($logger instanceof JunitXmlLogger) {
                return $logger;
            }
        }

        return null;
    }

    /**
     * Every subscriber registered with the event dispatcher, flattened. Shared by junitLogger()
     * above and by HistoryCorrector (the test-run-history handler is reached the same way).
     * Empty on any shape mismatch.
     *
     * @return list<object>
     *
     * @internal this method is not covered by the backward compatibility promise for counit
     */
    public static function registeredSubscribers(): array
    {
        $facade = EventFacade::instance();

        $deferring = (new \ReflectionProperty($facade, 'deferringDispatcher'))->getValue($facade);
        if (!$deferring instanceof DeferringDispatcher) {
            return [];
        }

        $dispatcher = (new \ReflectionProperty($deferring, 'dispatcher'))->getValue($deferring);
        if (!$dispatcher instanceof DirectDispatcher) {
            return [];
        }

        $subscribers = (new \ReflectionProperty($dispatcher, 'subscribers'))->getValue($dispatcher);
        if (!is_array($subscribers)) {
            return [];
        }

        $flattened = [];
        foreach ($subscribers as $subscribersOfType) {
            if (!is_array($subscribersOfType)) {
                continue;
            }

            foreach ($subscribersOfType as $subscriber) {
                if (is_object($subscriber)) {
                    $flattened[] = $subscriber;
                }
            }
        }

        return $flattened;
    }

    /**
     * Writes the deferred post-yield verdicts into the report: a <failure>/<error> element for
     * every entry LateFailures recorded (in the exact shape PHPUnit's own logger writes, type
     * attribute and description-plus-stack-trace text included) and a <skipped/> element for
     * every entry LateSkips recorded -- PHPUnit's logger writes <skipped/> for incomplete tests
     * too. Without this, a test that failed or skipped only after its first yield stays "passed"
     * in the surface CI systems actually parse, while the run itself exits non-zero. Elements
     * are matched by class and name exactly like the assertion-count correction above; a verdict
     * whose element cannot be found (or which already carries one) is skipped.
     */
    private static function writeDeferredVerdicts(\DOMDocument $document): void
    {
        $verdicts = [];

        foreach (LateFailures::forReport() as $deferred) {
            $verdicts[] = [$deferred['test'], $deferred['throwable'], null];
        }
        foreach (LateSkips::forReport() as $deferred) {
            $verdicts[] = [$deferred['test'], $deferred['throwable'], 'skipped'];
        }

        if ($verdicts === []) {
            return;
        }

        // Class name -> test name -> the verdicts recorded for it, in order (repetitions of one
        // test under --repeat can defer more than one).
        $byClassAndName = [];
        foreach ($verdicts as [$test, $throwable, $type]) {
            if (!$test instanceof TestMethod) {
                continue;
            }

            $byClassAndName[$test->className()][$test->name()][] = [$throwable, $type];
        }

        foreach ($document->getElementsByTagName('testcase') as $testCase) {
            $className = $testCase->getAttribute('class');
            $name      = $testCase->getAttribute('name');
            if (!isset($byClassAndName[$className][$name]) || $byClassAndName[$className][$name] === []) {
                continue;
            }

            [$throwable, $type] = array_shift($byClassAndName[$className][$name]);

            if ($testCase->getElementsByTagName('failure')->length > 0
                || $testCase->getElementsByTagName('error')->length > 0
                || $testCase->getElementsByTagName('skipped')->length > 0) {
                continue; // Already carries a verdict (e.g. a natively reported repetition).
            }

            if ($type === 'skipped') {
                $testCase->appendChild($document->createElement('skipped'));

                continue;
            }

            // Mirrors JunitXmlLogger::handleFault(): failure for an assertion-level Throwable,
            // error otherwise, with the same element text.
            $type           = ($throwable instanceof \AssertionError || $throwable instanceof AssertionFailedError) ? 'failure' : 'error';
            $eventThrowable = ThrowableBuilder::from($throwable);
            $buffer         = sprintf('%s::%s%s', $className, $name, PHP_EOL);
            $buffer .= trim($eventThrowable->description() . PHP_EOL . $eventThrowable->stackTrace());

            $fault = $document->createElement($type, Xml::prepareString($buffer));
            $fault->setAttribute('type', $eventThrowable->className());
            $testCase->appendChild($fault);
        }
    }

    /**
     * The JUnit logger's in-memory report document. Null when no JUnit logging is configured.
     */
    private static function junitDocument(): ?\DOMDocument
    {
        $logger = self::junitLogger();
        if ($logger === null) {
            return null;
        }

        $document = (new \ReflectionProperty($logger, 'document'))->getValue($logger);

        return $document instanceof \DOMDocument ? $document : null;
    }
}

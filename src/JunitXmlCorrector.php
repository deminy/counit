<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestResult;

/**
 * Corrects the per-testcase `assertions` attributes (and the testsuite aggregates computed from
 * them) in the JUnit XML report before it is written.
 *
 * The JUnit logger reads each test's count in its own endTest() -- the value PHPUnit has on the
 * test object at the moment it reports it. Unlike the run summary, the XML has no end-of-run
 * total correction, so under Swoole it carried the up-front assertion credit (inflating every
 * automatic-approach test's count, even in fully synchronous suites), missed the assertions
 * counted directly on the test object after its report (a mock verified after a yield, an
 * addToAssertionCount() call from a tearDown() that runs inside the coroutine), and attributed
 * assertions performed after a yield to whatever test's counting window happened to be open.
 * Each testcase is rewritten here with Counit::correctedAssertionCountFor()'s number, which
 * reconstructs the test's own count via segment accounting (see Attribution) -- exact whenever
 * the test's yields are observable, an undercount (never an overcount) otherwise.
 *
 * Elements are matched to recorded tests by their class and name attributes (stamped from the
 * same accessors the recording uses, data-set suffix included), never by position, so an element
 * with no record -- e.g. a PHPT test or a synthetic warning test, neither of which is a TestCase
 * object -- is simply left as PHPUnit wrote it.
 *
 * The logger buffers the whole report in one DOMDocument and only writes it from flush(), which
 * PHPUnit's TestRunner calls (through TestResult::flushListeners()) after it has run the
 * AfterLastTestHook extensions -- i.e. after CounitExtension has drained every coroutine. The
 * document is reached through the run's TestResult, on which the logger is registered as a
 * listener; on any failure (changed PHPUnit internals, no JUnit logging configured) the report is
 * simply left as PHPUnit produced it.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
class JunitXmlCorrector
{
    /**
     * Per test-class name and test name: the keys of its reported tests, in report order. The
     * name (from TestCase::getName()) already carries the data-set suffix, so each queue normally
     * holds one entry; a genuine duplicate (the same class included in two testsuites) stays
     * FIFO-disambiguated in document order.
     *
     * @var array<string, array<string, int[]>>
     */
    private static $testKeysByClass = [];

    /**
     * Called by AssertionCountListener::endTest() for every reported test.
     */
    public static function recordTest(string $className, string $name, int $key): void
    {
        self::$testKeysByClass[$className][$name][] = $key;
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

            $queues = self::$testKeysByClass;

            // Stage every change first, then write: nothing below can realistically throw, but a
            // half-corrected report would be worse than an uncorrected one.
            $corrections     = [];
            $timeCorrections = [];

            foreach ($document->getElementsByTagName('testcase') as $testCase) {
                if (!$testCase->hasAttribute('class') || !$testCase->hasAttribute('assertions')) {
                    continue; // e.g. a PHPT test; not produced by a TestCase object.
                }

                $className = $testCase->getAttribute('class');
                $name      = $testCase->getAttribute('name');
                if (empty($queues[$className][$name])) {
                    // No recorded test for this element: leave it as PHPUnit wrote it.
                    continue;
                }
                $key = array_shift($queues[$className][$name]);

                $corrected = Counit::correctedAssertionCountFor($key);
                if ($corrected !== null) {
                    $corrections[] = [$testCase, $corrected];
                }

                // The logger's `time` attribute measured the test's report boundary, which for
                // a non-joined test is time-to-first-yield (0.001s for a 1s test); replace it
                // with the coroutine's measured wall-clock duration -- approximately what a
                // blocking run reports. See Counit::recordTestDuration().
                $duration = Counit::durationForKey($key);
                if ($duration !== null && $testCase->hasAttribute('time')) {
                    $timeCorrections[] = [$testCase, $duration];
                }
            }

            foreach ($corrections as $correction) {
                $correction[0]->setAttribute('assertions', (string) $correction[1]);
            }

            foreach ($timeCorrections as $correction) {
                $correction[0]->setAttribute('time', sprintf('%F', $correction[1]));
            }

            self::writeDeferredVerdicts($document);

            // Recompute every testsuite aggregate from its (now corrected) descendant testcases.
            // The failures/errors/skipped aggregates count the verdict elements just written
            // (and PHPUnit's own), so the report's counters match its contents.
            foreach ($document->getElementsByTagName('testsuite') as $testSuite) {
                foreach (['assertions' => null, 'failures' => 'failure', 'errors' => 'error', 'skipped' => 'skipped'] as $attribute => $verdictElement) {
                    if (!$testSuite->hasAttribute($attribute)) {
                        continue;
                    }

                    $total = 0;
                    foreach ($testSuite->getElementsByTagName('testcase') as $testCase) {
                        if ($verdictElement === null) {
                            if ($testCase->hasAttribute('assertions')) {
                                $total += (int) $testCase->getAttribute('assertions');
                            }
                        } elseif ($testCase->getElementsByTagName($verdictElement)->length > 0) {
                            $total++;
                        }
                    }
                    $testSuite->setAttribute($attribute, (string) $total);
                }

                // The time aggregate is only recomputed when a testcase time was actually
                // rewritten (sums of per-test durations, blocking's own semantics -- under
                // concurrency they overlap, so the sum exceeds the run's real wall time exactly
                // as blocking's total would).
                if ($timeCorrections !== [] && $testSuite->hasAttribute('time')) {
                    $total = 0.0;
                    foreach ($testSuite->getElementsByTagName('testcase') as $testCase) {
                        $total += (float) $testCase->getAttribute('time');
                    }
                    $testSuite->setAttribute('time', sprintf('%F', $total));
                }
            }
        } catch (\Throwable $e) {
            // PHPUnit's internals have changed; leave the report as PHPUnit produced it.
        }
    }

    /**
     * Writes the deferred post-yield verdicts into the report: a <failure>/<error> element for
     * every deferred failure (in the exact shape the JUnit logger's own doAddFault() writes,
     * type attribute and description-plus-stack-trace text included) and a <skipped/> element
     * for every deferred skip/incomplete -- the logger writes <skipped/> for incomplete tests
     * too. The logger's own listener callbacks no-op for a late verdict (its currentTestCase is
     * null once the run has moved past the test), so without this a test that failed or skipped
     * only after its first yield stayed "passed" in the surface CI systems actually parse, while
     * the run itself exited non-zero. Elements are matched by class and name exactly like the
     * assertion-count correction above; a verdict whose element cannot be found (or which
     * already carries one) is skipped.
     *
     * @param \DOMDocument $document
     */
    private static function writeDeferredVerdicts($document): void
    {
        if (Counit::$verdictsForReport === []) {
            return;
        }

        // Class name -> test name -> the verdicts recorded for it, in order.
        $byClassAndName = [];
        foreach (Counit::$verdictsForReport as $verdict) {
            $test = $verdict['test'];

            $byClassAndName[get_class($test)][$test->getName(true)][] = $verdict['throwable'];
        }

        foreach ($document->getElementsByTagName('testcase') as $testCase) {
            $className = $testCase->getAttribute('class');
            $name      = $testCase->getAttribute('name');
            if (empty($byClassAndName[$className][$name])) {
                continue;
            }

            $throwable = array_shift($byClassAndName[$className][$name]);

            if ($testCase->getElementsByTagName('failure')->length > 0
                || $testCase->getElementsByTagName('error')->length > 0
                || $testCase->getElementsByTagName('skipped')->length > 0) {
                continue; // Already carries a verdict.
            }

            if (($throwable instanceof \PHPUnit\Framework\SkippedTest) || ($throwable instanceof \PHPUnit\Framework\IncompleteTest)) {
                $testCase->appendChild($document->createElement('skipped'));

                continue;
            }

            // Mirrors the JUnit logger's doAddFault(): failure for an assertion-level
            // Throwable, error otherwise, with the same element text.
            $type   = $throwable instanceof \PHPUnit\Framework\AssertionFailedError ? 'failure' : 'error';
            $buffer = sprintf('%s::%s', $className, $name) . "\n";
            $buffer .= trim(
                \PHPUnit\Framework\TestFailure::exceptionToString($throwable) . "\n"
                . \PHPUnit\Util\Filter::getFilteredStacktrace($throwable)
            );

            $fault = $document->createElement($type, \PHPUnit\Util\Xml::prepareString($buffer));
            $fault->setAttribute('type', get_class($throwable));
            $testCase->appendChild($fault);
        }
    }

    /**
     * The JUnit logger's in-memory report, reached through the listeners of the run's TestResult
     * (PHPUnit registers the logger as one). Null when no JUnit logging is configured.
     *
     * @return \DOMDocument|null
     */
    private static function junitDocument()
    {
        if (!Counit::$testResult instanceof TestResult) {
            return null;
        }

        $listeners = new \ReflectionProperty(TestResult::class, 'listeners');
        if (PHP_VERSION_ID < 80100) {
            $listeners->setAccessible(true);
        }
        $value = $listeners->getValue(Counit::$testResult);

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $listener) {
            // Looked up by property name rather than by logger class, so that both PHPUnit 8's
            // and PHPUnit 9's JUnit logger (and any subclass) are covered.
            if (!is_object($listener) || !($listener instanceof \PHPUnit\Framework\TestListener)) {
                continue;
            }

            $reflection = new \ReflectionObject($listener);
            if (!$reflection->hasProperty('document')) {
                continue;
            }

            $property = $reflection->getProperty('document');
            if (PHP_VERSION_ID < 80100) {
                $property->setAccessible(true);
            }
            $document = $property->getValue($listener);

            if ($document instanceof \DOMDocument) {
                return $document;
            }
        }

        return null;
    }
}

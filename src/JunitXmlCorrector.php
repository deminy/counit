<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\DeferringDispatcher;
use PHPUnit\Event\DirectDispatcher;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Logging\JUnit\JunitXmlLogger;
use PHPUnit\Logging\JUnit\Subscriber as JunitSubscriber;

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
            }

            foreach ($corrections as [$testCase, $corrected]) {
                $testCase->setAttribute('assertions', (string) $corrected);
            }

            // Recompute every testsuite aggregate from its (now corrected) descendant testcases.
            foreach ($document->getElementsByTagName('testsuite') as $testSuite) {
                if (!$testSuite->hasAttribute('assertions')) {
                    continue;
                }

                $total = 0;
                foreach ($testSuite->getElementsByTagName('testcase') as $testCase) {
                    if ($testCase->hasAttribute('assertions')) {
                        $total += (int) $testCase->getAttribute('assertions');
                    }
                }
                $testSuite->setAttribute('assertions', (string) $total);
            }
        } catch (\Throwable) {
            // PHPUnit's internals have changed; leave the report as PHPUnit produced it.
        }
    }

    /**
     * The JUnit logger's in-memory report, reached through the event dispatcher's subscriber list:
     * Facade -> DeferringDispatcher -> DirectDispatcher -> subscribers -> any JUnit subscriber ->
     * its logger -> the logger's DOMDocument. Null when no JUnit logging is configured.
     */
    private static function junitDocument(): ?\DOMDocument
    {
        $facade = EventFacade::instance();

        $deferring = (new \ReflectionProperty($facade, 'deferringDispatcher'))->getValue($facade);
        if (!$deferring instanceof DeferringDispatcher) {
            return null;
        }

        $dispatcher = (new \ReflectionProperty($deferring, 'dispatcher'))->getValue($deferring);
        if (!$dispatcher instanceof DirectDispatcher) {
            return null;
        }

        $subscribers = (new \ReflectionProperty($dispatcher, 'subscribers'))->getValue($dispatcher);
        if (!is_array($subscribers)) {
            return null;
        }

        foreach ($subscribers as $subscribersOfType) {
            if (!is_array($subscribersOfType)) {
                continue;
            }

            foreach ($subscribersOfType as $subscriber) {
                if (!$subscriber instanceof JunitSubscriber) {
                    continue;
                }

                $logger = (new \ReflectionProperty(JunitSubscriber::class, 'logger'))->getValue($subscriber);
                if (!$logger instanceof JunitXmlLogger) {
                    continue;
                }

                $document = (new \ReflectionProperty($logger, 'document'))->getValue($logger);

                return $document instanceof \DOMDocument ? $document : null;
            }
        }

        return null;
    }
}

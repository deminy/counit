<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestResult;
use Swoole\Coroutine;

/**
 * Recovers the assertions PHPUnit counts *on the test object* rather than through its static
 * assertion counter -- verifyMockObjects() (one per mock with matchers, plus one per checked
 * prophecy prediction) and the exception-expectation checks in runTest() all do
 * `$this->numAssertions++` directly. Under counit those run inside the test's coroutine, which
 * usually resumes only after PHPUnit has already read the test's count (the result printer reads
 * it in endTest(), which fires when the test's coroutine first yields), so they would silently
 * vanish from the run's reported total -- a mis-count that does not fail the run.
 *
 * This listener is registered on the run's TestResult (a public API: TestResult::addListener())
 * from Counit::create(), i.e. after PHPUnit registered its own result printer, so its endTest()
 * observes exactly the count the printer just consumed. The difference between that snapshot and
 * the test's final count -- read after CounitExtension has drained every coroutine -- is what got
 * lost, and is added back to the run's total there.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
class AssertionCountListener implements TestListener
{
    use TestListenerDefaultImplementation;

    /**
     * @var self|null
     */
    private static $instance;

    /**
     * Per test: the assertion count PHPUnit read for it when the test ended.
     *
     * The test objects are kept alive by PHPUnit's own TestSuite for the whole run anyway, so
     * holding them here adds no meaningful memory beyond one integer per test.
     *
     * @var \SplObjectStorage<BaseTestCase, int>
     */
    private $countsAtEndOfTest;

    /**
     * Per test key (spl_object_id()): the assertion count PHPUnit reported for that test.
     *
     * @var array<int, int>
     */
    private $emitted = [];

    /**
     * Per test key: the test object, kept alive so its spl_object_id() is never recycled and its
     * final assertion count can be read at the end of the run.
     *
     * @var array<int, BaseTestCase>
     */
    private $tests = [];

    /**
     * The TestResult objects this listener is already registered on.
     *
     * @var \SplObjectStorage<TestResult, true>
     */
    private $results;

    private function __construct()
    {
        $this->countsAtEndOfTest = new \SplObjectStorage();
        $this->results           = new \SplObjectStorage();
    }

    /**
     * Register the listener on the run's TestResult, at most once per TestResult.
     */
    public static function attach(TestResult $result): void
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        if (isset(self::$instance->results[$result])) {
            return;
        }

        self::$instance->results[$result] = true;
        $result->addListener(self::$instance);
    }

    /**
     * The assertions that were counted on their test object after PHPUnit had already read that
     * object's count. Call this only once every test coroutine has finished, otherwise late
     * assertions are still on their way.
     */
    public static function lostAssertionCount(): int
    {
        if (self::$instance === null) {
            return 0;
        }

        $lost = 0;
        foreach (self::$instance->countsAtEndOfTest as $test) {
            $delta = $test->getNumAssertions() - self::$instance->countsAtEndOfTest[$test];

            // A negative delta means the test object was run again after the snapshot (PHPUnit
            // resets the count in runBare()), e.g. under --repeat; nothing was lost then.
            if ($delta > 0) {
                $lost += $delta;
            }
        }

        return $lost;
    }

    /**
     * The assertion count PHPUnit reported for $test -- the number the JUnit logger wrote into
     * that test's <testcase> element -- or null when the test was never reported (it ran before
     * this listener could be registered; see attach()).
     *
     * @return int|null
     */
    public static function emittedCountFor(int $key)
    {
        if (self::$instance === null) {
            return null;
        }

        return isset(self::$instance->emitted[$key]) ? self::$instance->emitted[$key] : null;
    }

    /**
     * The assertions counted on the test object after PHPUnit had already reported it. Call this
     * only once the test's coroutine has finished.
     */
    public static function lateCountFor(int $key): int
    {
        if ((self::$instance === null) || !isset(self::$instance->tests[$key])) {
            return 0;
        }

        $test    = self::$instance->tests[$key];
        $emitted = isset(self::$instance->emitted[$key]) ? self::$instance->emitted[$key] : null;

        if ($emitted === null) {
            return 0;
        }

        return max(0, $test->getNumAssertions() - $emitted);
    }

    /**
     * {@inheritDoc}
     *
     * Fires right after PHPUnit reset its static assertion counter for $test and before the
     * test's setUp() runs, which is exactly the segment boundary Attribution needs -- and, for a
     * test PHPUnit brackets with a global-state snapshot, the one seam early enough for the
     * pre-snapshot drain barrier (TestResult::run() calls this before runBare(), which takes the
     * snapshot as nearly its first statement). See GlobalState for the whole design.
     */
    public function startTest(Test $test): void
    {
        if ($test instanceof BaseTestCase) {
            // The barrier half of the @backupGlobals/@backupStaticAttributes support: drain
            // every in-flight test coroutine before this test's snapshot can be taken, so the
            // restore -- which reverts to that snapshot -- can never wipe a concurrent test's
            // global writes. Runs before Attribution::testStarting() below, so the draining
            // coroutines' assertion segments stay attributed to their own tests; the increments
            // themselves land in this test's already-open counter window (the reset is behind
            // us): mis-attributed but never wiped, the bucket the end-of-run total correction
            // already handles. A test that needs the barrier but ran before this listener was
            // attached cannot exist: pending coroutines imply an earlier Counit::create()/
            // createAndJoin() call, which is what attaches the listener.
            if (Helper::isCoroutineFriendly() && GlobalState::isBackedUp($test)) {
                Attribution::suspended();
                while (Coroutine::stats()['coroutine_num'] > 1) { // @phpstan-ignore offsetAccess.nonOffsetAccessible
                    Coroutine::sleep(0.01);
                }
                Attribution::resumed();
            }

            Attribution::testStarting(spl_object_id($test));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function endTest(Test $test, float $time): void
    {
        if ($test instanceof BaseTestCase) {
            $key                            = spl_object_id($test);
            $this->countsAtEndOfTest[$test] = $test->getNumAssertions();
            $this->emitted[$key]            = $test->getNumAssertions();
            $this->tests[$key]              = $test;

            // Captures the counter window PHPUnit harvested into this test; must run before
            // anything else can move the counter, hence the notification from here.
            Attribution::testFinished($key);

            JunitXmlCorrector::recordTest(get_class($test), $test->getName(), $key);
        }
    }
}

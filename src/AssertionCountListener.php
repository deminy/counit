<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase as BaseTestCase;
use PHPUnit\Framework\TestListener;
use PHPUnit\Framework\TestListenerDefaultImplementation;
use PHPUnit\Framework\TestResult;

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
     * {@inheritDoc}
     */
    public function endTest(Test $test, float $time): void
    {
        if ($test instanceof BaseTestCase) {
            $this->countsAtEndOfTest[$test] = $test->getNumAssertions();
        }
    }
}

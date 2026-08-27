<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestResult;
use PHPUnit\Runner\AfterLastTestHook;
use PHPUnit\Runner\BeforeFirstTestHook;
use PHPUnit\Runner\BeforeTestHook;
use Swoole\Coroutine;

/**
 * @internal this class is not covered by the backward compatibility promise for counit
 *
 * Register this extension in your phpunit.xml / phpunit.xml.dist:
 *
 *     <extensions>
 *         <extension class="Deminy\Counit\CounitExtension"/>
 *     </extensions>
 *
 * (PHPUnit 10 removed the test-hook interfaces this class implements, so the 1.x line registers it
 * as <bootstrap class="..."/> instead; the two forms are not interchangeable.)
 *
 * It is what waits for every coroutine to drain before PHPUnit's summary is taken, so it is what
 * makes the run's reported time, its assertion totals, and the replay of late
 * failure/skip/incomplete/risky verdicts correct. Unregistered, PHPUnit prints its summary while
 * tests are still sleeping: the reported time can understate the truth by orders of magnitude, and
 * assertion totals are neither correct nor consistent between a full run and the same tests run in
 * isolation. See docs/compatibility.md, whose every guarantee assumes this extension is registered.
 */
class CounitExtension implements AfterLastTestHook, BeforeFirstTestHook, BeforeTestHook
{
    /**
     * {@inheritDoc}
     */
    public function executeBeforeFirstTest(): void
    {
        // Segment accounting (see Attribution) relies on cooperative scheduling: a coroutine only
        // ever switches at a yield. Swoole's preemptive scheduler breaks that premise, so
        // attribution stays off under it and the JUnit per-testcase correction falls back to the
        // credit/late arithmetic in Counit::correctedAssertionCountFor().
        Attribution::$enabled = Helper::isCoroutineFriendly()
            && !filter_var((string) ini_get('swoole.enable_preemptive_scheduler'), FILTER_VALIDATE_BOOLEAN);

        if (Helper::isCoroutineFriendly()) {
            // Convert diagnostics a test triggers after its first yield; see Diagnostics.
            Diagnostics::initialize();
        }
    }

    /**
     * {@inheritDoc}
     *
     * Runs from the run's first test onwards, before setUp() and before anything else of that
     * test's class can execute -- which is when the sleep()/usleep() shims for its namespace have
     * to be in place. PHPUnit hands hooks the test's description ("Class::method"), and that is
     * all installShims() needs. (This hook, unlike counit's own TestListener, is registered by
     * PHPUnit before the run starts, so no test is missed.)
     */
    public function executeBeforeTest(string $test): void
    {
        $separator = strpos($test, '::');
        if ($separator !== false) {
            Attribution::installShims(substr($test, 0, $separator));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function executeAfterLastTest(): void
    {
        if (Helper::isCoroutineFriendly()) {
            // Everything in PHPUnit's static assertion counter at this point was already harvested
            // into the last test: the counter is read at the end of each test but only reset at the
            // start of the next one, and no test coroutine can have run in between -- PHPUnit's own
            // bookkeeping between tests (progress output, result cache) only performs STDIO/file
            // calls, which are deliberately excluded from the coroutine hooks (see
            // Helper::coroutineHookFlags()), so control never leaves the main coroutine there.
            // Reset the counter so that, after the drain below, it holds exactly the assertions
            // that ran too late for PHPUnit to see.
            Assert::resetCount();
            Attribution::counterReset();

            // When the only coroutine left is the one created in script /counit, it means all the tests are finally
            // done, and it's time to hand it over to PHPUnit to take care of the rest part.
            // The drain is the main coroutine's longest yield of the run and the one most
            // post-yield test code runs in; it does not go through Attribution's observation
            // points, so counit's converting handler is put on the stack for it here. For the
            // same reason the drain runs outside every per-test code-coverage window, so a
            // coverage window of its own is opened around it -- without one, every post-yield
            // line silently vanishes from the aggregate report. See Coverage.
            Diagnostics::suspended();
            Coverage::startDrainWindow();
            while (Coroutine::stats()['coroutine_num'] > 1) { // @phpstan-ignore offsetAccess.nonOffsetAccessible
                Coroutine::sleep(0.2);
            }
            Coverage::stopDrainWindow();
            Diagnostics::resumed();

            // Correct the run's reported assertion total. PHPUnit attributes assertions to tests
            // through a per-test window over a static counter, so under counit every real assertion
            // ends up in exactly one of two places: harvested into whatever test's window happened
            // to be open when it ran (already part of the reported total, possibly double-counting
            // an up-front credit), or -- having run after the last window closed -- in the counter
            // residue drained above. Therefore:
            //     true total = reported total - up-front credits + residue + late instance counts
            // which holds for both the automatic and the manual approach. The last term covers the
            // assertions PHPUnit counts on the test object instead of through the static counter --
            // mock/prophecy verification and the exception-expectation checks -- which run inside
            // the test's coroutine, after PHPUnit read that test's count; see
            // AssertionCountListener.
            // The JUnit XML report needs its own correction: its per-testcase `assertions`
            // attributes were read from the test objects as PHPUnit reported them, so they carry
            // the up-front credits (inflating every automatic-approach test's count, even in
            // fully synchronous suites), miss the assertions counted on the test object after its
            // report, and hold whatever the test's counting window happened to catch. The logger
            // buffers the report and only writes it from flush(), which TestResult::flushListeners()
            // triggers after this hook has run.
            // Replay the skip/incomplete verdicts PHPUnit could not reach on its own: a
            // markTestSkipped()/markTestIncomplete() call made after the test's first yield is
            // thrown mid-body -- nothing is registered up front for a join decision to detect --
            // and lands in Counit::$deferredSkips after PHPUnit already reported the test as
            // passed. Handing each stashed Throwable to the public TestResult::addError() here,
            // after the drain and before the printer reads the result, classifies it natively
            // (PHPUnit 8/9 route SkippedTest/IncompleteTest to their skipped/incomplete records
            // in addError()): the Skipped:/Incomplete: summary counts, the listings and -- via
            // the script's exit-code alignment -- the --fail-on-skipped/--fail-on-incomplete
            // exit codes (PHPUnit 9) match a blocking run exactly, with no test-count
            // compensation needed. A successfully replayed entry leaves $deferredSkips, so the
            // script's notice only reports what could not be recorded; the recorded per-test
            // status stays "passed" (the run already moved past it), and the printer appends a
            // late S/I progress character -- both documented. Runs before the useless-test pass
            // below so the listener verdicts re-mark the replayed tests as aborted.
            if (Counit::$testResult instanceof TestResult) {
                foreach (Counit::$deferredSkips as $description => $throwable) {
                    $test = Counit::$deferredSkipTests[$description] ?? null;
                    if ($test === null) {
                        continue;
                    }

                    try {
                        Counit::$testResult->addError($test, $throwable, 0.0);
                        unset(Counit::$deferredSkips[$description]);
                    } catch (\Throwable $t) {
                        // Left in $deferredSkips: the script's notice reports it instead.
                    }
                }
            }
            Counit::$deferredSkipTests = [];

            // Replay the failure/error verdicts the same way: a body (or in-coroutine hook)
            // Throwable that arrived only after the test's first yield lands in
            // Counit::$deferredFailures, and used to surface only in the `counit` script's
            // STDERR block with a forced exit code 1 while PHPUnit itself reported OK. Handing
            // it to the public addFailure()/addError() here -- blocking PHPUnit's own
            // classification: an AssertionFailedError fails the test, anything else errors it
            // -- makes the FAILURES!/ERRORS! summary counts, the listings and (via the script's
            // alignment) the native exit codes 1/2 match a blocking run exactly. A successfully
            // replayed entry leaves $deferredFailures, so the script's block only reports what
            // could not be recorded -- and only that fallback still forces exit code 1.
            if (Counit::$testResult instanceof TestResult) {
                foreach (Counit::$deferredFailures as $description => $throwable) {
                    $test = Counit::$deferredFailureTests[$description] ?? null;
                    if ($test === null) {
                        continue;
                    }

                    try {
                        if ($throwable instanceof AssertionFailedError) {
                            Counit::$testResult->addFailure($test, $throwable, 0.0);
                        } else {
                            Counit::$testResult->addError($test, $throwable, 0.0);
                        }
                        unset(Counit::$deferredFailures[$description]);
                    } catch (\Throwable $t) {
                        // Left in $deferredFailures: the script's block reports it instead.
                    }
                }
            }
            Counit::$deferredFailureTests = [];

            // Emit the "did not perform any assertions" verdicts (and their mirror) PHPUnit
            // could not reach on its own, now that every coroutine has drained and the per-test
            // tallies are final. This hook runs before the result printer writes its footer, so
            // a RiskyTestError handed to TestResult::addFailure() here still enters the risky
            // list, the summary's Risky count, and -- through the `counit` script's exit-code
            // alignment -- the --fail-on-risky exit code.
            UselessTests::emitDeferred();

            // The result cache's defects are already exact (the replays above notify its
            // listener adapter, and it persists only after this hook); its times still hold
            // time-to-first-yield, so they are overwritten with the measured wall-clock
            // durations before that persist. See HistoryCorrector.
            HistoryCorrector::correct();

            JunitXmlCorrector::correct();

            $this->correctAssertionCount(
                Assert::getCount()
                - Counit::$creditedAssertionCount
                + AssertionCountListener::lostAssertionCount()
            );
        }
    }

    /**
     * Add $delta to the assertion total PHPUnit is about to report.
     *
     * This hook runs before the result printer prints its footer, so adjusting the printer here
     * makes the reported total match a blocking (non-Swoole) run exactly. Unlike the per-test
     * counts, that total lives in the printer alone -- TestResult does not track it -- and the
     * printer keeps it in a property with no public mutator, hence the reflection. The printer is
     * reached through the TestResult it was registered on as a listener, since this hook is handed
     * nothing of its own; the property is looked up by name rather than by printer class so that
     * both PHPUnit 8's ResultPrinter and PHPUnit 9's DefaultResultPrinter (and subclasses of it,
     * such as CliTestDoxPrinter) are covered. If PHPUnit's internals change, the correction is
     * skipped rather than breaking the run.
     */
    private function correctAssertionCount(int $delta): void
    {
        if (($delta === 0) || !(Counit::$testResult instanceof TestResult)) {
            return;
        }

        try {
            $listeners = new \ReflectionProperty(TestResult::class, 'listeners');
            if (PHP_VERSION_ID < 80100) {
                // A no-op since PHP 8.1 (and deprecated since 8.5), but required on the PHP 7.2
                // through 8.0 part of this branch's supported range.
                $listeners->setAccessible(true);
            }
            $value = $listeners->getValue(Counit::$testResult);

            if (!is_array($value)) {
                return;
            }

            foreach ($value as $listener) {
                if (!is_object($listener)) {
                    continue;
                }

                $reflection = new \ReflectionObject($listener);
                if (!$reflection->hasProperty('numAssertions')) {
                    continue;
                }

                $property = $reflection->getProperty('numAssertions');
                if (PHP_VERSION_ID < 80100) {
                    // Same as above: only needed on PHP < 8.1.
                    $property->setAccessible(true);
                }
                $total = $property->getValue($listener);

                if (is_int($total)) {
                    $property->setValue($listener, max(0, $total + $delta));
                }
            }
        } catch (\ReflectionException $e) {
            // PHPUnit's internals have changed; leave the (approximate) total as is.
        }
    }
}

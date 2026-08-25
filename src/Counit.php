<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;

/**
 * This class allows unit tests to run in parallel (using counit + Swoole) or in blocking mode (default behavior).
 */
class Counit
{
    /**
     * Sum of all assertion counts actually credited to tests via creditAssertionCount(): the
     * $count values applied for manual-approach tests, plus the single credit
     * TestCase::runBare() records for every automatic-approach test. (A requested credit is
     * declined -- and therefore not ledgered here -- for a test that declares it performs no
     * assertions; see creditAssertionCount().) These credits stand in for
     * assertions that will only run later inside a coroutine -- but those assertions ALSO increment
     * PHPUnit's static assertion counter when they eventually run, so they either get harvested
     * into whatever test happens to be current at that moment (double-counting them) or, after the
     * last test, never get harvested at all. CounitExtension uses this ledger together with the
     * counter residue left after draining all coroutines to correct the run's reported assertion
     * total to exactly what a blocking (non-Swoole) run would have reported.
     *
     * @var int
     */
    public static $creditedAssertionCount = 0;

    /**
     * The TestResult of the current run, remembered from the first test that creates a coroutine.
     * PHPUnit 8/9 keeps the run's assertion total in its result printer rather than in TestResult
     * itself, and the printer is reachable only as one of the TestResult's listeners; this is how
     * CounitExtension gets to it (it receives no arguments of its own). Null when no test created
     * a coroutine, in which case there is nothing to correct.
     *
     * @var \PHPUnit\Framework\TestResult|null
     */
    public static $testResult;

    /**
     * Failures/errors thrown by a coroutine *after* create() already returned to its caller --
     * meaning the caller (and, for tests, PHPUnit itself) already moved on assuming success.
     * Coroutine::create() only returns once the coroutine finishes OR yields (e.g. on sleep()/IO
     * -- that's what lets other coroutines run concurrently in the meantime); if the callable
     * throws only after such a yield, nothing is left to observe it synchronously, and Swoole does
     * not propagate an uncaught Throwable out of a coroutine to its caller -- it becomes a fatal
     * error that kills the whole process instead. Catching it here and queuing it avoids the
     * crash; the `counit` script checks this once every coroutine has drained and fails the whole
     * run if it's non-empty, instead of letting a false "pass" stand uncorrected.
     *
     * @var array<string, \Throwable>
     */
    public static $deferredFailures = [];

    /**
     * Like $deferredFailures, but for a markTestSkipped()/markTestIncomplete() call that happened
     * only after the test's coroutine had already yielded: PHPUnit reported the test as passed at
     * that first yield, and its status cannot be changed afterwards. Unlike a real late failure,
     * this must not fail the run -- blocking PHPUnit exits 0 for skipped/incomplete tests -- so
     * the `counit` script only prints these as a notice, without touching the exit code.
     *
     * @var array<string, \Throwable>
     */
    public static $deferredSkips = [];

    /**
     * Per test key (spl_object_id() of its TestCase object): the up-front assertion credit
     * applied to it. The run total only needs the sum ($creditedAssertionCount); the JUnit
     * per-testcase correction needs to know which test carries which credit.
     *
     * @var array<int, int>
     */
    private static $appliedCredits = [];

    /**
     * To run test cases asynchronously when running unit tests using counit (and with the Swoole extension enabled).
     * If the Swoole extension is not enabled, or counit is not in use, the test cases will be executed in the same way
     * as under PHPUnit.
     *
     * @param int $count an optional parameter to suppress warning message "This test did not perform any assertions",
     *                   and to make the counters match. The credit is a request: it is declined for a test that
     *                   declares -- through the annotation @doesNotPerformAssertions or a call to method
     *                   expectNotToPerformAssertions() -- that it performs no assertions, since PHPUnit would
     *                   otherwise report the credited test as risky.
     * @return int return 0 if not running inside a coroutine; otherwise, return the coroutine ID, or -1 when failed
     *             creating a new coroutine to run the tests
     */
    public static function create(callable $callable, int $count = 0): int
    {
        if (Helper::isCoroutineFriendly()) {
            $trace  = debug_backtrace();
            $caller = $trace[1]['object'] ?? null;

            if ($caller instanceof TestCase) {
                $testResult = $caller->getTestResultObject();
                if ($testResult !== null) {
                    self::$testResult = $testResult;
                    // Registered here, rather than from CounitExtension, because the listener has
                    // to sit *after* PHPUnit's own result printer in the TestResult's listener
                    // list: it snapshots the very count the printer just consumed. See
                    // AssertionCountListener.
                    AssertionCountListener::attach($testResult);
                }
            }

            if ($count > 0) {
                if ($caller instanceof TestCase) {
                    self::creditAssertionCount($caller, $count);
                } else {
                    throw new Exception(sprintf('Method "%s" should be called directly in a test method of a %s object.', __METHOD__, TestCase::class));
                }
            }

            $description = $caller instanceof TestCase
                ? sprintf('%s::%s', get_class($caller), $caller->getName(true))
                : sprintf('%s() call', __METHOD__);

            $caught          = null;
            $alreadyReturned = false;
            $key             = ($caller instanceof TestCase) ? spl_object_id($caller) : null;

            if ($key !== null) {
                // Normally a no-op: the run's TestListener already claimed the counter for this
                // test at startTest(). It matters for the one test whose startTest() fired before
                // that listener could be registered (it is registered from this very method, on
                // its first call) -- nothing has yielded yet at that point, so the counter's
                // whole current value belongs to this test and is claimed retroactively.
                Attribution::claimMain($key);
            }

            $id = Coroutine::create(function () use ($callable, $key, &$caught, &$alreadyReturned, $description): void {
                Attribution::coroutineStarted($key);

                try {
                    $callable();
                } catch (\Throwable $e) {
                    // PHPStan sees only the value $alreadyReturned holds when the closure is
                    // created; it is flipped to true (by reference) below, before a coroutine
                    // that yielded resumes and can reach this catch block.
                    if ($alreadyReturned) { // @phpstan-ignore if.alwaysFalse
                        if (($e instanceof SkippedTest) || ($e instanceof IncompleteTest)) {
                            self::$deferredSkips[$description] = $e;
                        } else {
                            self::$deferredFailures[$description] = $e;
                        }
                    } else {
                        $caught = $e;
                    }
                } finally {
                    Attribution::coroutineFinished();
                }
            });
            $alreadyReturned = true;

            // The calling coroutine is running again (the child finished or yielded): re-claim
            // the assertion counter for whichever test it is running.
            Attribution::resumed();

            if ($caught !== null) {
                throw $caught;
            }

            return ($id !== false) ? $id : -1; // @phpstan-ignore return.type
        }

        $callable();
        return 0;
    }

    /**
     * Credit a test with assertions it has not performed yet, and record the credit in the ledger
     * CounitExtension subtracts again at the end of the run (see $creditedAssertionCount).
     *
     * The credit suppresses the "This test did not perform any assertions" warning for a test whose
     * assertions all run later, inside its coroutine, after PHPUnit has already read its count.
     */
    public static function creditAssertionCount(TestCase $test, int $count): void
    {
        // A test that declares it performs no assertions -- through the annotation
        // @doesNotPerformAssertions or a call to expectNotToPerformAssertions() -- must not be
        // credited: PHPUnit would then report it as risky ('This test is annotated with
        // "@doesNotPerformAssertions" but performed 1 assertions'). The credit would serve no
        // purpose there anyway (PHPUnit never emits the "did not perform any assertions" warning
        // for such a test), and CounitExtension subtracts every credit from the run's total again,
        // so declining it costs nothing. The flag is always settled by the time this runs: PHPUnit
        // resolves the annotation inside its own runBare() -- before setUp() and before anything
        // that can yield under counit's hook mask -- which the automatic approach runs inside the
        // coroutine that create() has already waited on, and which for a manual-approach test
        // completed on the main coroutine before the test method (and its create() call) started.
        if ($test->doesNotPerformAssertions()) {
            return;
        }

        $test->addToAssertionCount($count);
        self::$creditedAssertionCount += $count;

        $key                        = spl_object_id($test);
        self::$appliedCredits[$key] = (isset(self::$appliedCredits[$key]) ? self::$appliedCredits[$key] : 0) + $count;
    }

    /**
     * Delays the program execution for the given number of seconds. It works asynchronously when possible, otherwise
     * it works the same as PHP function sleep().
     */
    public static function sleep(int $seconds): void
    {
        if (Helper::isCoroutineFriendly()) {
            Attribution::suspended();
            Coroutine::sleep($seconds);
            Attribution::resumed();
        } else {
            \sleep($seconds);
        }
    }

    /**
     * The assertion count the JUnit XML report should carry for the given test -- what a blocking
     * run would have reported for it -- or null when counit has nothing better than the number
     * PHPUnit already wrote.
     *
     * PHPUnit's number for a test (`emitted`) is, under counit, the sum of three things: the
     * up-front credit, the assertions counted directly on the test object before it was reported,
     * and the static-counter window PHPUnit harvested into it -- a window that, after a yield,
     * holds whatever other tests happened to run in it. Segment accounting knows the test's real
     * share of the static counter (Attribution::ownFor()), so the window is replaced wholesale:
     *
     *     corrected = own + (emitted - credit - harvested) + late
     *
     * where the parenthesised term is what remains of PHPUnit's number once the credit and the
     * window are taken out -- the instance-counted assertions -- and `late` adds the ones counted
     * on the test object after it was reported (a mock verified after a yield, an
     * addToAssertionCount() call from a tearDown() running inside the coroutine).
     *
     * Without segment accounting (Swoole's preemptive scheduler is on), the window cannot be
     * split, so the correction is limited to removing the credit and adding the late counts.
     *
     * @return int|null
     */
    public static function correctedAssertionCountFor(int $key)
    {
        $emitted = AssertionCountListener::emittedCountFor($key);
        if ($emitted === null) {
            return null;
        }

        $credit = isset(self::$appliedCredits[$key]) ? self::$appliedCredits[$key] : 0;
        $late   = AssertionCountListener::lateCountFor($key);

        if (Attribution::$enabled && Attribution::harvestRecorded($key)) {
            $instanceOnly = max(0, $emitted - $credit - Attribution::harvestedFor($key)) + $late;

            return max(0, Attribution::ownFor($key) + $instanceOnly);
        }

        return max(0, $emitted - $credit + $late);
    }
}

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
     * Whether the most recent create() call joined its coroutine because the calling test had an
     * exception expectation registered at the first yield. TestCase::runBare() consults this to
     * skip the up-front assertion credit for such a test: its body has fully finished, so the
     * expectation verification's own assertions are counted natively, before PHPUnit reads the
     * count. create() calls are made sequentially from the main coroutine, so one flag suffices.
     *
     * @var bool
     */
    private static $lastCreateJoined = false;

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
     * @return int return 0 if not running inside a coroutine, or when the callable was joined (run
     *             to completion before returning) because something @depends on the calling test
     *             or because the calling test had an exception expectation registered at the
     *             body's first yield; otherwise, return the coroutine ID, or -1 when failed
     *             creating a new coroutine to run the tests
     */
    public static function create(callable $callable, int $count = 0): int
    {
        if (Helper::isCoroutineFriendly()) {
            self::$lastCreateJoined = false;

            $trace  = debug_backtrace();
            $caller = $trace[1]['object'] ?? null;
            $method = isset($trace[1]['function']) ? (string) $trace[1]['function'] : '';

            // A test something @depends on cannot be allowed to merely start its coroutine here:
            // PHPUnit records the test's return value and verdict when its runBare() returns and
            // resolves each dependent's input from there, before any counit seam (see
            // DependencyMap). Joining makes the idiomatic manual-approach shape -- compute into a
            // by-ref variable inside the callable, return it from the test method -- deliver the
            // real value, and a failure after a yield reach PHPUnit synchronously, with no test
            // changes. (Detection needs the create() call to sit directly in the test method; a
            // call made from a helper starts an ordinary coroutine.) No assertion credit is
            // applied on this path even when requested: the body completes before PHPUnit reads
            // the count, so the real assertions are counted -- crediting on top would both
            // inflate the count and stop a producer that performs no assertions from being
            // flagged risky, which is what makes PHPUnit skip its dependents in blocking mode.
            if ($caller instanceof TestCase && $method !== '' && DependencyMap::isProducer(get_class($caller), $method)) {
                self::createAndJoin($callable);

                return 0;
            }

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
            $joining         = false;
            $thrown          = null;
            $done            = new Coroutine\Channel(1);
            $key             = ($caller instanceof TestCase) ? spl_object_id($caller) : null;

            if ($key !== null) {
                // Normally a no-op: the run's TestListener already claimed the counter for this
                // test at startTest(). It matters for the one test whose startTest() fired before
                // that listener could be registered (it is registered from this very method, on
                // its first call) -- nothing has yielded yet at that point, so the counter's
                // whole current value belongs to this test and is claimed retroactively.
                Attribution::claimMain($key);
            }

            $id = Coroutine::create(function () use ($callable, $key, $done, &$caught, &$alreadyReturned, &$joining, &$thrown, $description): void {
                Attribution::coroutineStarted($key);

                try {
                    $callable();
                } catch (\Throwable $e) {
                    // PHPStan sees only the value $alreadyReturned holds when the closure is
                    // created; it is flipped to true (by reference) below, before a coroutine
                    // that yielded resumes and can reach this catch block.
                    if ($alreadyReturned) { // @phpstan-ignore if.alwaysFalse
                        if ($joining) { // @phpstan-ignore if.alwaysFalse
                            // The caller is waiting for this coroutine (see the join below): hand
                            // the Throwable over instead of deferring it, so it reaches PHPUnit
                            // synchronously and its native handling applies.
                            $thrown = $e;
                        } elseif (($e instanceof SkippedTest) || ($e instanceof IncompleteTest)) {
                            self::$deferredSkips[$description] = $e;
                        } else {
                            self::$deferredFailures[$description] = $e;
                        }
                    } else {
                        $caught = $e;
                    }
                } finally {
                    Attribution::coroutineFinished();
                    // Unconditional, so a join decided only after this coroutine already finished
                    // (its whole body ran without yielding) still pops instantly instead of
                    // blocking forever on an empty channel.
                    $done->push(true);
                }
            });
            $alreadyReturned = true;

            // The calling coroutine is running again (the child finished or yielded): re-claim
            // the assertion counter for whichever test it is running.
            Attribution::resumed();

            if ($caught !== null) {
                throw $caught;
            }

            // A test with a registered exception expectation cannot be allowed to merely start
            // here: PHPUnit verifies the expectation the moment the test method invocation
            // returns -- with the body still in flight it sees no Throwable and fails the test
            // with "exception ... is thrown", while the real Throwable arrives later and can only
            // be deferred (on this branch that bit the manual approach, and the automatic
            // approach's expectWarning() family: PHPUnit's converting error handler is
            // unregistered on the main coroutine before the test coroutine resumes). Joining
            // keeps PHPUnit waiting -- error handler still registered -- and puts the Throwable
            // back where its native verification expects it. The check happens here rather than
            // before the spawn on purpose: expectException() is called inside the body, so the
            // expectation only exists once the body has run to its first yield.
            if ($caller instanceof TestCase && ExceptionExpectations::isRegisteredFor($caller)) {
                self::$lastCreateJoined = true;
                $joining                = true;
                Attribution::suspended();
                $done->pop();
                Attribution::resumed();

                // Set by reference from inside the coroutine, which PHPStan cannot see.
                if ($thrown !== null) { // @phpstan-ignore notIdentical.alwaysFalse
                    throw $thrown;
                }

                return 0;
            }

            return ($id !== false) ? $id : -1; // @phpstan-ignore return.type
        }

        $callable();
        return 0;
    }

    /**
     * Runs the callable in its own coroutine and *waits* for it: returns its return value, or
     * rethrows what it threw -- never deferring the failure. Other tests' pending coroutines keep
     * running while this one is awaited (the wait itself yields to the scheduler).
     * TestCase::runBare() uses this for every automatic-approach test something @depends on, and
     * Counit::create() delegates here for manual-approach producers; a manual-approach producer
     * can also call it directly, in which case its return value can be returned from the test
     * method as-is.
     *
     * No assertion credit is applied: the body is complete before PHPUnit reads the count, so the
     * real assertions are counted -- exactly as in blocking mode.
     *
     * @return mixed the callable's return value
     */
    public static function createAndJoin(callable $callable)
    {
        if (!Helper::isCoroutineFriendly()) {
            return $callable();
        }

        // The nearest TestCase frame is the test being joined. It is not always the direct caller:
        // when create() delegates here for a manual-approach producer, the direct caller is that
        // static create() frame, and the test object sits one frame further out.
        $caller = null;
        foreach (debug_backtrace() as $frame) {
            if (isset($frame['object']) && $frame['object'] instanceof TestCase) {
                $caller = $frame['object'];

                break;
            }
        }

        if ($caller instanceof TestCase) {
            $testResult = $caller->getTestResultObject();
            if ($testResult !== null) {
                // Same bookkeeping as create(); see the comments there.
                self::$testResult = $testResult;
                AssertionCountListener::attach($testResult);
            }
        }

        $key = ($caller instanceof TestCase) ? spl_object_id($caller) : null;
        if ($key !== null) {
            Attribution::claimMain($key);
        }

        $done   = new Coroutine\Channel(1);
        $result = null;
        $thrown = null;

        Coroutine::create(function () use ($callable, $key, $done, &$result, &$thrown): void {
            Attribution::coroutineStarted($key);

            try {
                $result = $callable();
            } catch (\Throwable $e) {
                $thrown = $e;
            } finally {
                Attribution::coroutineFinished();
                $done->push(true);
            }
        });

        // The pop() below blocks the current coroutine until the joined one pushes -- Swoole
        // resumes other pending coroutines in the meantime, so only this one test runs at its
        // blocking-mode pace. The Attribution brackets keep the assertion-counter segments
        // attributed correctly across the switch.
        Attribution::resumed();
        Attribution::suspended();
        $done->pop();
        Attribution::resumed();

        if ($thrown !== null) {
            throw $thrown;
        }

        return $result;
    }

    /**
     * Whether the most recent create() call joined its coroutine because the calling test had an
     * exception expectation registered; see $lastCreateJoined.
     */
    public static function lastCreateJoined(): bool
    {
        return self::$lastCreateJoined;
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

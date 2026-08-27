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
     * The TestCase object behind each $deferredSkips entry (same key). CounitExtension replays
     * each deferred skip/incomplete into the run's TestResult through its public addError() --
     * which classifies SkippedTest/IncompleteTest Throwables natively -- once every coroutine
     * has drained, so the Skipped:/Incomplete: summary counts, the listings and the
     * --fail-on-skipped/--fail-on-incomplete exit codes (PHPUnit 9; the flags do not exist on
     * PHPUnit 8) match a blocking run exactly. Successfully replayed entries are removed from
     * $deferredSkips, leaving the script's notice as the fail-soft fallback. The object is
     * stashed at deferral time rather than looked up later: the closure is the only place that
     * still holds the caller when the verdict arrives.
     *
     * @var array<string, TestCase>
     */
    public static $deferredSkipTests = [];

    /**
     * The TestCase object behind each $deferredFailures entry (same key). CounitExtension
     * replays each deferred failure/error into the run's TestResult through its public
     * addFailure()/addError() -- blocking PHPUnit's own classification: an AssertionFailedError
     * fails the test, anything else errors it -- once every coroutine has drained, so the
     * FAILURES!/ERRORS! summary counts, the listings and the run's exit code (1/2, through the
     * `counit` script's alignment) match a blocking run exactly. Successfully replayed entries
     * are removed from $deferredFailures, leaving the script's block as the fail-soft fallback
     * (which then still forces a non-zero exit code). Stashed at deferral time, like
     * $deferredSkipTests.
     *
     * @var array<string, TestCase>
     */
    public static $deferredFailureTests = [];

    /**
     * Every deferred post-yield verdict (skip/incomplete/failure/error), whether its replay
     * later succeeds or not, for JunitXmlCorrector to write the matching
     * <skipped/>/<failure>/<error> element into the report -- the JUnit logger's own listener
     * callbacks no-op for a late verdict (its currentTestCase is null by then), so without the
     * corrector the report kept calling failed tests passed.
     *
     * @var array<int, array{test: TestCase, throwable: \Throwable}>
     */
    public static $verdictsForReport = [];

    /**
     * Per test key (spl_object_id() of its TestCase object): the up-front assertion credit
     * applied to it. The run total only needs the sum ($creditedAssertionCount); the JUnit
     * per-testcase correction needs to know which test carries which credit.
     *
     * @var array<int, int>
     */
    private static $appliedCredits = [];

    /**
     * Per test key: the hrtime(true) stamp taken when the test started (from the run's
     * TestListener; create() stamps the first test itself, whose startTest() fired before the
     * listener could be attached). Together with the coroutine-finish stamp this yields the
     * test's real wall-clock duration -- what a blocking run would have measured -- where
     * PHPUnit's own telemetry only ever sees time-to-first-yield for the automatic approach's
     * report.
     *
     * @var array<int, int>
     */
    private static $testStartTimes = [];

    /**
     * Per test key: the test's TestCase object and measured wall-clock duration in seconds,
     * recorded when its coroutine truly finishes. The maximum wins: a test may wrap several
     * coroutines (manual approach), and the last one to finish defines the test's real end.
     * Consumed by JunitXmlCorrector (the `time` attributes) and HistoryCorrector (the result
     * cache's `times` map). Approximate under concurrency -- a coroutine also waits for its
     * turn on the scheduler while others run -- but of blocking's magnitude, never the
     * 0.001-for-a-1s-test garbage the raw telemetry records.
     *
     * @var array<int, array{test: TestCase, seconds: float}>
     */
    private static $testDurations = [];

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
     * Whether the most recent create() call's coroutine ran to completion before create()
     * returned -- i.e. the body never yielded. The count PHPUnit is about to read is then
     * already final, so no assertion credit is needed (or wanted: it would suppress the native
     * "did not perform any assertions" verdict for a test that genuinely performed none). Like
     * $lastCreateJoined, a single flag suffices: create() calls are sequential on the main
     * coroutine.
     *
     * @var bool
     */
    private static $lastCreateFinished = false;

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
            // The first coroutine of the run is the point where an unregistered CounitExtension
            // starts costing correctness; warn once. See CounitExtension::warnIfUnregistered().
            CounitExtension::warnIfUnregistered();

            self::$lastCreateJoined   = false;
            self::$lastCreateFinished = false;

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

            // Resolved before the credit is applied: while --enforce-time-limit is active, or
            // when the calling test is bracketed by a global-state snapshot (@backupGlobals /
            // @backupStaticAttributes; see GlobalState), the coroutine is joined below, so the
            // body completes -- real assertions counted -- before PHPUnit reads the count. A
            // credit would inflate that count and mask the risky "did not perform any
            // assertions" verdict blocking PHPUnit gives in those runs.
            $joinForTimeLimit    = TimeLimit::enforcedForRun($caller instanceof TestCase ? $caller : null);
            // A run with a verdict-sequencing option active (--stop-on-*) joins every test as
            // well: PHPUnit decides between tests from the verdicts it has so far, and only a
            // joined test's verdict is final before that decision. The run serializes for the
            // duration; see VerdictSequencing.
            $joinForSequencing   = VerdictSequencing::activeForRun($caller instanceof TestCase ? $caller : null);
            $joinForGlobalState  = $caller instanceof TestCase && GlobalState::isBackedUp($caller);
            // Scoped to the manual approach on purpose: the automatic approach's own
            // create(parent::runBare()) call runs the whole phase inside the coroutine already --
            // correct timing, full concurrency -- and must not be joined for this.
            $joinForPostConditions = $caller instanceof TestCase
                && !($caller instanceof \Deminy\Counit\TestCase)
                && PostConditions::isCustomizedFor(get_class($caller));

            // Validation stays ahead of the spawn, so a misuse still fails before a coroutine is
            // created; the credit itself is applied only after the spawn (see below), once it is
            // known whether the body already ran to completion.
            if ($count > 0 && !$caller instanceof TestCase) {
                throw new Exception(sprintf('Method "%s" should be called directly in a test method of a %s object.', __METHOD__, TestCase::class));
            }

            $description = $caller instanceof TestCase
                ? sprintf('%s::%s', get_class($caller), $caller->getName(true))
                : sprintf('%s() call', __METHOD__);

            $caught          = null;
            $alreadyReturned = false;
            $finished        = false;
            $joining         = false;
            $thrown          = null;
            $done            = new Coroutine\Channel(1);
            $key             = ($caller instanceof TestCase) ? spl_object_id($caller) : null;

            // Swoole gives every coroutine its own output-buffer stack, so a manual-approach
            // body's echo -- running inside the coroutine spawned below -- would never reach
            // PHPUnit's output buffer, which lives on the main coroutine (see OutputCapture).
            // The output is captured inside the coroutine and replayed on this, the calling,
            // coroutine. Scoped to the manual approach on purpose: the automatic approach's own
            // create(parent::runBare()) call runs PHPUnit's whole buffering INSIDE the coroutine
            // already -- correct capture, full concurrency -- and must not be double-wrapped.
            $captureOutput    = $caller instanceof TestCase && !($caller instanceof \Deminy\Counit\TestCase);
            $capturedOutput   = '';
            $outputLevelDelta = 0;

            if ($key !== null) {
                // Normally a no-op: the run's TestListener already claimed the counter for this
                // test at startTest(). It matters for the one test whose startTest() fired before
                // that listener could be registered (it is registered from this very method, on
                // its first call) -- nothing has yielded yet at that point, so the counter's
                // whole current value belongs to this test and is claimed retroactively.
                Attribution::claimMain($key);
                // Same first-test rationale for the wall-clock stamp (a no-op for every later
                // test, whose startTest() already stamped it); see recordTestStarting().
                self::recordTestStarting($key);
            }

            $id = Coroutine::create(function () use ($callable, $caller, $key, $done, &$caught, &$alreadyReturned, &$finished, &$joining, &$thrown, $captureOutput, &$capturedOutput, &$outputLevelDelta, $description): void {
                Attribution::coroutineStarted($key);
                Diagnostics::coroutineStarted();
                $obHandle = $captureOutput ? OutputCapture::start() : null;

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
                            $uniqueKey                        = self::uniqueDeferredKey($description, self::$deferredSkips);
                            self::$deferredSkips[$uniqueKey]  = $e;
                            if ($caller instanceof TestCase) {
                                self::$deferredSkipTests[$uniqueKey] = $caller;
                                self::$verdictsForReport[]           = ['test' => $caller, 'throwable' => $e];
                            }
                            self::markAbortedAfterReport($key);
                        } else {
                            $uniqueKey                          = self::uniqueDeferredKey($description, self::$deferredFailures);
                            self::$deferredFailures[$uniqueKey] = $e;
                            if ($caller instanceof TestCase) {
                                self::$deferredFailureTests[$uniqueKey] = $caller;
                                self::$verdictsForReport[]              = ['test' => $caller, 'throwable' => $e];
                            }
                            self::markAbortedAfterReport($key);
                        }
                    } else {
                        $caught = $e;
                    }
                } finally {
                    if ($obHandle !== null) {
                        [$capturedOutput, $outputLevelDelta] = OutputCapture::stop($obHandle);

                        // The captured output is replayed inside PHPUnit's buffer by the calling
                        // coroutine -- which only works while the caller is still there: either
                        // it has not returned from create() yet, or it is waiting on the join.
                        // Otherwise PHPUnit already stopped the buffer and the output can only go
                        // where it went before this fix -- straight to the terminal, just in one
                        // batch instead of interleaved.
                        if ($alreadyReturned && !$joining) { // @phpstan-ignore booleanAnd.leftAlwaysFalse, booleanNot.alwaysTrue
                            echo $capturedOutput;
                            $capturedOutput = '';
                        }
                    }

                    $finished = true;

                    if ($key !== null) {
                        // A non-null $key implies a TestCase caller (see how $key is derived).
                        self::recordTestDuration($key, $caller);
                    }

                    Attribution::coroutineFinished();
                    Diagnostics::coroutineFinished();
                    // Unconditional, so a join decided only after this coroutine already finished
                    // (its whole body ran without yielding) still pops instantly instead of
                    // blocking forever on an empty channel.
                    $done->push(true);
                }
            });
            $alreadyReturned = true;

            // The calling coroutine is running again (the child finished or yielded): re-claim
            // the assertion counter for whichever test it is running. Remember whether the body
            // already ran to completion -- TestCase::runBare() reads this to skip the automatic
            // approach's credit for a never-yielding body, whose count is already final.
            Attribution::resumed();
            self::$lastCreateFinished = $finished;

            if ($caught !== null) {
                // The body threw before ever yielding; whatever it echoed first is already
                // captured and belongs inside PHPUnit's buffer, exactly as in blocking mode,
                // before the Throwable reaches PHPUnit's handling.
                echo $capturedOutput;
                $capturedOutput = '';

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
            // A run with --enforce-time-limit active joins EVERY create() call -- test bodies and
            // nested calls alike: PHPUnit times a limited test by wrapping runBare() in a
            // pcntl_alarm() guard, so the body must have truly finished before runBare() returns,
            // and no other coroutine may be in flight while a test's alarm is armed (the SIGALRM
            // is delivered to whichever coroutine resumes first). The run is serialized for the
            // duration; see TimeLimit.
            // A manual-approach test bracketed by a global-state snapshot is joined for the same
            // body-must-truly-finish reason: its runBare() is PHPUnit's own, running on the main
            // coroutine, so the restore fires when the test method returns -- which must be
            // after the real body, not at its first yield. The matching pre-snapshot drain lives
            // in AssertionCountListener::startTest(); see GlobalState.
            // A manual-approach test whose class customizes PHPUnit's post-condition phase (an
            // overridden assertPostConditions(), or a method carrying the postCondition
            // annotation) joins too: runBare() invokes those hooks right after the test method
            // returns, and a throwing hook must fail/error the test natively -- which it only
            // can when the body has truly finished. Again no credit is applied. See
            // PostConditions.
            // A manual-approach test with a registered output expectation
            // (expectOutputString()/expectOutputRegex(), or output already retrieved for an
            // assertion -- the public hasExpectationOnOutput() covers both, no reflection
            // needed) joins so its captured output can be replayed into PHPUnit's still-open
            // buffer below, where runBare() verifies it natively right after the test method
            // returns: match, mismatch and never-printed all report as in blocking mode.
            // Declared inside the body like an exception expectation, so it too is only
            // checkable here, after the body ran to its first yield. The automatic approach
            // needs no join for this: PHPUnit's whole buffering and verification run inside its
            // coroutine (see OutputCapture).
            $joinForOutput = $caller instanceof TestCase
                && !($caller instanceof \Deminy\Counit\TestCase)
                // Deliberately guarded although every PHPUnit release in the supported range
                // declares the method (which is why PHPStan proves the check redundant against
                // the analyzed vendor): a future or unexpected shape degrades to "no join"
                // instead of a fatal.
                && method_exists($caller, 'hasExpectationOnOutput') // @phpstan-ignore function.alreadyNarrowedType
                && $caller->hasExpectationOnOutput();

            // A manual-approach test that has registered a mock carrying a matcher
            // (->expects(...)) joins for the same body-must-truly-finish reason: its runBare()
            // is PHPUnit's own, running on the main coroutine, so verifyMockObjects() fires when
            // the test method returns -- the callable's first yield -- and that verification is
            // not read-only: a mock already satisfied at that instant passed and was then
            // STRIPPED (its invocation mocker unset), so a post-yield never()/exceeded-count
            // violation was silently allowed, while a not-yet-satisfied expectation failed
            // prematurely. Joining lets PHPUnit verify the truly finished body and classify
            // natively in both directions; a violation mid-body throws at call time into the
            // joined body, exactly as in blocking mode. Any registered double counts, matchers
            // or not: PHPUnit 8/9 verify-and-reset EVERY registered mock (the matcher gate there
            // only controls the assertion count), so even a matcher-less createMock() used as a
            // plain stub had its willReturn() configuration stripped at the first yield -- the
            // join fixes that corruption too. Scoped to the manual approach on purpose: the
            // automatic approach's own create(parent::runBare()) call runs the whole
            // verification inside the coroutine already -- correct verdicts, full concurrency --
            // and must not be joined for this. See MockExpectations.
            $joinForMocks = $caller instanceof TestCase
                && !($caller instanceof \Deminy\Counit\TestCase)
                && MockExpectations::isVerifiableFor($caller);

            // The requested credit is applied only now, after the spawn: a body that ran to
            // completion without ever yielding needs no credit -- PHPUnit is about to read the
            // test's real, final count, exactly as in blocking mode -- so a test that genuinely
            // performed no assertions is flagged risky natively, at the right moment, and one
            // that did assert is not inflated. The join paths resolved before the spawn decline
            // it as before (the joined body's real assertions are counted natively); the joins
            // decided below (exception/output expectations) keep the credit, exactly as they
            // always did -- the run total stays exact either way through the credits correction.
            // The instanceof is provably redundant to PHPStan (a positive $count already threw
            // for a non-TestCase caller before the spawn) but kept for the narrowing and as
            // documentation of the invariant.
            if ($count > 0 && $caller instanceof TestCase && !$finished // @phpstan-ignore instanceof.alwaysTrue
                && !$joinForTimeLimit && !$joinForSequencing && !$joinForGlobalState && !$joinForPostConditions) {
                self::creditAssertionCount($caller, $count);
            }

            if ($joinForTimeLimit || $joinForSequencing || $joinForGlobalState || $joinForPostConditions || $joinForOutput || $joinForMocks || ($caller instanceof TestCase && ExceptionExpectations::isRegisteredFor($caller))) {
                self::$lastCreateJoined = true;
                $joining                = true;
                Attribution::suspended();
                $done->pop();
                Attribution::resumed();

                // The joined body has fully run: replay its captured output into PHPUnit's
                // still-open buffer, and reproduce any buffer-level mismatch the body caused, so
                // PHPUnit's own stopOutputBuffering() detects and reports it natively.
                echo $capturedOutput;
                $capturedOutput = '';
                OutputCapture::replayLevelMismatch($outputLevelDelta);

                // Set by reference from inside the coroutine, which PHPStan cannot see.
                if ($thrown !== null) { // @phpstan-ignore notIdentical.alwaysFalse
                    throw $thrown;
                }

                return 0;
            }

            // The body ran to completion without ever yielding: its output was captured but not
            // yet emitted, and the caller is still inside PHPUnit's output buffer -- exactly
            // where blocking PHPUnit puts it.
            if ($capturedOutput !== '') {
                echo $capturedOutput;
                $capturedOutput = '';
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

        // See create(): the same warn-once, for a run whose first coroutine is a joined one.
        CounitExtension::warnIfUnregistered();

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

        // Same manual-approach output capture as in create(): the automatic approach's joined
        // runBare() wrapper runs PHPUnit's whole buffering inside the coroutine already and must
        // not be double-wrapped.
        $captureOutput    = $caller instanceof TestCase && !($caller instanceof \Deminy\Counit\TestCase);
        $capturedOutput   = '';
        $outputLevelDelta = 0;

        Coroutine::create(function () use ($callable, $key, $done, &$result, &$thrown, $captureOutput, &$capturedOutput, &$outputLevelDelta): void {
            Attribution::coroutineStarted($key);
            Diagnostics::coroutineStarted();
            $obHandle = $captureOutput ? OutputCapture::start() : null;

            try {
                $result = $callable();
            } catch (\Throwable $e) {
                $thrown = $e;
            } finally {
                if ($obHandle !== null) {
                    [$capturedOutput, $outputLevelDelta] = OutputCapture::stop($obHandle);
                }

                Attribution::coroutineFinished();
                Diagnostics::coroutineFinished();
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

        // Replay the body's output inside PHPUnit's still-open output buffer, and reproduce any
        // buffer-level mismatch the body caused; see create() for the whole story.
        echo $capturedOutput;
        OutputCapture::replayLevelMismatch($outputLevelDelta);

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
     * Whether the most recent create() call's coroutine finished before create() returned; see
     * $lastCreateFinished.
     */
    public static function lastCreateFinished(): bool
    {
        return self::$lastCreateFinished;
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

    /**
     * Returns $description, made unique against the given deferred map. The description is built
     * from the test's class, method and data-set names, which is not unique across a whole run
     * (a manual-approach test wrapping several Counit::create() calls can defer more than one
     * verdict), and a second deferred verdict used to silently overwrite the first -- one report
     * simply vanished.
     *
     * @param array<string, \Throwable> $map
     *
     * @internal this method is not covered by the backward compatibility promise for counit
     */
    public static function uniqueDeferredKey(string $description, array $map): string
    {
        $uniqueKey = $description;
        for ($i = 2; isset($map[$uniqueKey]); $i++) {
            $uniqueKey = sprintf('%s (%d)', $description, $i);
        }

        return $uniqueKey;
    }

    /**
     * Stamps the start of a test's wall-clock window, unless one is already recorded for it.
     * Called from AssertionCountListener::startTest() for every test the listener sees, and
     * from create() for the first test of the run (whose startTest() fired before the listener
     * could be attached -- nothing has yielded at that point, so the create() call is still at
     * the test's start).
     *
     * @internal this method is not covered by the backward compatibility promise for counit
     */
    public static function recordTestStarting(int $key): void
    {
        if (!isset(self::$testStartTimes[$key])) {
            self::$testStartTimes[$key] = (int) hrtime(true);
        }
    }

    /**
     * The test's measured wall-clock duration in seconds, or null when none was recorded.
     *
     * @internal this method is not covered by the backward compatibility promise for counit
     */
    public static function durationForKey(int $key): ?float
    {
        return isset(self::$testDurations[$key]) ? self::$testDurations[$key]['seconds'] : null;
    }

    /**
     * Every measured duration, keyed by test key; see $testDurations.
     *
     * @return array<int, array{test: TestCase, seconds: float}>
     *
     * @internal this method is not covered by the backward compatibility promise for counit
     */
    public static function measuredDurations(): array
    {
        return self::$testDurations;
    }

    /**
     * A test whose body failed, skipped or went incomplete only after PHPUnit had already
     * reported it (see $deferredFailures/$deferredSkips). PHPUnit 8/9 exempt every
     * non-passing test from the "did not perform any assertions" check, so the deferred pass
     * must exempt them too; see UselessTests.
     *
     * @param int|null $key
     */
    private static function markAbortedAfterReport($key): void
    {
        if ($key !== null) {
            UselessTests::markAborted($key);
        }
    }

    /**
     * Records the test's duration as of now; see $testDurations. The maximum wins.
     */
    private static function recordTestDuration(int $key, TestCase $test): void
    {
        if (!isset(self::$testStartTimes[$key])) {
            return;
        }

        $seconds = ((int) hrtime(true) - self::$testStartTimes[$key]) / 1000000000;

        if (!isset(self::$testDurations[$key]) || self::$testDurations[$key]['seconds'] < $seconds) {
            self::$testDurations[$key] = ['test' => $test, 'seconds' => $seconds];
        }
    }
}

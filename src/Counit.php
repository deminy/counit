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
     * Sum of all assertion counts actually credited to tests via addToAssertionCount() in create():
     * the $count values applied for manual-approach tests, plus the single credit
     * TestCase::invokeTestMethod() requests for every automatic-approach test. (A requested credit is
     * declined -- and therefore not ledgered here -- for a test that declares it performs no
     * assertions; see creditCaller().) These credits stand in for assertions that will only run later
     * inside a coroutine -- but those assertions ALSO increment PHPUnit's static assertion counter
     * when they eventually run, so they either get harvested into whatever test happens to be current
     * at that moment (double-counting them) or, after the last test, never get harvested at all.
     * CounitExtension uses this ledger together with the counter residue left after draining all
     * coroutines to correct the run's reported assertion total to exactly what a blocking (non-Swoole)
     * run would have reported.
     */
    public static int $creditedAssertionCount = 0;

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
    public static array $deferredFailures = [];

    /**
     * Like $deferredFailures, but for a markTestSkipped()/markTestIncomplete() call that happened
     * only after the test's coroutine had already yielded: PHPUnit reported the test as passed at
     * that first yield, and its status cannot be changed afterwards. Unlike a real late failure,
     * this must not fail the run -- blocking PHPUnit exits 0 for skipped/incomplete tests -- so
     * the `counit` script only prints these as a notice, without touching the exit code.
     *
     * @var array<string, \Throwable>
     */
    public static array $deferredSkips = [];

    /**
     * Cleanup callbacks registered via defer(), keyed by the ID of the coroutine they were
     * registered in (-1 when not inside a coroutine, e.g. in blocking mode). Each stack is drained
     * -- in reverse registration order -- by create(), right after the wrapped callable finishes.
     *
     * @var array<int, list<callable>>
     */
    private static array $deferred = [];

    /**
     * Per test (keyed by the test's event ID): the assertion count PHPUnit reported for it in its
     * `Test\Finished` event -- i.e. the number that entered the run's reported total. Populated by
     * the subscriber CounitExtension registers, and only under Swoole.
     *
     * @var array<string, int>
     */
    private static array $emittedAssertionCounts = [];

    /**
     * Per test (keyed the same way): the test's final assertion count, read inside its coroutine
     * once everything that can still count assertions on the test object -- the body, cleanup
     * hooks, deferred callbacks -- has run. Where this exceeds the emitted count, the difference
     * was counted directly on the test object *after* PHPUnit had already reported the test (an
     * addToAssertionCount() call made after a sleep/IO yield, e.g. from the body's tail or from a
     * relocated tearDown()). Such counts bypass PHPUnit's static assertion counter, so the
     * end-of-run residue correction cannot see them; CounitExtension adds the difference back.
     *
     * @var array<string, int>
     */
    private static array $finalAssertionCounts = [];

    /**
     * Per test (keyed the same way): the assertion credit actually applied to it by
     * creditCaller(). Used by JunitXmlCorrector to subtract the credit from the per-testcase
     * `assertions` attributes in the JUnit XML report, which -- unlike the run summary -- has no
     * end-of-run total correction of its own.
     *
     * @var array<string, int>
     */
    private static array $appliedCredits = [];

    /**
     * To run test cases asynchronously when running unit tests using counit (and with the Swoole extension enabled).
     * If the Swoole extension is not enabled, or counit is not in use, the test cases will be executed in the same way
     * as under PHPUnit.
     *
     * @param int $count an optional parameter to suppress warning message "This test did not perform any assertions",
     *                   and to make the counters match. The credit is a request: it is declined for a test that
     *                   declares -- through #[DoesNotPerformAssertions] or expectNotToPerformAssertions() -- that it
     *                   performs no assertions, since PHPUnit would otherwise report the credited test as risky --
     *                   and it is not applied at all when the coroutine is joined for any reason (see the join
     *                   condition below), since PHPUnit then reads the real, final count.
     * @param callable|null $onJoin counit-internal: invoked -- on the calling coroutine, before the wrapped callable
     *                              can resume -- when the coroutine is about to be joined for any of the reasons
     *                              listed at the join condition below (an exception or output expectation, a
     *                              global-state backup, a customized post-condition phase, or a run-level switch).
     *                              TestCase::invokeTestMethod() uses it to hand the after-test hooks back to
     *                              PHPUnit for that test.
     * @return int return 0 if not running inside a coroutine, or when the coroutine was joined (run to completion
     *             before returning) for any of the reasons listed at the join condition below; otherwise, return
     *             the coroutine ID, or -1 when failed creating a new coroutine to run the tests
     */
    public static function create(callable $callable, int $count = 0, ?callable $onJoin = null): int
    {
        if (Helper::isCoroutineFriendly()) {
            $trace  = debug_backtrace();
            $caller = $trace[1]['object'] ?? null;

            // Validation stays ahead of the spawn, so a misuse still fails before a coroutine is created.
            if ($count > 0 && !$caller instanceof TestCase) {
                throw new Exception(sprintf('Method "%s" should be called directly in a test method of a %s object.', __METHOD__, TestCase::class));
            }

            $description = $caller instanceof TestCase
                ? sprintf('%s::%s', $caller::class, $caller->nameWithDataSet())
                : sprintf('%s() call', __METHOD__);

            $caught           = null;
            $alreadyReturned  = false;
            $joining          = false;
            $thrown           = null;
            $capturedOutput   = '';
            $outputLevelDelta = 0;
            $done             = new Coroutine\Channel(1);
            $testId           = $caller instanceof TestCase ? $caller->valueObjectForEvents()->id() : null;

            $id = Coroutine::create(function () use ($callable, $caller, $testId, $done, &$caught, &$alreadyReturned, &$joining, &$thrown, &$capturedOutput, &$outputLevelDelta, $description): void {
                Attribution::coroutineStarted($testId);
                $obHandle = OutputCapture::start();

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
                    try {
                        self::runDeferred(self::currentCoroutineId());
                    } catch (\Throwable $e) {
                        // A failing cleanup must not mask a failing body: it is only surfaced as
                        // the test's own error when the body succeeded synchronously; after a
                        // yield it is queued under its own key, so both failures get reported.
                        if ($alreadyReturned) { // @phpstan-ignore if.alwaysFalse
                            self::$deferredFailures[$description . ' (deferred cleanup)'] = $e;
                        } elseif ($caught === null) {
                            $caught = $e;
                        }
                    }

                    // Recorded last, once everything that can still count assertions on the test
                    // object -- the body, cleanup hooks, deferred callbacks -- has run; see
                    // $finalAssertionCounts.
                    if ($caller instanceof TestCase) {
                        self::recordFinalAssertionCount($caller);
                    }

                    [$capturedOutput, $outputLevelDelta] = OutputCapture::stop($obHandle);

                    // Swoole gives every coroutine its own output-buffer stack, so PHPUnit's
                    // buffer -- opened on the runner coroutine at the top of runBare() -- is
                    // invisible here and the body's output would go straight to STDOUT. It is
                    // captured above instead and handed back to the calling coroutine, which
                    // replays it inside PHPUnit's buffer. That only works while the caller is
                    // still there: either it has not returned from create() yet, or it is
                    // waiting on the join. Otherwise PHPUnit already stopped the buffer and the
                    // output can only go where it went before this fix -- straight to the
                    // terminal, just in one batch instead of interleaved.
                    if ($alreadyReturned && !$joining) { // @phpstan-ignore booleanAnd.leftAlwaysFalse, booleanNot.alwaysTrue
                        echo $capturedOutput;
                        $capturedOutput = '';
                    }

                    Attribution::coroutineFinished();
                    $done->push(true);
                }
            });
            $alreadyReturned = true;

            // The calling coroutine is running again (the child finished or yielded): re-claim
            // the assertion counter for whichever test it is running.
            Attribution::resumed();

            if ($caught !== null) {
                // The body threw before ever yielding; whatever it echoed first is already
                // captured and belongs inside PHPUnit's buffer, exactly as in blocking mode,
                // before the Throwable reaches PHPUnit's handling.
                echo $capturedOutput;
                $capturedOutput = '';

                throw $caught;
            }

            // The credit is applied only now, after the coroutine spawned: the test body has run up
            // to its first yield (or to completion), so the does-not-perform-assertions declaration
            // is fully resolved at this point -- whether made through the attribute (read by
            // PHPUnit's runBare() before the test method is invoked), a call in setUp(), or an
            // expectNotToPerformAssertions() call at the top of the test body itself. It also means
            // a callable that threw synchronously above is never credited, which matches blocking
            // mode: PHPUnit does not count assertions a test never got to perform.
            // A test with a registered exception expectation cannot be allowed to merely start
            // here: PHPUnit verifies the expectation in runTest(), right after invokeTestMethod()
            // returns -- so with the body still in flight it sees no Throwable and fails the test
            // with "exception ... is thrown", while the real Throwable arrives later and can only
            // be deferred. Joining the coroutine puts the Throwable back where PHPUnit expects it.
            // The check happens here rather than before the spawn on purpose: expectException()
            // is called inside the body, so the expectation only exists once the body has run to
            // its first yield. No assertion credit is applied on this path -- the expectation
            // verification counts its own assertions natively, before PHPUnit reads the count.
            // A run with --enforce-time-limit active joins EVERY create() call -- test bodies and
            // nested calls alike: PHPUnit times a limited test by wrapping runBare() in a
            // pcntl_alarm() guard, so the body must have truly finished before runBare() returns,
            // and no other coroutine may be in flight while a test's alarm is armed (the SIGALRM
            // is delivered to whichever coroutine resumes first). The run is serialized for the
            // duration; failures land synchronously in PHPUnit's native handling, exactly as in
            // blocking mode, and no assertion credit is needed -- PHPUnit reads the real, final
            // count. See TimeLimit.
            // A manual-approach test PHPUnit brackets with a global-state snapshot
            // (#[BackupGlobals], #[BackupStaticProperties], the equivalent configuration, or
            // #[WithEnvironmentVariable]) is joined for the same body-must-truly-finish reason:
            // the restore fires when runBare() returns and must follow the real body. The
            // matching pre-snapshot drain lives in CounitExtension; see GlobalState. As on the
            // other join paths no assertion credit is applied (this branch returns before
            // creditCaller()) -- the joined body's real assertions are counted natively before
            // PHPUnit reads them, so a backed-up test performing no assertions stays risky,
            // exactly as in blocking mode.
            // A manual-approach test whose class customizes PHPUnit's post-condition phase (an
            // overridden assertPostConditions(), or a #[PostCondition] method) joins for the
            // same reason: runBare() invokes those hooks right after runTest() returns, and a
            // throwing hook must fail/error the test natively -- which it only can when the body
            // has truly finished. Again no credit is applied. See PostConditions.
            // A test with a registered output expectation (expectOutputString()/
            // expectOutputRegex(), or output already retrieved for an assertion -- the public
            // expectsOutput() covers both, no reflection needed) joins so its captured output
            // can be replayed into PHPUnit's still-open buffer below, where runBare() verifies
            // it natively right after the body: match, mismatch and never-printed all report as
            // in blocking mode. Declared inside the body like an exception expectation, so it
            // too is only checkable here. And while --disallow-test-output is active, EVERY
            // create() call joins: the unexpected-output risky check reads the test's output
            // right after runBare(), so only a joined-and-replayed body can satisfy it. The run
            // serializes for the duration; see OutputExpectations.
            if (TimeLimit::enforcedForRun()
                || OutputExpectations::disallowedForRun()
                || ($caller instanceof TestCase
                    && (ExceptionExpectations::isRegisteredFor($caller)
                        || $caller->expectsOutput()
                        || GlobalState::isBackedUp($caller::class, $caller->name())
                        || PostConditions::isCustomizedFor($caller::class)))) {
                if ($onJoin !== null) {
                    $onJoin();
                }

                $joining = true;
                Attribution::suspended();
                $done->pop();
                Attribution::resumed();

                // The joined body has fully run: replay its captured output into PHPUnit's
                // still-open buffer -- the whole point of the output-expectation join -- and
                // reproduce any buffer-level mismatch the body caused, so PHPUnit's own stop()
                // detects and reports it natively.
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

            self::creditCaller($caller, $count);

            return ($id !== false) ? $id : -1; // @phpstan-ignore return.type
        }

        // Blocking mode: run the callable directly; cleanups it registered via defer() run right
        // after it, preserving the same ordering as coroutine mode. Nested create() calls share
        // the same (non-)coroutine ID here, so the caller's pending cleanups are set aside first.
        $cid            = self::currentCoroutineId();
        $parentDeferred = self::$deferred[$cid] ?? [];
        unset(self::$deferred[$cid]);
        $bodyThrew = false;

        try {
            $callable();
        } catch (\Throwable $e) {
            $bodyThrew = true;
            throw $e;
        } finally {
            try {
                self::runDeferred($cid);
            } catch (\Throwable $e) {
                // The body's Throwable takes precedence, the same way PHPUnit itself prioritizes
                // a test failure over a subsequent tearDown() error.
                if (!$bodyThrew) {
                    throw $e;
                }
            } finally {
                if ($parentDeferred !== []) {
                    self::$deferred[$cid] = $parentDeferred;
                }
            }
        }

        return 0;
    }

    /**
     * Registers a cleanup callback to run right after the current test body finishes -- pass or
     * fail -- instead of in tearDown(), which PHPUnit invokes as soon as the test body first
     * yields on a sleep/IO call (possibly while the body is still running). Deferred callbacks run
     * in reverse registration order, inside the coroutine (or at the same point in blocking mode).
     * They only apply within a create() call: for the automatic approach that is the whole test
     * method; for the manual approach, call defer() inside the callable passed to create(). A
     * callback registered outside any create() call is never executed.
     *
     * A Throwable thrown by a deferred callback after the test body has yielded is reported at the
     * end of the run and forces exit code 1, like any other deferred failure. When the test body
     * itself threw, the body's Throwable takes precedence. See also TestCase::tearDownCoroutine()
     * for the automatic approach's structured equivalent.
     */
    public static function defer(callable $cleanup): void
    {
        self::$deferred[self::currentCoroutineId()][] = $cleanup;
    }

    /**
     * Remembers the assertion count PHPUnit just reported for a test; called by the Test\Finished
     * subscriber CounitExtension registers under Swoole.
     */
    public static function recordEmittedAssertionCount(string $testId, int $count): void
    {
        self::$emittedAssertionCounts[$testId] = $count;
    }

    /**
     * The assertion count a test's JUnit XML `assertions` attribute should carry, or null when
     * the attribute must be left exactly as PHPUnit wrote it.
     *
     * With segment accounting available (see Attribution), the number is what the test itself
     * performed: the static-counter segments attributed to it at counit's observation points,
     * plus the assertions counted directly on the test object (the emitted count minus the
     * up-front credit and the harvested counter window, plus the post-report instance counts) --
     * the credit never enters either term. Exact whenever every yield of the test is observable
     * (sleep()/usleep() in a namespaced test class, Counit::sleep()); an unobserved yield (hooked
     * network IO, a fully-qualified \sleep() call, a test class in the global namespace) leaves
     * an undercount, never an overcount. When segment accounting is unavailable (Swoole's
     * preemptive scheduler), this falls back to `emitted - credit + late`, which is exact for
     * tests that never yield.
     *
     * Null is returned when no Test\Finished event was observed for the test (it was skipped or
     * failed before it was prepared -- PHPUnit only emits the event for prepared tests), or --
     * under segment accounting -- when counit never ran a coroutine for it (a process-isolated
     * test, or a test that never went through create()): in both cases nothing of the test's own
     * ran after PHPUnit counted it, so PHPUnit's number is already the right one.
     */
    public static function correctedAssertionCountFor(string $testId): ?int
    {
        $emitted = self::$emittedAssertionCounts[$testId] ?? null;
        if ($emitted === null) {
            return null;
        }

        $credit = self::$appliedCredits[$testId] ?? 0;
        $late   = max(0, (self::$finalAssertionCounts[$testId] ?? 0) - $emitted);

        if (Attribution::$enabled) {
            if (!Attribution::observedCoroutineFor($testId)) {
                return null;
            }

            $instanceOnly = max(0, $emitted - $credit - Attribution::harvestedFor($testId)) + $late;

            return max(0, Attribution::ownFor($testId) + $instanceOnly);
        }

        return max(0, $emitted - $credit + $late);
    }

    /**
     * The assertions that were counted directly on a test object after PHPUnit had already
     * reported that test -- and therefore never entered the run's reported total. Only meaningful
     * once every test coroutine has finished; see CounitExtension.
     */
    public static function lateAssertionCount(): int
    {
        $late = 0;

        foreach (self::$finalAssertionCounts as $testId => $finalCount) {
            $emitted = self::$emittedAssertionCounts[$testId] ?? null;
            if ($emitted === null) {
                continue;
            }

            // A final count at or below the emitted one only means the coroutine finished before
            // PHPUnit reported the test -- e.g. a body that never yielded, whose harvested window
            // and credit were applied afterwards. Nothing ran late then.
            if ($finalCount > $emitted) {
                $late += $finalCount - $emitted;
            }
        }

        return $late;
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
     * Runs the callable in its own coroutine and waits for it to finish, returning its return
     * value and rethrowing whatever it threw -- i.e. the caller observes exactly what a blocking
     * run would, while the main coroutine still yields for the duration, so every test coroutine
     * already in flight keeps making progress.
     *
     * This is what a test other tests #[Depends] on needs: PHPUnit records a producer's return
     * value and verdict into PassedTests at the end of runBare(), which under create() happens at
     * the body's first yield -- too early for either to be real. Joining here moves both back to
     * where PHPUnit expects them, at the cost of that one test's own concurrency (its dependents
     * could not have overlapped with it anyway). No assertion credit is involved: the body has
     * fully run by the time PHPUnit reads the test's count, exactly as in blocking mode.
     * TestCase::invokeTestMethod() routes every automatic-approach test through this join while
     * --enforce-time-limit is active, for the same body-must-truly-finish reason; see TimeLimit.
     *
     * @throws \Throwable whatever the callable threw
     */
    public static function createAndJoin(callable $callable): mixed
    {
        if (!Helper::isCoroutineFriendly()) {
            return $callable();
        }

        $trace  = debug_backtrace();
        $caller = $trace[1]['object'] ?? null;
        $testId = $caller instanceof TestCase ? $caller->valueObjectForEvents()->id() : null;

        $done             = new Coroutine\Channel(1);
        $result           = null;
        $thrown           = null;
        $capturedOutput   = '';
        $outputLevelDelta = 0;

        Coroutine::create(function () use ($callable, $caller, $testId, $done, &$result, &$thrown, &$capturedOutput, &$outputLevelDelta): void {
            Attribution::coroutineStarted($testId);
            $obHandle = OutputCapture::start();

            try {
                $result = $callable();
            } catch (\Throwable $e) {
                $thrown = $e;
            } finally {
                try {
                    self::runDeferred(self::currentCoroutineId());
                } catch (\Throwable $e) {
                    // A failing cleanup must not mask a failing body.
                    if ($thrown === null) {
                        $thrown = $e;
                    }
                }

                if ($caller instanceof TestCase) {
                    self::recordFinalAssertionCount($caller);
                }

                [$capturedOutput, $outputLevelDelta] = OutputCapture::stop($obHandle);

                Attribution::coroutineFinished();
                $done->push(true);
            }
        });

        // Back on the calling coroutine: it is running again because the child finished or
        // yielded. Re-claim the assertion counter, then hand it over for the wait.
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
     * Credits the calling test with $count assertions up front, unless it declared -- through
     * #[DoesNotPerformAssertions] or expectNotToPerformAssertions() -- that it performs none, in
     * which case PHPUnit would report the credited test as risky ("This test is not expected to
     * perform assertions but performed 1 assertion"). Kept as a separate method on purpose: inlining
     * the check into create() trips PHPStan level 9 (the caller was already type-checked before the
     * coroutine spawned, making an inline instanceof provably redundant), while a fresh scope keeps
     * the guard self-contained.
     */
    private static function creditCaller(?object $caller, int $count): void
    {
        if ($count > 0 && $caller instanceof TestCase && !$caller->doesNotPerformAssertions()) {
            $caller->addToAssertionCount($count);
            self::$creditedAssertionCount += $count;

            $testId                        = $caller->valueObjectForEvents()->id();
            self::$appliedCredits[$testId] = (self::$appliedCredits[$testId] ?? 0) + $count;
        }
    }

    /**
     * Records a test's assertion count as read inside its coroutine after everything has run. The
     * maximum wins: a test may wrap several coroutines (manual approach), and the instance counter
     * only ever grows within a test's lifecycle, so the highest reading is the latest one.
     */
    private static function recordFinalAssertionCount(TestCase $test): void
    {
        $testId = $test->valueObjectForEvents()->id();
        $count  = $test->numberOfAssertionsPerformed();

        if ($count > (self::$finalAssertionCounts[$testId] ?? -1)) {
            self::$finalAssertionCounts[$testId] = $count;
        }
    }

    /**
     * The ID of the coroutine the current code runs in, or -1 when not inside a coroutine (which
     * includes blocking mode without the Swoole extension loaded at all).
     */
    private static function currentCoroutineId(): int
    {
        if (!extension_loaded('swoole')) {
            return -1;
        }

        // The is_int() check exists for PHPStan only: swoole/ide-helper types getCid() as mixed.
        $cid = Coroutine::getCid();

        return is_int($cid) ? $cid : -1;
    }

    /**
     * Runs (and forgets) the cleanup callbacks registered under the given coroutine ID, in reverse
     * registration order. Every callback runs even when an earlier one throws -- skipping the rest
     * would leak the resources they were meant to release -- and the first Throwable is rethrown
     * afterwards so the failure still surfaces.
     */
    private static function runDeferred(int $cid): void
    {
        $stack = self::$deferred[$cid] ?? [];
        unset(self::$deferred[$cid]);

        $firstFailure = null;

        foreach (array_reverse($stack) as $cleanup) {
            try {
                $cleanup();
            } catch (\Throwable $e) {
                if ($firstFailure === null) {
                    $firstFailure = $e;
                }
            }
        }

        if ($firstFailure !== null) {
            throw $firstFailure;
        }
    }
}

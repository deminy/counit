<?php

declare(strict_types=1);

namespace Deminy\Counit;

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
     * To run test cases asynchronously when running unit tests using counit (and with the Swoole extension enabled).
     * If the Swoole extension is not enabled, or counit is not in use, the test cases will be executed in the same way
     * as under PHPUnit.
     *
     * @param int $count an optional parameter to suppress warning message "This test did not perform any assertions",
     *                   and to make the counters match. The credit is a request: it is declined for a test that
     *                   declares -- through #[DoesNotPerformAssertions] or expectNotToPerformAssertions() -- that it
     *                   performs no assertions, since PHPUnit would otherwise report the credited test as risky.
     * @return int return 0 if not running inside a coroutine; otherwise, return the coroutine ID, or -1 when failed
     *             creating a new coroutine to run the tests
     */
    public static function create(callable $callable, int $count = 0): int
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

            $caught          = null;
            $alreadyReturned = false;

            $id = Coroutine::create(function () use ($callable, &$caught, &$alreadyReturned, $description): void {
                try {
                    $callable();
                } catch (\Throwable $e) {
                    // PHPStan sees only the value $alreadyReturned holds when the closure is
                    // created; it is flipped to true (by reference) below, before a coroutine
                    // that yielded resumes and can reach this catch block.
                    if ($alreadyReturned) { // @phpstan-ignore if.alwaysFalse
                        self::$deferredFailures[$description] = $e;
                    } else {
                        $caught = $e;
                    }
                }
            });
            $alreadyReturned = true;

            if ($caught !== null) {
                throw $caught;
            }

            // The credit is applied only now, after the coroutine spawned: the test body has run up
            // to its first yield (or to completion), so the does-not-perform-assertions declaration
            // is fully resolved at this point -- whether made through the attribute (read by
            // PHPUnit's runBare() before the test method is invoked), a call in setUp(), or an
            // expectNotToPerformAssertions() call at the top of the test body itself. It also means
            // a callable that threw synchronously above is never credited, which matches blocking
            // mode: PHPUnit does not count assertions a test never got to perform.
            self::creditCaller($caller, $count);

            return ($id !== false) ? $id : -1; // @phpstan-ignore return.type
        }

        $callable();
        return 0;
    }

    /**
     * Delays the program execution for the given number of seconds. It works asynchronously when possible, otherwise
     * it works the same as PHP function sleep().
     */
    public static function sleep(int $seconds): void
    {
        if (Helper::isCoroutineFriendly()) {
            Coroutine::sleep($seconds);
        } else {
            \sleep($seconds);
        }
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
        }
    }
}

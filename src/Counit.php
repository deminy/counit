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
     * Sum of all assertion counts credited to tests up front via creditAssertionCount(): the
     * explicit $count values passed by manual-approach tests, plus the single credit
     * TestCase::runBare() records for every automatic-approach test. These credits stand in for
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
     * To run test cases asynchronously when running unit tests using counit (and with the Swoole extension enabled).
     * If the Swoole extension is not enabled, or counit is not in use, the test cases will be executed in the same way
     * as under PHPUnit.
     *
     * @param int $count an optional parameter to suppress warning message "This test did not perform any assertions",
     *                   and to make the counters match
     * @return int return 0 if not running inside a coroutine; otherwise, return the coroutine ID, or -1 when failed
     *             creating a new coroutine to run the tests
     */
    public static function create(callable $callable, int $count = 0): int
    {
        if (Helper::isCoroutineFriendly()) {
            $trace  = debug_backtrace();
            $caller = $trace[1]['object'] ?? null;

            if ($caller instanceof TestCase) {
                self::$testResult = $caller->getTestResultObject();
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
        $test->addToAssertionCount($count);
        self::$creditedAssertionCount += $count;
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
}

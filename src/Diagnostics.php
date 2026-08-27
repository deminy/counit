<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\NoTestCaseObjectOnCallStackException;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use PHPUnit\Metadata\Parser\Registry as MetadataRegistry;
use PHPUnit\Runner\ErrorHandler;
use Swoole\Coroutine;

use const E_USER_ERROR;

/**
 * Conversion of diagnostics (deprecations/warnings/notices) triggered after a test's first yield.
 *
 * PHPUnit turns a diagnostic into one of its own issue events from a converting error handler
 * that TestRunner::run() enables right before runBare() and disables right after it returns --
 * under counit, the test body's first yield. Everything a non-joined test triggers from there on
 * reached PHP's default handler instead: printed raw, never counted in
 * Deprecations:/Warnings:/Notices:, invisible to --display-*, to the baseline, to
 * #[IgnoreDeprecations] and to --fail-on-deprecation and friends.
 *
 * The fix is one counit-owned error handler that covers exactly the windows PHPUnit's own cannot,
 * and it does not decide anything itself: what it receives is handed to PHPUnit's own handler
 * object. ErrorHandler::__invoke() is what PHPUnit calls in blocking mode as well, so every
 * downstream decision stays PHPUnit's own and exact -- the test the issue is attributed to
 * (resolved from the call stack, which inside a coroutine is that test's own stack),
 * @-suppression, the baseline, #[IgnoreDeprecations], the deprecation filters, the issue-trigger
 * classification, and the events that feed the counts, the listings and the exit code. Nothing is
 * deferred, nothing is re-implemented, and a diagnostic PHPUnit's own handler already saw is
 * never seen by this one.
 *
 * WHEN the handler is registered is the whole design, and it follows from one invariant: a
 * suspended test coroutine can only resume while the MAIN coroutine is inside a yield, and counit
 * owns every yield the main coroutine performs (Attribution::suspended()/resumed() bracket all of
 * them -- the sleep()/usleep() shims, Counit::sleep(), the join waits, the global-state barrier --
 * plus the end-of-run drain loop in CounitExtension). Registering there, and only there, means:
 *
 *  - post-yield code always runs with counit's handler on top, whichever coroutine it belongs to;
 *  - PHPUnit's own machinery -- ErrorHandler::enable(), the per-test handler-stack snapshot and
 *    its restore, ErrorHandler::disable() -- always runs with the stack exactly as it would be
 *    without counit, because the main coroutine is running, not suspended, at those points.
 *
 * The second point is not a nicety. PHPUnit 12.5's ErrorHandler::enable() bails out when ANY
 * handler is already registered
 *
 *     $oldErrorHandler = set_error_handler($this);
 *     if ($oldErrorHandler !== null) { restore_error_handler(); return; }
 *
 * so a counit handler that is permanently on the stack silently disables PHPUnit's converting
 * handler for the whole run on that line (verified: a deprecation triggered by a data provider
 * vanishes from the summary). And a handler pushed for the duration of each test coroutine's
 * slices cannot be popped reliably: a coroutine resumed at a point counit cannot observe (hooked
 * network/DB IO, a fully-qualified \sleep(), a class in the global namespace) is left holding it,
 * and the pop then takes PHPUnit's handler off the stack instead -- verified too: counit's own
 * curl/MySQL/Redis sample suites went from clean to twelve risky handler verdicts.
 *
 * A test marked #[WithoutErrorHandler] is never converted, mirroring
 * TestRunner::shouldErrorHandlerBeUsed(); its post-yield diagnostics are handed back to PHP,
 * which also shields it from whichever converting handler happens to be registered for the test
 * the runner is currently on.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class Diagnostics
{
    /**
     * The levels handed to PHPUnit's handler. For everything but E_USER_ERROR that records one of
     * PHPUnit's issue events; for E_USER_ERROR the handler THROWS ("E_USER_ERROR was triggered"),
     * exactly as in blocking mode -- at the trigger site inside the coroutine, so the body aborts
     * into counit's deferred-failure machinery, whose replayed Test\Errored (see LateFailures)
     * then errors the test with blocking's exact summary and exit code 2. Without this, a
     * post-yield E_USER_ERROR reached PHP's default handler and KILLED the whole run with exit
     * code 255.
     */
    private const int LEVELS = \E_DEPRECATED | \E_USER_DEPRECATED | \E_NOTICE | \E_USER_NOTICE | \E_WARNING | \E_USER_WARNING | \E_USER_ERROR;

    private const int OFF = 0;

    private const int CONVERT = 1;

    private const int PASS_THROUGH = 2;

    public static bool $enabled = false;

    /**
     * Coroutine ID => what counit's handler does for diagnostics raised in that coroutine. Only
     * coroutines counit spawned for a test body have an entry; everything else (the main
     * coroutine above all) is OFF.
     *
     * @var array<int, self::OFF|self::CONVERT|self::PASS_THROUGH>
     */
    private static array $mode = [];

    /** The coroutine that pushed counit's handler, while it is on the shared stack. */
    private static ?int $pushedBy = null;

    /** The registered handler; see handler(). */
    private static ?\Closure $handler = null;

    public static function initialize(): void
    {
        self::$enabled = true;
    }

    /**
     * A test-body coroutine starts; remember how its diagnostics are to be treated. Called from
     * Counit::create()/createAndJoin() next to the Attribution hook. Nothing is registered here:
     * while this coroutine runs for the first time, PHPUnit's own handler is enabled and does the
     * work.
     */
    public static function coroutineStarted(?object $caller): void
    {
        if (!self::$enabled) {
            return;
        }

        $cid = self::cid();
        if ($cid <= 0) {
            return;
        }

        if (!$caller instanceof PHPUnitTestCase) {
            self::$mode[$cid] = self::OFF;

            return;
        }

        self::$mode[$cid] = self::withoutErrorHandler($caller) ? self::PASS_THROUGH : self::CONVERT;
    }

    public static function coroutineFinished(): void
    {
        if (!self::$enabled) {
            return;
        }

        unset(self::$mode[self::cid()]);
    }

    /**
     * The coroutine PHPUnit runs on is about to yield, so test coroutines are about to run with
     * no converting handler of PHPUnit's on the stack: put counit's there. Called from
     * Attribution::suspended() and around the end-of-run drain loop.
     */
    public static function suspended(): void
    {
        if (!self::$enabled || self::$pushedBy !== null) {
            return;
        }

        $cid = self::cid();
        if ($cid <= 0 || isset(self::$mode[$cid])) {
            // A test coroutine yielding inside the main coroutine's own suspension: counit's
            // handler is already on the stack, and it must stay there until the main coroutine
            // resumes.
            return;
        }

        self::$pushedBy = $cid;

        set_error_handler(self::handler(), self::LEVELS);
    }

    /** The coroutine PHPUnit runs on has been resumed: hand the stack back untouched. */
    public static function resumed(): void
    {
        if (!self::$enabled || self::$pushedBy === null || self::$pushedBy !== self::cid()) {
            return;
        }

        self::$pushedBy = null;

        restore_error_handler();
    }

    public static function convertsCurrentCoroutine(): bool
    {
        return (self::$mode[self::cid()] ?? self::OFF) === self::CONVERT;
    }

    /**
     * The handler counit registers. It is a closure BOUND TO PHPUnit's ErrorHandler class on
     * purpose, and it calls ErrorHandler::__invoke() directly rather than through a helper:
     * ErrorHandler::errorStackTrace() drops the leading frames whose class is its own before the
     * issue-trigger resolver looks at the trace, so any frame of counit's sitting between the
     * triggering call and __invoke() would be taken for the code that triggered the deprecation.
     * A userland deprecation would then be classified as coming from counit instead of from the
     * test -- measured: --fail-on-self-deprecation stops matching where blocking mode matches.
     * Borrowing the class makes counit's frame disappear together with PHPUnit's own.
     */
    private static function handler(): \Closure
    {
        return self::$handler ??= \Closure::bind(
            static function (int $errorNumber, string $errorString, string $errorFile = '', int $errorLine = 0): bool {
                if (!Diagnostics::convertsCurrentCoroutine()) {
                    // Not a test coroutine of counit's, or a #[WithoutErrorHandler] test: leave
                    // the diagnostic to PHP, exactly as counit did before this class existed.
                    return false;
                }

                try {
                    ErrorHandler::instance()($errorNumber, $errorString, $errorFile, $errorLine);
                } catch (NoTestCaseObjectOnCallStackException) {
                    // Nothing on the call stack to attribute the issue to; same fallback.
                    return false;
                }

                // PHPUnit's handler returns false and relies on the error reporting level it
                // masked while enabled to keep PHP quiet. After the first yield that mask is
                // gone, so counit reports the diagnostic as handled instead -- same visible
                // result as blocking mode.
                return true;
            },
            null,
            ErrorHandler::class
        );
    }

    private static function withoutErrorHandler(PHPUnitTestCase $test): bool
    {
        try {
            return MetadataRegistry::parser()->forMethod($test::class, $test->name())->isWithoutErrorHandler()->isNotEmpty();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function cid(): int
    {
        // The is_int() check exists for PHPStan only: swoole/ide-helper types getCid() as mixed.
        $cid = Coroutine::getCid();

        return is_int($cid) ? $cid : -1;
    }
}

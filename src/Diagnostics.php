<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Util\ErrorHandler;
use Swoole\Coroutine;

/**
 * Conversion of diagnostics (deprecations/warnings/notices) triggered after a test's first yield.
 *
 * PHPUnit 8/9 turn a diagnostic into an exception thrown at the call site, from an error handler
 * TestResult::run() registers right before $test->runBare() and unregisters right after it
 * returns -- under counit, the test body's first yield, in BOTH approaches (the registration
 * lives outside runBare(), so this branch's "the whole runBare() runs inside the coroutine"
 * property does not help). Everything a test triggers after that yield reached PHP's default
 * handler instead: no exception, no error, the test passes and the run exits 0, where blocking
 * PHPUnit reports an error and exits 2.
 *
 * The fix has the shape of the master branch's: counit registers one handler of its own for
 * exactly the windows PHPUnit's cannot cover -- while the coroutine PHPUnit runs on is suspended,
 * which is the only time a suspended test coroutine can resume -- and delegates to PHPUnit's own
 * handler, so the convert*ToExceptions settings, the @-suppression rule and the exception
 * classes all stay PHPUnit's. The converted diagnostic is thrown inside the test's coroutine and
 * lands in counit's deferred-failure list (exit code 1; blocking errors the test natively, exit
 * code 2 -- this branch's standard post-yield reporting model).
 *
 * Registering only in that window is not an optimization: PHPUnit 8/9's ErrorHandler
 * registration gives up when any handler is already on the stack, so a handler counit leaves
 * behind would silently disable conversion for every following test. And the delegate must be
 * captured at COROUTINE START, not lazily at the first suspension: PHPUnit builds a per-test
 * handler and unregisters it when runBare() returns, and a test coroutine is guaranteed to be
 * spawned inside its own test's registration window -- a run where no test ever joins has no
 * other moment at which the handler is observable.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class Diagnostics
{
    /**
     * @var bool
     */
    public static $enabled = false;

    /**
     * Coroutine IDs counit spawned for a test body.
     *
     * @var array<int, true>
     */
    private static $mine = [];

    /**
     * The coroutine that pushed counit's handler, while it is on the shared stack.
     *
     * @var int|null
     */
    private static $pushedBy;

    /**
     * The converting handler PHPUnit had registered when the first test coroutine started. On
     * PHPUnit 9 that is a Util\ErrorHandler instance built from the run-wide
     * convert*ToExceptions settings (every test's instance is built from the same settings, so
     * the first is delegate enough -- even after PHPUnit unregistered it); on PHPUnit 8 it is
     * the static [ErrorHandler::class, 'handleError'] callable, whose convert flags live on the
     * Error\* classes and are maintained by TestResult::run() itself, so the ambient state is
     * always the run's own. Null when PHPUnit registered nothing (every convert* setting off):
     * the fix stays inert and PHP's default handling applies, as in blocking mode.
     *
     * @var callable|null
     */
    private static $delegate;

    public static function initialize(): void
    {
        self::$enabled = true;
    }

    public static function coroutineStarted(): void
    {
        if (!self::$enabled) {
            return;
        }

        $cid = self::cid();
        if ($cid > 0) {
            self::$mine[$cid] = true;
        }

        if (self::$delegate === null) {
            // Peek at the currently registered handler without disturbing the stack.
            $current = set_error_handler(static function (): bool {
                return false;
            });
            restore_error_handler();

            if ($current instanceof ErrorHandler) {
                // PHPUnit 9: the registered instance carries the run's convert* settings.
                self::$delegate = $current;
            } elseif (is_array($current) && $current[0] === ErrorHandler::class) {
                // PHPUnit 8: the converting handler is static; see $delegate.
                self::$delegate = $current;
            }
        }
    }

    public static function coroutineFinished(): void
    {
        if (!self::$enabled) {
            return;
        }

        unset(self::$mine[self::cid()]);
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
        if ($cid <= 0 || isset(self::$mine[$cid])) {
            // A test coroutine yielding inside the main coroutine's own suspension: counit's
            // handler is already on the stack, and it must stay there until the main coroutine
            // resumes.
            return;
        }

        if (self::$delegate === null) {
            return;
        }

        self::$pushedBy = $cid;

        set_error_handler([self::class, 'handle']);
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

    /**
     * The handler counit registers. Delegation throws the exact PHPUnit\Framework\Error\*
     * exception the blocking run would have thrown at this very call site, or returns false for
     * a severity the run does not convert -- native handling applies, as in blocking mode.
     * Diagnostics raised outside a test-body coroutine of counit's are always left to PHP.
     */
    public static function handle(int $errorNumber, string $errorString, string $errorFile = '', int $errorLine = 0): bool
    {
        if (!isset(self::$mine[self::cid()]) || self::$delegate === null) {
            return false;
        }

        $delegate = self::$delegate;

        return (bool) $delegate($errorNumber, $errorString, $errorFile, $errorLine);
    }

    private static function cid(): int
    {
        $cid = Coroutine::getCid();

        return is_int($cid) ? $cid : -1;
    }
}

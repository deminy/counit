<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Facade as EventFacade;
use Swoole\Coroutine;

/**
 * Per-coroutine error/exception handler stacks, plus the deferred half of PHPUnit's "did not
 * remove its own error/exception handlers" risky checks.
 *
 * Swoole saves and restores the output-buffer stack per coroutine (PHPContext::output_ptr, see
 * OutputCapture) but NOT EG(user_error_handler)/EG(user_exception_handler) or their stacks --
 * verified in swoole-src 4.8 and 6.0 and empirically: a handler pushed inside a coroutine is
 * visible everywhere, immediately, and survives its coroutine's death. PHPUnit snapshots both
 * stacks at the top of runBare() and compares/restores them at its bottom -- under counit, the
 * test body's first yield -- unconditionally, for every test. That used to break four ways: a
 * legal handler spanning a yield was stripped mid-body AND falsely flagged (its own later
 * restore_*() then popped PHPUnit's handler), a handler registered after the first yield leaked
 * unreported into the rest of the run (where it could swallow other tests' diagnostics), and a
 * bystander whose window covered someone else's leak was blamed for it.
 *
 * This class supplies the isolation Swoole does not: at every observation point counit controls,
 * the handlers the running coroutine pushed during its slice are lifted off the shared stack
 * (sliceEnded()) and put back when it resumes (sliceStarted()). Between two of counit's
 * observation points on one coroutine nothing else can run -- Swoole is cooperative -- so
 * everything above the slice's starting depth is provably that coroutine's own. A suspended
 * test's handler is therefore simply not on the stack while other tests run (no swallowed
 * diagnostics, no misattribution, nothing for PHPUnit's per-test snapshot to trip over), and a
 * test's own handler survives its own yields. What a coroutine still holds when it finishes is
 * its leak: the verdict -- PHPUnit's exact wording -- is emitted at the end of the run through a
 * real Test\ConsideredRisky event (the UselessTests late-emit seam), deduplicated against
 * verdicts PHPUnit reached itself. No join, no serialization.
 *
 * The stacks are read with the same public-API walk PHPUnit itself uses (set_*_handler() returns
 * the previous handler; pop; repeat to the bottom; re-push everything) -- no reflection into
 * anything.
 *
 * Two guards, both indispensable:
 *  - a coroutine that NEVER yielded is left completely alone: PHPUnit reads the stacks after its
 *    body already finished, exactly as in blocking mode, and reaches the verdict itself --
 *    natively, at the right moment (the same principle as the never-yielded credit decline).
 *  - a coroutine resumed at a point counit cannot observe (hooked network/DB IO, a
 *    fully-qualified \sleep(), a test class in the global namespace) has a stale slice base, so
 *    a stack delta measured across that gap is not provably its own
 *    (Attribution::sliceIsTrustworthy()). Such a coroutine is handed back everything already
 *    lifted on its behalf and left to counit's pre-existing behavior -- silence, never a false
 *    accusation. Without this guard, counit's own Redis sample tests were falsely flagged.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class HandlerIsolation
{
    public const ERROR_LEAK    = 'Test code or tested code did not remove its own error handlers';

    public const ERROR_FOREIGN = 'Test code or tested code removed error handlers other than its own';

    public const EXC_LEAK      = 'Test code or tested code did not remove its own exception handlers';

    public const EXC_FOREIGN   = 'Test code or tested code removed exception handlers other than its own';

    public static bool $enabled = false;

    /**
     * Per coroutine ID: handlers the coroutine pushed and has not popped, lifted off the shared
     * stack while it is suspended.
     *
     * @var array<int, array{error: list<callable>, exception: list<callable>}>
     */
    private static array $mine = [];

    /**
     * Per coroutine ID: the shared stacks' depths at the start of the current slice.
     *
     * @var array<int, array{error: int, exception: int}>
     */
    private static array $sliceStart = [];

    /**
     * Per coroutine ID: whether the coroutine popped below its own contribution during a slice.
     *
     * @var array<int, array{error: bool, exception: bool}>
     */
    private static array $foreign = [];

    /**
     * Coroutine IDs that have suspended at least once; see the never-yielded guard above.
     *
     * @var array<int, true>
     */
    private static array $yielded = [];

    /**
     * Coroutine IDs whose handler bookkeeping can no longer be trusted; see the trust guard
     * above.
     *
     * @var array<int, true>
     */
    private static array $untrusted = [];

    /**
     * Verdicts PHPUnit already emitted itself, so the deferred pass never repeats one.
     *
     * @var array<string, array<string, true>>
     */
    private static array $alreadyFlagged = [];

    /**
     * @var array<string, list<non-empty-string>> test ID => verdicts to emit at the end of the run
     */
    private static array $verdicts = [];

    /**
     * @var array<string, Test>
     */
    private static array $tests = [];

    /**
     * Remembers a finished test's event-facade Test object; the deferred pass needs it to emit
     * the verdict. Called from the Test\Finished subscriber.
     */
    public static function record(Test $test): void
    {
        self::$tests[$test->id()] = $test;
    }

    /**
     * One of PHPUnit's own handler verdicts (a never-yielded or joined test), so the deferred
     * pass does not repeat it. Called from the Test\ConsideredRisky subscriber.
     */
    public static function markFlagged(string $testId, string $message): void
    {
        if (in_array($message, [self::ERROR_LEAK, self::ERROR_FOREIGN, self::EXC_LEAK, self::EXC_FOREIGN], true)) {
            self::$alreadyFlagged[$testId][$message] = true;
        }
    }

    /**
     * A test coroutine starts, or resumes at an observation point: put back whatever it had
     * pushed before it yielded, and record the shared stacks' base depths for the new slice.
     */
    public static function sliceStarted(): void
    {
        if (!self::$enabled) {
            return;
        }

        $cid = self::cid();
        if ($cid <= 0 || isset(self::$untrusted[$cid])) {
            return;
        }

        if (!Attribution::sliceIsTrustworthy()) {
            self::distrust($cid);

            return;
        }

        foreach (self::$mine[$cid]['error'] ?? [] as $handler) {
            set_error_handler($handler);
        }

        foreach (self::$mine[$cid]['exception'] ?? [] as $handler) {
            set_exception_handler($handler);
        }

        self::$sliceStart[$cid] = [
            'error'     => count(self::readErrorHandlers()) - count(self::$mine[$cid]['error'] ?? []),
            'exception' => count(self::readExceptionHandlers()) - count(self::$mine[$cid]['exception'] ?? []),
        ];
    }

    /**
     * The coroutine is about to yield (or has finished): lift its own handlers -- everything
     * above the slice's base depth -- off the shared stack, so PHPUnit's snapshot/restore and
     * every other coroutine see only the baseline. A depth below the base records the
     * "removed ... other than its own" verdict.
     */
    public static function sliceEnded(): void
    {
        if (!self::$enabled) {
            return;
        }

        $cid = self::cid();
        if ($cid <= 0 || isset(self::$untrusted[$cid]) || !isset(self::$sliceStart[$cid])) {
            return;
        }

        if (!Attribution::sliceIsTrustworthy()) {
            self::distrust($cid);

            return;
        }

        self::$yielded[$cid] = true;

        $base = self::$sliceStart[$cid];

        $error = self::readErrorHandlers();
        if (count($error) > $base['error']) {
            self::$mine[$cid]['error'] = array_slice($error, $base['error']);

            for ($i = count($error); $i > $base['error']; $i--) {
                restore_error_handler();
            }
        } elseif (count($error) < $base['error']) {
            self::$mine[$cid]['error']    = [];
            self::$foreign[$cid]['error'] = true;
        } else {
            self::$mine[$cid]['error'] = [];
        }

        $exception = self::readExceptionHandlers();
        if (count($exception) > $base['exception']) {
            self::$mine[$cid]['exception'] = array_slice($exception, $base['exception']);

            for ($i = count($exception); $i > $base['exception']; $i--) {
                restore_exception_handler();
            }
        } elseif (count($exception) < $base['exception']) {
            self::$mine[$cid]['exception']    = [];
            self::$foreign[$cid]['exception'] = true;
        } else {
            self::$mine[$cid]['exception'] = [];
        }
    }

    /**
     * The coroutine has finished for good: whatever it still holds is its leak. The verdicts are
     * recorded for the end-of-run emit and the coroutine's bookkeeping is dropped.
     */
    public static function coroutineFinished(?string $testId): void
    {
        if (!self::$enabled) {
            return;
        }

        $cid = self::cid();
        if ($cid <= 0) {
            return;
        }

        if (isset(self::$untrusted[$cid])) {
            unset(self::$untrusted[$cid], self::$mine[$cid], self::$sliceStart[$cid], self::$foreign[$cid], self::$yielded[$cid]);

            return;
        }

        // Never yielded: leave both stacks exactly as the body left them and let PHPUnit's own
        // snapshot/restore reach the verdict, natively and in the right place.
        if (!isset(self::$yielded[$cid])) {
            unset(self::$mine[$cid], self::$sliceStart[$cid], self::$foreign[$cid]);

            return;
        }

        self::sliceEnded();

        if ($testId !== null) {
            $messages = [];

            if ((self::$mine[$cid]['error'] ?? []) !== []) {
                $messages[] = self::ERROR_LEAK;
            }

            if (self::$foreign[$cid]['error'] ?? false) {
                $messages[] = self::ERROR_FOREIGN;
            }

            if ((self::$mine[$cid]['exception'] ?? []) !== []) {
                $messages[] = self::EXC_LEAK;
            }

            if (self::$foreign[$cid]['exception'] ?? false) {
                $messages[] = self::EXC_FOREIGN;
            }

            if ($messages !== []) {
                self::$verdicts[$testId] = array_merge(self::$verdicts[$testId] ?? [], $messages);
            }
        }

        unset(self::$mine[$cid], self::$sliceStart[$cid], self::$foreign[$cid], self::$yielded[$cid]);
    }

    /**
     * Emits the recorded verdicts through PHPUnit's own risky event -- honored by the risky
     * listing, the summary's Risky count and the --fail-on-risky exit code, because the result
     * collector is only read after the extension's ExecutionFinished subscriber returns. Called
     * from CounitExtension, after the drain.
     */
    public static function emitDeferred(): void
    {
        foreach (self::$verdicts as $testId => $messages) {
            $test = self::$tests[$testId] ?? null;

            if ($test === null) {
                continue;
            }

            foreach (array_unique($messages) as $message) {
                if (isset(self::$alreadyFlagged[$testId][$message])) {
                    continue;
                }

                EventFacade::emitter()->testConsideredRisky($test, $message);
            }
        }

        self::$verdicts = [];
    }

    /**
     * @return list<callable>
     */
    private static function readErrorHandlers(): array
    {
        $stack = [];

        while (true) {
            $previous = set_error_handler(static fn (): bool => false);
            restore_error_handler();

            if ($previous === null) {
                break;
            }

            $stack[] = $previous;
            restore_error_handler();
        }

        $stack = array_reverse($stack);

        foreach ($stack as $handler) {
            if (is_callable($handler)) { // @phpstan-ignore function.alreadyNarrowedType
                set_error_handler($handler);
            }
        }

        return $stack;
    }

    /**
     * @return list<callable>
     */
    private static function readExceptionHandlers(): array
    {
        $stack = [];

        while (true) {
            $previous = set_exception_handler(static function (\Throwable $t): void {});
            restore_exception_handler();

            if ($previous === null) {
                break;
            }

            $stack[] = $previous;
            restore_exception_handler();
        }

        $stack = array_reverse($stack);

        foreach ($stack as $handler) {
            if (is_callable($handler)) { // @phpstan-ignore function.alreadyNarrowedType
                set_exception_handler($handler);
            }
        }

        return $stack;
    }

    /**
     * Stops tracking this coroutine and puts back everything already lifted on its behalf, so
     * the shared stacks end up exactly where counit's pre-existing behavior would have left
     * them.
     */
    private static function distrust(int $cid): void
    {
        foreach (self::$mine[$cid]['error'] ?? [] as $handler) {
            set_error_handler($handler);
        }

        foreach (self::$mine[$cid]['exception'] ?? [] as $handler) {
            set_exception_handler($handler);
        }

        self::$untrusted[$cid] = true;

        unset(self::$mine[$cid], self::$sliceStart[$cid], self::$foreign[$cid]);
    }

    private static function cid(): int
    {
        // The is_int() check exists for PHPStan only: swoole/ide-helper types getCid() as mixed.
        $cid = Coroutine::getCid();

        return is_int($cid) ? $cid : -1;
    }
}

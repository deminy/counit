<?php

declare(strict_types=1);

namespace Deminy\Counit;

use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;
use Swoole\Coroutine\WaitGroup;

/**
 * A nesting-safe substitute for `Swoole\Coroutine\Scheduler` in tests that manage their own
 * coroutines directly, instead of letting counit manage them.
 *
 * `Counit::create()` / `Counit::sleep()` exist to let one slow, blocking call yield so *other
 * tests* can make progress while it waits. They are not for a test that instead needs several of
 * its *own* coroutines to run concurrently against each other -- exercising a mutex, a queue, or
 * any other coroutine-native code directly. Under plain PHPUnit such a test bootstraps its own
 * `Scheduler`:
 *
 *     $scheduler = new Swoole\Coroutine\Scheduler();
 *     $scheduler->add($coroutineA);
 *     $scheduler->add($coroutineB);
 *     $scheduler->start();
 *
 * Under the `counit` runner with the Swoole extension enabled, though, the whole PHPUnit run
 * already happens inside one coroutine (see the `counit` script), and Swoole does not allow a
 * second, nested event loop -- which is what `Scheduler::start()` starts internally, via
 * `Event::wait()` -- from inside a running one:
 *
 *     Fatal error: Swoole\Coroutine\Scheduler::start(): Unable to call Event::wait() in coroutine
 *
 * `CoroutineGroup::run()` is a drop-in replacement for the snippet above that is safe in both
 * contexts: it bootstraps a `Scheduler` when nothing is running yet (exactly as above), and starts
 * plain sibling coroutines instead when one already is.
 *
 * `CoroutineGroup::runWithTimeout()` is the same, but gives up and throws after a deadline instead
 * of risking a permanent hang on a callable that never finishes -- see its own docblock for what it
 * can and cannot actually bound.
 */
final class CoroutineGroup
{
    /**
     * Runs every given callable as its own coroutine and blocks until all of them have finished --
     * and, when this is called before any coroutine is running (see runViaScheduler() below), until
     * everything they transitively spawn via `go()` / `Coroutine::create()` has too, since nothing
     * else is running to end the wait early (the same as a raw `Scheduler`). Once already running
     * inside a coroutine (see runViaNestedCoroutines() below), only the given callables themselves
     * are waited on: a callable that spawns further work and returns before it finishes leaves that
     * work still running after `run()` returns. Coroutine-native code should always wait for what
     * it spawns before returning regardless of this class -- the same discipline
     * runViaNestedCoroutines()'s own implementation follows.
     *
     * Unlike `Counit::create()`, this never returns before the work is done, so it does not
     * participate in counit's assertion-attribution or late-failure/skip machinery: every
     * callable runs to completion regardless of what its siblings do, and the first Throwable
     * any of them threw -- a failed assertion among them -- is rethrown from here, once
     * everything has finished, exactly as it would be had that callable simply been called
     * directly in the calling test method instead of scheduled. Swoole does not do this on its
     * own: an uncaught Throwable does not propagate out of a coroutine to whatever started it,
     * it kills the whole process instead (the same limitation `Counit::create()` guards against
     * for its own spawned coroutines) -- both branches below catch inside each callable's own
     * coroutine for that reason, purely to relay the failure back here, not to change what the
     * caller ultimately sees.
     */
    public static function run(callable ...$callables): void
    {
        if ($callables === []) {
            // Nothing to do -- and nothing worth bootstrapping a Scheduler or a coroutine for
            // either: an empty Scheduler::start() call emits its own engine-level deprecation
            // warning on shutdown ("Event::wait() in shutdown function is deprecated"), noise a
            // caller with nothing to run should never see.
            return;
        }

        if (!extension_loaded('swoole')) {
            // No coroutines are possible at all; the closest equivalent is running them one
            // after another, right here -- the same "no Swoole -> plain blocking behavior"
            // every other counit API falls back to.
            foreach ($callables as $callable) {
                $callable();
            }

            return;
        }

        if (Coroutine::getCid() === -1) {
            self::runViaScheduler(...$callables);

            return;
        }

        self::runViaNestedCoroutines(null, ...$callables);
    }

    /**
     * Like run(), but gives up waiting after $seconds and throws instead of risking a permanent
     * hang on a callable that never finishes -- at the cost of only being able to enforce that
     * bound in one of the three shapes below, because Swoole gives no way to abandon an
     * already-started wait early from outside it in the other two:
     *
     * - No Swoole extension: the same synchronous fallback run() uses. A plain PHP call cannot be
     *   interrupted short of pcntl signals, which this class does not use (see the class docblock's
     *   design notes) -- $seconds is accepted but has nothing to bound.
     * - Called before any coroutine is running: delegates to the same runViaScheduler() run() uses.
     *   A raw `Scheduler` has no timeout of its own: `Scheduler::start()` -- like `Coroutine\run()`,
     *   which was tried as a replacement for it here and rejected for the identical reason -- always
     *   blocks until every coroutine in its own bootstrapped event loop has finished, with no public
     *   API to abandon that wait early from outside. Confirmed empirically: racing a `WaitGroup`
     *   with a short timeout against a longer-sleeping coroutine inside `Coroutine\run()` does make
     *   the `WaitGroup` give up on time, but `Coroutine\run()` itself still blocks until that
     *   coroutine finishes regardless -- the timeout has nothing left to bound by the time it fires.
     *   $seconds is accepted but not enforced here either, for the same reason a raw `Scheduler`
     *   cannot enforce one.
     * - Called from inside an already-running coroutine -- the common case: every test under
     *   `counit` with Swoole enabled -- the bound is real, via runViaNestedCoroutines() below: this
     *   method's own coroutine can give up and return to its caller without needing the whole
     *   process's coroutine container to drain first, unlike the two shapes above. Whatever
     *   callable(s) are still running when the deadline hits keep running in the background --
     *   Swoole coroutines cannot be cancelled -- which is a leaked coroutine for the rest of the
     *   run, the same risk any other leftover coroutine already is.
     *
     * @throws \Throwable whatever the first callable to fail threw, if one did before the deadline
     * @throws Exception if $seconds is not greater than zero, or if the deadline passed with no
     *                   callable having failed yet
     */
    public static function runWithTimeout(float $seconds, callable ...$callables): void
    {
        if ($callables === []) {
            return;
        }

        if ($seconds <= 0.0) {
            // A non-positive value is not "fail immediately": Swoole's own WaitGroup::wait()
            // treats it as "no timeout" (confirmed empirically), the opposite of what a caller
            // reaching for a *timeout* method would expect from passing e.g. 0. Rejected outright
            // rather than silently degrading to an unbounded wait.
            throw new Exception(sprintf('%s(): $seconds must be greater than 0, %F given.', __METHOD__, $seconds));
        }

        if (!extension_loaded('swoole') || Coroutine::getCid() === -1) {
            self::run(...$callables);

            return;
        }

        self::runViaNestedCoroutines($seconds, ...$callables);
    }

    /**
     * No coroutine running yet -- e.g. a test running under plain PHPUnit, or under `counit`
     * without the Swoole extension enabled. Scheduler::start() blocks until every coroutine it
     * started, and everything they spawned, has finished.
     *
     * A raw `Scheduler` does not catch what a scheduled callable throws: an uncaught Throwable
     * still kills the whole process from inside `start()`, the same as it would from a bare
     * `Coroutine::create()`. Each callable is wrapped to catch its own Throwable instead of
     * letting `Scheduler` see it directly, so `start()` always returns; only the first Throwable
     * observed across every callable is kept, and rethrown below once it has.
     */
    private static function runViaScheduler(callable ...$callables): void
    {
        $scheduler = new Scheduler();
        $thrown    = null;

        foreach ($callables as $callable) {
            $scheduler->add(static function () use ($callable, &$thrown): void {
                try {
                    $callable();
                } catch (\Throwable $e) {
                    if ($thrown === null) {
                        $thrown = $e;
                    }
                }
            });
        }

        $scheduler->start();

        if ($thrown !== null) {
            throw $thrown;
        }
    }

    /**
     * Already inside a coroutine -- what running under the `counit` runner with Swoole enabled
     * looks like for every test, whether or not it uses this class. The callables are started as
     * siblings of the current coroutine via `Coroutine::create()`, which -- unlike
     * `Scheduler::start()` -- needs no event loop of its own and is safe to call from within a
     * running coroutine.
     *
     * Completion is tracked with a `Swoole\Coroutine\WaitGroup`: one `add()` per callable before
     * any of them starts, one `done()` per callable once it returns, `wait()` blocks until every
     * `add()` has been matched by a `done()`. This waits on exactly -- only, and always -- the
     * callables this call itself spawned, which matters because the calling coroutine is not
     * necessarily the only other one alive: under `counit` with Swoole enabled, this method runs
     * *nested* inside the run's own root coroutine (see the `counit` script) and, for the
     * automatic approach or a manual test wrapped in `Counit::create()`, inside that test's own
     * coroutine too -- both still alive and waiting on this very call to return.
     *
     * An earlier version of this method instead polled the process-wide coroutine count
     * (`Coroutine::stats()['coroutine_num']`) down to exactly 1, on the reasoning that "nothing
     * left running besides the caller" is the same "everything finished" condition
     * `Scheduler::start()` itself relies on. That reasoning silently assumed the caller's
     * coroutine was the *only* other one alive -- true when this method is called directly from
     * the process's one root coroutine, but false the moment it's called from a coroutine that is
     * itself nested inside another still-running one, as above. In that shape the count can never
     * drop to 1 while the outer coroutine is alive waiting on this call, which deadlocked every
     * such use permanently. Joining only what was actually spawned has no such blind spot,
     * whatever else happens to be running, nested or not.
     *
     * A bare `Coroutine::create()` does not catch what its callable throws either -- same as
     * `Scheduler`, an uncaught Throwable kills the whole process instead of reaching this
     * method's caller. Each callable is wrapped to catch its own Throwable -- from a `finally`,
     * so `done()` is still called and the WaitGroup above never stalls on a throwing callable --
     * keeping only the first Throwable observed across every callable, rethrown below once
     * `wait()` returns.
     *
     * $timeoutSeconds is null for run()'s plain, unbounded join (`wait()`'s own default, `-1.0`,
     * already means "no timeout") and a positive number of seconds for runWithTimeout()'s bounded
     * one. The timeout Exception below is guarded on $timeoutSeconds itself, not on `$finished`
     * alone: run()'s own unbounded call cannot observe `$finished === false` in practice (nothing
     * ever gives up), but gating the throw on "a deadline was actually given" rather than on that
     * one boundary value is the more honest invariant to depend on -- run() itself should never be
     * capable of throwing a timeout-shaped Exception, on principle, whatever `wait()` happens to do.
     */
    private static function runViaNestedCoroutines(?float $timeoutSeconds, callable ...$callables): void
    {
        $waitGroup = new WaitGroup();
        $waitGroup->add(count($callables));
        $thrown = null;

        foreach ($callables as $callable) {
            Coroutine::create(static function () use ($callable, $waitGroup, &$thrown): void {
                try {
                    $callable();
                } catch (\Throwable $e) {
                    if ($thrown === null) {
                        $thrown = $e;
                    }
                } finally {
                    $waitGroup->done();
                }
            });
        }

        $finished = $waitGroup->wait($timeoutSeconds ?? -1.0);

        if ($thrown !== null) {
            throw $thrown;
        }

        if ($timeoutSeconds !== null && !$finished) {
            throw new Exception(sprintf('%s::runWithTimeout(): %d of %d callable(s) still running after %F second(s).', self::class, $waitGroup->count(), count($callables), $timeoutSeconds));
        }
    }
}

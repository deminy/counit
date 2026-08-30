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
 * `CoroutineScheduler::run()` is a drop-in replacement for the snippet above that is safe in both
 * contexts: it bootstraps a `Scheduler` when nothing is running yet (exactly as above), and starts
 * plain sibling coroutines instead when one already is.
 */
final class CoroutineScheduler
{
    /**
     * Runs every given callable as its own coroutine and blocks until all of them -- and
     * everything they transitively spawn via `go()` / `Coroutine::create()` -- have finished.
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

        self::runViaNestedCoroutines(...$callables);
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
     */
    private static function runViaNestedCoroutines(callable ...$callables): void
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

        $waitGroup->wait();

        if ($thrown !== null) {
            throw $thrown;
        }
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit;

use Swoole\Coroutine;
use Swoole\Coroutine\Scheduler;

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
     * participate in counit's assertion-attribution or late-failure/skip machinery: an assertion
     * or exception inside a callable behaves exactly as it would if it ran synchronously in the
     * calling test method, in every context.
     */
    public static function run(callable ...$callables): void
    {
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
     */
    private static function runViaScheduler(callable ...$callables): void
    {
        $scheduler = new Scheduler();

        foreach ($callables as $callable) {
            $scheduler->add($callable);
        }

        $scheduler->start();
    }

    /**
     * Already inside a coroutine -- what running under the `counit` runner with Swoole enabled
     * looks like for every test, whether or not it uses this class. The callables are started as
     * siblings of the current coroutine via `Coroutine::create()`, which -- unlike
     * `Scheduler::start()` -- needs no event loop of its own and is safe to call from within a
     * running coroutine.
     *
     * There is no public Swoole API to join a specific coroutine, or to wait for only the
     * descendants of a specific one, so completion is detected by polling the process-wide
     * coroutine count down to 1 -- nothing left running besides the caller -- the same
     * "everything finished" condition `Scheduler::start()` itself relies on (there, the
     * process-wide count reaching zero).
     *
     * A count captured just before starting the callables, and compared with ">", would look
     * right in isolation but is not safe in a real suite: tests sharing this one coroutine run one
     * after another, and a coroutine left over from whichever test ran just before this call --
     * something with its own idle timeout still counting down, say -- would be part of that
     * captured baseline. Such a straggler finishing during the wait below drops the count by one,
     * same as one of *this* call's own coroutines finishing would, so a baseline comparison can
     * read "no more than we started with" while one of ours is still running. Waiting for the
     * count to reach exactly 1 has no such blind spot: every coroutine still alive, related to
     * this call or not, is waited out -- at the cost of this call also waiting on stragglers that
     * have nothing to do with it, and never returning at all should one of them never finish.
     */
    private static function runViaNestedCoroutines(callable ...$callables): void
    {
        foreach ($callables as $callable) {
            Coroutine::create($callable);
        }

        while (Coroutine::stats()['coroutine_num'] > 1) { // @phpstan-ignore offsetAccess.nonOffsetAccessible
            Coroutine::sleep(0.001);
        }
    }
}

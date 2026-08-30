<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\CoroutineGroup;
use Deminy\Counit\Counit;
use Deminy\Counit\Exception;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * This suite runs in all four combinations covered by .github/workflows/unit_tests.yml -- under
 * `phpunit` and under `counit`, each with and without the Swoole extension enabled -- which is
 * what exercises every branch of CoroutineGroup::run() without needing to force any of them
 * directly: without the extension it falls back to calling the given callables in order; with the
 * extension but no coroutine running yet (plain `phpunit` never opens one) it bootstraps its own
 * `Scheduler`; and under `counit` with the extension enabled, every test method here already runs
 * inside the one coroutine the `counit` script itself opens for the whole run, which is what pins
 * the nesting-safe branch -- a raw `Swoole\Coroutine\Scheduler` in that position would abort with
 * "Unable to call Event::wait() in coroutine".
 *
 * @internal
 */
#[CoversNothing]
class CoroutineGroupTest extends TestCase
{
    /**
     * Every callable actually finishes before run() returns to the caller.
     */
    public function testRunsEveryCallableToCompletionBeforeReturning(): void
    {
        $finished = [];

        CoroutineGroup::run(
            static function () use (&$finished): void {
                usleep(2_000);
                $finished[] = 'a';
            },
            static function () use (&$finished): void {
                usleep(1_000);
                $finished[] = 'b';
            },
            static function () use (&$finished): void {
                $finished[] = 'c';
            },
        );

        self::assertCount(3, $finished);
        self::assertContains('a', $finished);
        self::assertContains('b', $finished);
        self::assertContains('c', $finished);
    }

    /**
     * With the Swoole extension enabled, the callables genuinely interleave -- the one sleeping
     * longer finishes last despite starting first, rather than blocking the other one out. Without
     * the extension nothing can be concurrent, so run() calls them in the order given instead; this
     * is the one place the two environments are expected to disagree.
     *
     * usleep() only yields once Swoole's coroutine hooks are enabled -- exactly like under a raw
     * Swoole\Coroutine\Scheduler, which CoroutineGroup::run() is a substitute for, not an
     * improvement on. Under `counit` with Swoole enabled they already are, globally, before the
     * run's one coroutine even opens (see the `counit` script); enabling them again here is then a
     * documented no-op. Under plain `phpunit` nothing has enabled them yet, so this test does --
     * exactly as a consumer bootstrapping its own Scheduler-based test would.
     *
     * The 300x margin between the two sleeps (rather than, say, 2x) matches this project's own
     * convention for timing-sensitive ordering assertions elsewhere (e.g.
     * tests/regression/HandlerCanaryTest.php's usleep(300000) vs usleep(1000)) -- enough headroom
     * that scheduler jitter on a loaded CI runner cannot plausibly flip the observed order.
     */
    public function testCallablesInterleaveOnlyWhenSwooleIsEnabled(): void
    {
        if (extension_loaded('swoole')) {
            Runtime::enableCoroutine(SWOOLE_HOOK_SLEEP);
        }

        try {
            $finished = [];

            CoroutineGroup::run(
                static function () use (&$finished): void {
                    usleep(300_000);
                    $finished[] = 'slow';
                },
                static function () use (&$finished): void {
                    usleep(1_000);
                    $finished[] = 'fast';
                },
            );

            if (extension_loaded('swoole')) {
                self::assertSame(
                    ['fast', 'slow'],
                    $finished,
                    'The faster callable finishes first when both run concurrently.',
                );
            } else {
                self::assertSame(
                    ['slow', 'fast'],
                    $finished,
                    'Without Swoole, callables run in the order they were given.',
                );
            }
        } finally {
            if (extension_loaded('swoole')) {
                Runtime::enableCoroutine(0);
            }
        }
    }

    /**
     * Only meaningful already running inside a coroutine, which -- see the class docblock -- is
     * exactly what every test method here does under `counit` with Swoole enabled, and never does
     * otherwise. This is the scenario a raw Scheduler cannot handle.
     */
    public function testWorksWhenAlreadyRunningInsideACoroutine(): void
    {
        if (!extension_loaded('swoole') || Coroutine::getCid() === -1) {
            self::markTestSkipped('Only meaningful when already running inside a coroutine.');
        }

        $ran = false;

        CoroutineGroup::run(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    /**
     * `Counit::create()` wraps this body in its own coroutine, nested inside the one `counit`
     * itself opens for the whole run under Swoole -- so `run()` here is called from a coroutine
     * that is, in turn, nested inside another still-running one, unlike every test method above
     * (which -- see the class docblock -- runs directly on `counit`'s one root coroutine, with
     * nothing else in between). A version of `runViaNestedCoroutines()` that waited for the
     * process-wide coroutine count to drop to exactly 1 could never satisfy that condition here,
     * since the outer, `Counit::create()`-opened coroutine stays alive for the whole test method --
     * it deadlocked this exact shape permanently. Under plain PHPUnit, `Counit::create()` isn't
     * coroutine-friendly and just calls its callable directly, so this degenerates to the same
     * "no coroutine running yet" shape `testWorksWhenAlreadyRunningInsideACoroutine()`'s sibling
     * cases already cover.
     */
    public function testWorksWhenCalledFromInsideCounitCreate(): void
    {
        Counit::create(function (): void {
            $ran = false;

            CoroutineGroup::run(static function () use (&$ran): void {
                $ran = true;
            });

            self::assertTrue($ran);
        });
    }

    /**
     * A Throwable from one callable propagates out of run() to the caller, synchronously --
     * exactly as it would have if that callable had simply been called directly instead of
     * scheduled (see the class docblock). Neither Swoole branch can "cancel" a sibling coroutine
     * that is already running, so with the extension available (both the fresh-Scheduler and the
     * nested-coroutine branches) the sibling still runs to completion before run() rethrows;
     * without the extension, run() calls the callables in order and a Throwable stops it right
     * there, exactly like plain sequential PHP code would -- the one place, like the interleaving
     * test above, the two environments are expected to disagree.
     */
    public function testThrowingCallablePropagatesAndSiblingsStillRun(): void
    {
        $ran    = [];
        $thrown = null;

        try {
            CoroutineGroup::run(
                static function () use (&$ran): void {
                    $ran[] = 'a';

                    throw new \RuntimeException('boom');
                },
                static function () use (&$ran): void {
                    $ran[] = 'b';
                },
            );
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'run() must rethrow the Throwable, not swallow it.');
        self::assertSame('boom', $thrown->getMessage());
        self::assertContains('a', $ran);

        if (extension_loaded('swoole')) {
            self::assertContains('b', $ran, 'With Swoole available, the sibling callable still runs to completion.');
        } else {
            self::assertNotContains('b', $ran, 'Without Swoole, run() stops at the first Throwable, like plain sequential code.');
        }
    }

    /**
     * The realistic case behind the class's own safety claim: a failed PHPUnit assertion made
     * inside a scheduled callable -- not a plain exception -- reaches PHPUnit's own handling
     * exactly as it would have from the test body directly, in whichever branch this runs under.
     * Before each callable's coroutine was wrapped in a try/catch, an uncaught Throwable here
     * crashed the whole PHP process instead (Swoole does not propagate one out of a coroutine on
     * its own): no PHPUnit summary at all, and every other test still queued behind this one
     * silently never ran and was never reported, pass or fail.
     */
    public function testFailedAssertionInsideCallablePropagatesAsATestFailure(): void
    {
        $this->expectException(ExpectationFailedException::class);

        CoroutineGroup::run(static function (): void {
            self::assertSame(1, 2, 'deliberate failing assertion inside a CoroutineGroup callable');
        });
    }

    /**
     * run() is variadic; calling it with nothing to run must return immediately in every branch,
     * not hang -- e.g. the fresh-Scheduler branch's Scheduler::start() with nothing added, or the
     * nested-coroutine branch's WaitGroup counter starting (and staying) at zero.
     */
    public function testWorksWithZeroCallables(): void
    {
        $start = microtime(true);

        CoroutineGroup::run();

        self::assertLessThan(1.0, microtime(true) - $start, 'run() with no callables must return immediately.');
    }

    /**
     * The realistic reason runWithTimeout() exists: a callable that never finishes -- a genuinely
     * stuck mutex/queue test, not merely a slow one -- must not hang the whole run forever. Only
     * meaningful once already running inside a coroutine (see runWithTimeout()'s own docblock):
     * that is the one shape where the deadline is actually enforced, unlike the other two (see
     * testRunWithTimeoutDoesNotEnforceTheDeadlineOutsideACoroutine()). The 50x margin between the
     * timeout and the stuck callable's own sleep (0.01s vs 0.5s) matches this project's convention
     * of a wide safety margin for timing-sensitive assertions.
     */
    public function testRunWithTimeoutThrowsWhenACallableNeverFinishes(): void
    {
        if (!extension_loaded('swoole') || Coroutine::getCid() === -1) {
            self::markTestSkipped('The deadline is only enforced once already running inside a coroutine.');
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/still running after/');

        CoroutineGroup::runWithTimeout(0.01, static function (): void {
            Coroutine::sleep(0.5);
        });
    }

    /**
     * The other side of testRunWithTimeoutThrowsWhenACallableNeverFinishes(): outside an
     * already-running coroutine, $seconds is accepted but genuinely cannot be enforced -- see
     * runWithTimeout()'s own docblock for why (Scheduler::start() / Coroutine\run() always drain
     * their own event loop fully, with no public API to abandon that wait early). Pinned here so a
     * callable that outlasts the given "timeout" is confirmed to still run to completion rather
     * than throw, in whichever of the two non-enforcing shapes this test happens to run under.
     */
    public function testRunWithTimeoutDoesNotEnforceTheDeadlineOutsideACoroutine(): void
    {
        if (extension_loaded('swoole') && Coroutine::getCid() !== -1) {
            self::markTestSkipped('Only meaningful when the deadline cannot be enforced (see the sibling test).');
        }

        $ran = false;

        CoroutineGroup::runWithTimeout(0.001, static function () use (&$ran): void {
            usleep(300_000);
            $ran = true;
        });

        self::assertTrue($ran, 'The callable must still run to completion even though it outlasted $seconds.');
    }

    /**
     * The common, hopefully-typical case: every callable finishes well within the deadline, so
     * runWithTimeout() behaves exactly like run() -- no exception, no different from what a
     * consumer's coroutine-native test would see under a raw Scheduler with no timeout at all.
     */
    public function testRunWithTimeoutFinishesNormallyWithinTheDeadline(): void
    {
        $ran = false;

        CoroutineGroup::runWithTimeout(5.0, static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }

    /**
     * A real failure inside a callable is more useful than a generic "timed out" report, so it
     * wins -- confirmed here with a deadline generous enough that the failure, not the clock, is
     * what ends the wait, in whichever branch this runs under. Mirrors
     * testThrowingCallablePropagatesAndSiblingsStillRun(), run()'s equivalent.
     */
    public function testRunWithTimeoutPropagatesARealFailureRatherThanTimingOut(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('real failure');

        CoroutineGroup::runWithTimeout(5.0, static function (): void {
            throw new \RuntimeException('real failure');
        });
    }

    /**
     * $seconds <= 0 is rejected outright rather than silently degrading to an unbounded wait:
     * Swoole's own WaitGroup::wait() treats a non-positive timeout as "no timeout" (the opposite
     * of what a caller reaching for a *timeout* method would expect from passing e.g. 0), so
     * runWithTimeout() refuses both instead of quietly inheriting that surprise.
     */
    #[DataProvider('nonPositiveSecondsProvider')]
    public function testRunWithTimeoutRejectsNonPositiveSeconds(float $seconds): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches('/\$seconds must be greater than 0/');

        CoroutineGroup::runWithTimeout($seconds, static function (): void {});
    }

    /**
     * @return array<string, array{float}>
     */
    public static function nonPositiveSecondsProvider(): array
    {
        return [
            'zero'     => [0.0],
            'negative' => [-1.0],
        ];
    }

    /**
     * run() is variadic; calling runWithTimeout() with nothing to run must return immediately in
     * every branch, not hang -- the same guarantee testWorksWithZeroCallables() pins for run(),
     * extended here since the timeout machinery is a different code path.
     */
    public function testRunWithTimeoutWorksWithZeroCallables(): void
    {
        $start = microtime(true);

        CoroutineGroup::runWithTimeout(1.0);

        self::assertLessThan(1.0, microtime(true) - $start, 'runWithTimeout() with no callables must return immediately.');
    }
}

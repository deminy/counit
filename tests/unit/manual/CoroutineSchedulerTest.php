<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\CoroutineScheduler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Runtime;

/**
 * This suite runs in all four combinations covered by .github/workflows/unit_tests.yml -- under
 * `phpunit` and under `counit`, each with and without the Swoole extension enabled -- which is
 * what exercises every branch of CoroutineScheduler::run() without needing to force any of them
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
class CoroutineSchedulerTest extends TestCase
{
    /**
     * Every callable actually finishes before run() returns to the caller.
     */
    public function testRunsEveryCallableToCompletionBeforeReturning(): void
    {
        $finished = [];

        CoroutineScheduler::run(
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
     * Swoole\Coroutine\Scheduler, which CoroutineScheduler::run() is a substitute for, not an
     * improvement on. Under `counit` with Swoole enabled they already are, globally, before the
     * run's one coroutine even opens (see the `counit` script); enabling them again here is then a
     * documented no-op. Under plain `phpunit` nothing has enabled them yet, so this test does --
     * exactly as a consumer bootstrapping its own Scheduler-based test would.
     */
    public function testCallablesInterleaveOnlyWhenSwooleIsEnabled(): void
    {
        if (extension_loaded('swoole')) {
            Runtime::enableCoroutine(SWOOLE_HOOK_SLEEP);
        }

        try {
            $finished = [];

            CoroutineScheduler::run(
                static function () use (&$finished): void {
                    usleep(20_000);
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

        CoroutineScheduler::run(static function () use (&$ran): void {
            $ran = true;
        });

        self::assertTrue($ran);
    }
}

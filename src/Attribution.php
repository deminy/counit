<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\Assert;
use Swoole\Coroutine;

/**
 * Segment accounting: attributes the increments of PHPUnit's static assertion counter to the
 * tests that performed them, so the per-testcase numbers in the JUnit XML report can be corrected
 * to what a blocking run reports (see Counit::correctedAssertionCountFor() and JunitXmlCorrector).
 *
 * The model: Swoole coroutines are cooperative and single-threaded, so at any instant exactly one
 * coroutine is running, and between two observation points with no yield in between every
 * increment of the counter belongs to the owner declared at the earlier point. counit observes
 * every point it controls: test coroutine start/end (Counit::create()), Counit::sleep(), the
 * sleep()/usleep() shims installed per test-class namespace (see installShims()), PHPUnit's
 * Test\PreparationStarted and Test\Finished events, and the return from Coroutine::create(). The
 * attribution is exact when every resume of every coroutine happens at such a point; a yield
 * counit cannot observe (hooked network IO, a fully-qualified \sleep() call, a test class in the
 * global namespace) makes the owning test's own number an undercount -- but never inflates
 * another test's, because a segment with no declared owner is attributed to nobody.
 *
 * Disabled -- with Counit::correctedAssertionCountFor() falling back to the credit/late
 * arithmetic -- when Swoole's preemptive scheduler is turned on: it switches coroutines without a
 * yield, which breaks the no-switch-between-observation-points premise.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class Attribution
{
    /** Set by CounitExtension when the run is coroutine-friendly and the model's premise holds. */
    public static bool $enabled = false;

    /**
     * Per test (keyed by the test's event ID): the static-counter assertions attributed to it.
     *
     * @var array<string, int>
     */
    private static array $own = [];

    /**
     * Per test (keyed the same way): the counter window PHPUnit harvested into it -- the counter's
     * value when its Test\Finished event fired (the counter is reset at each test's start and
     * added to the test right before the event, so that value IS the harvested window).
     *
     * @var array<string, int>
     */
    private static array $harvested = [];

    /**
     * Tests counit ran -- and therefore observed -- a coroutine for. A test without one (a
     * process-isolated test, or a test that never went through Counit::create()) had nothing of
     * its own run after PHPUnit reported it, so PHPUnit's number is already exact.
     *
     * @var array<string, true>
     */
    private static array $observedCoroutines = [];

    /**
     * @var array<int, string> coroutine ID => the ID of the test owning that coroutine
     */
    private static array $cidOwner = [];

    /** The test PHPUnit is currently running on the main coroutine. */
    private static ?string $mainOwner = null;

    /** Owner of the segment since the last observation point (null: nobody claims it). */
    private static ?string $current = null;

    /** The static counter's value at the last observation point. */
    private static int $mark = 0;

    /**
     * Tests whose coroutine was observed executing code it did not hold the counter for -- i.e.
     * it was resumed at a point counit cannot observe (hooked network IO, a fully-qualified
     * \sleep(), a test class in the global namespace). Their own tally is an undercount, so it
     * must never be treated as proof of anything; see UselessTests.
     *
     * @var array<string, true>
     */
    private static array $unattributed = [];

    /**
     * @var array<string, true> namespaces a sleep()/usleep() shim was installed in (or found
     *                          occupied and left alone)
     */
    private static array $shimmed = [];

    /**
     * A test coroutine created by Counit::create() starts running: register its owner and claim
     * the counter for it.
     */
    public static function coroutineStarted(?string $testId): void
    {
        if (!self::$enabled) {
            return;
        }

        if ($testId !== null) {
            $cid = self::currentCoroutineId();
            if ($cid > 0) {
                self::$cidOwner[$cid] = $testId;
            }
            self::$observedCoroutines[$testId] = true;
        }

        self::switchTo($testId);
    }

    /**
     * The coroutine's callable (and its deferred cleanups) have finished: claim the tail segment
     * and release the counter.
     */
    public static function coroutineFinished(): void
    {
        if (!self::$enabled) {
            return;
        }

        self::verifyHeldCounter();
        self::switchTo(null);
        unset(self::$cidOwner[self::currentCoroutineId()]);
    }

    /** The current coroutine is about to yield: claim its segment and release the counter. */
    public static function suspended(): void
    {
        // Before the counter switch below clears the ownership state sliceIsTrustworthy() reads:
        // lift the suspending coroutine's own handlers off the shared stacks. See
        // HandlerIsolation.
        HandlerIsolation::sliceEnded();

        if (!self::$enabled) {
            return;
        }

        self::verifyHeldCounter();
        self::switchTo(null);
    }

    /**
     * Whether the test's coroutine was ever observed running unattributed code -- in which case
     * its own per-test tally is an undercount and proves nothing.
     */
    public static function unattributedFor(string $testId): bool
    {
        return isset(self::$unattributed[$testId]);
    }

    /** The current coroutine has just been resumed: re-claim the counter for its owner. */
    public static function resumed(): void
    {
        if (!self::$enabled) {
            return;
        }

        self::switchTo(self::$cidOwner[self::currentCoroutineId()] ?? self::$mainOwner);
        // After the counter switch re-established the ownership state sliceIsTrustworthy()
        // reads: put the resuming coroutine's own handlers back on the shared stacks. See
        // HandlerIsolation.
        HandlerIsolation::sliceStarted();
    }

    /**
     * Whether the current coroutine is the counter's declared owner -- i.e. its current slice
     * began at an observation point counit controls, so everything that happened during the
     * slice provably belongs to it. False after a resume counit could not observe (hooked
     * network/DB IO, a fully-qualified \sleep(), a test class in the global namespace); see
     * HandlerIsolation, which uses this to decide whether a handler-stack delta may be claimed.
     */
    public static function sliceIsTrustworthy(): bool
    {
        if (!self::$enabled) {
            return false;
        }

        $owner = self::$cidOwner[self::currentCoroutineId()] ?? null;

        return ($owner !== null) && (self::$current === $owner);
    }

    /**
     * PHPUnit starts preparing/running a test on the main coroutine (Test\PreparationStarted --
     * emitted before setUp()/#[Before] hooks, so their assertions are claimed for the test too).
     */
    public static function testStarting(string $testId): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$mainOwner = $testId;
        self::switchTo($testId);
    }

    /**
     * PHPUnit reported the test (Test\Finished): capture the window it harvested into the test
     * and release the counter.
     */
    public static function testFinished(string $testId): void
    {
        if (!self::$enabled) {
            return;
        }

        self::switchTo(null);
        self::$mainOwner = null;

        self::$harvested[$testId] = Assert::getCount();
    }

    /** The static counter was reset outside a test boundary: previous marks no longer compare. */
    public static function counterReset(): void
    {
        self::$current = null;
        self::$mark    = 0;
    }

    public static function ownFor(string $testId): int
    {
        return self::$own[$testId] ?? 0;
    }

    public static function harvestedFor(string $testId): int
    {
        return self::$harvested[$testId] ?? 0;
    }

    public static function observedCoroutineFor(string $testId): bool
    {
        return isset(self::$observedCoroutines[$testId]);
    }

    /**
     * Installs namespace-local sleep()/usleep() shims for the given test class, making the raw
     * sleep() calls of an automatic-approach test observable: PHP resolves an unqualified
     * function call to the current namespace first (at call time), so a shim defined before the
     * first such call in that namespace shadows the global function -- and brackets the same
     * coroutine sleep the Swoole hook would have performed with an observation point on each
     * side. A class in the global namespace cannot be shimmed (there is nothing to shadow); its
     * post-yield assertions stay unattributed, like any other unobserved yield. A namespace that
     * already defines its own sleep() or usleep() is left alone.
     */
    public static function installShims(string $className): void
    {
        if (!self::$enabled) {
            return;
        }

        $pos = strrpos($className, '\\');
        if ($pos === false) {
            return;
        }

        $namespace = substr($className, 0, $pos);
        if (isset(self::$shimmed[$namespace])) {
            return;
        }
        self::$shimmed[$namespace] = true;

        if (function_exists($namespace . '\sleep') || function_exists($namespace . '\usleep')) {
            return;
        }

        eval(sprintf(
            'namespace %s;'
            . ' function sleep(int $seconds): int { return \Deminy\Counit\Attribution::sleepShim($seconds); }'
            . ' function usleep(int $microseconds): void { \Deminy\Counit\Attribution::usleepShim($microseconds); }',
            $namespace
        ));
    }

    /**
     * Shim target; behaves like the (Swoole-hooked) global sleep().
     */
    public static function sleepShim(int $seconds): int
    {
        if ($seconds > 0) {
            self::suspended();
            Coroutine::sleep($seconds);
            self::resumed();
        }

        return 0;
    }

    /**
     * Shim target; behaves like the (Swoole-hooked) global usleep(). Swoole cannot sleep for less
     * than a millisecond, so shorter durations are rounded up to it.
     */
    public static function usleepShim(int $microseconds): void
    {
        if ($microseconds > 0) {
            self::suspended();
            Coroutine::sleep(max(0.001, $microseconds / 1_000_000));
            self::resumed();
        }
    }

    /**
     * Called at every point where the CURRENT coroutine releases the counter. If it is not the
     * declared owner at that moment, the code it just executed ran unattributed: the coroutine
     * was resumed without passing an observation point, so its own tally cannot be trusted.
     */
    private static function verifyHeldCounter(): void
    {
        $owner = self::$cidOwner[self::currentCoroutineId()] ?? null;

        if ($owner !== null && self::$current !== $owner) {
            self::$unattributed[$owner] = true;
        }
    }

    /**
     * Observation point: attribute the counter increments since the last one to their owner, then
     * hand the counter to $owner (null: nobody -- increments until the next point are discarded,
     * never guessed at).
     */
    private static function switchTo(?string $owner): void
    {
        $now = Assert::getCount();

        if (self::$current !== null) {
            // The max() guards against a counter reset between two observation points. PHPUnit
            // resets the counter at each test's start, which happens while no owner is current
            // (the previous test's Finished event released the counter), so this is belt and
            // braces -- but a negative delta must never shrink an owner's tally.
            self::$own[self::$current] = (self::$own[self::$current] ?? 0) + max(0, $now - self::$mark);
        }

        self::$mark    = $now;
        self::$current = $owner;
    }

    /**
     * The ID of the coroutine the current code runs in, or -1 when not inside one. The is_int()
     * check exists for PHPStan only: swoole/ide-helper types getCid() as mixed.
     */
    private static function currentCoroutineId(): int
    {
        $cid = Coroutine::getCid();

        return is_int($cid) ? $cid : -1;
    }
}

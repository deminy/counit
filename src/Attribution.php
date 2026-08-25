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
 * startTest()/endTest() listener callbacks, and the return from Coroutine::create(). The
 * attribution is exact when every resume of every coroutine happens at such a point; a yield
 * counit cannot observe (hooked network IO, a fully-qualified \sleep() call, a test class in the
 * global namespace) makes the owning test's own number an undercount -- but never inflates
 * another test's, because a segment with no declared owner is attributed to nobody.
 *
 * Disabled -- with Counit::correctedAssertionCountFor() falling back to the credit/late
 * arithmetic -- when Swoole's preemptive scheduler is turned on: it switches coroutines without a
 * yield, which breaks the no-switch-between-observation-points premise.
 *
 * Tests are keyed by spl_object_id() of their TestCase object; AssertionCountListener keeps every
 * one of those objects alive for the whole run, so an ID is never recycled while in use.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
class Attribution
{
    /**
     * Set by CounitExtension when the run is coroutine-friendly and the model's premise holds.
     *
     * @var bool
     */
    public static $enabled = false;

    /**
     * Per test (keyed by spl_object_id()): the static-counter assertions attributed to it.
     *
     * @var array<int, int>
     */
    private static $own = [];

    /**
     * Per test (keyed the same way): the counter window PHPUnit harvested into it -- the counter's
     * value when its endTest() fired. PHPUnit resets the counter before each test starts and adds
     * its value to the test right before endTest(), so that value IS the harvested window.
     *
     * @var array<int, int>
     */
    private static $harvested = [];

    /**
     * Per test (keyed the same way): the counter's value when PHPUnit started the test, i.e. the
     * bottom of the window it later harvests into it. Normally 0 -- PHPUnit resets the counter
     * right before it starts a test -- but not for a process-isolated test: PHPUnit runs it
     * through a different path that neither resets the counter nor harvests a window at all, so
     * whatever the counter happens to hold there must not be mistaken for that test's window.
     *
     * @var array<int, int>
     */
    private static $windowStart = [];

    /**
     * Tests counit ran -- and therefore observed -- a coroutine for.
     *
     * @var array<int, bool>
     */
    private static $observedCoroutines = [];

    /**
     * @var array<int, int> coroutine ID => the key of the test owning that coroutine
     */
    private static $cidOwner = [];

    /**
     * The test PHPUnit is currently running on the main coroutine.
     *
     * @var int|null
     */
    private static $mainOwner;

    /**
     * Owner of the segment since the last observation point (null: nobody claims it).
     *
     * @var int|null
     */
    private static $current;

    /**
     * The static counter's value at the last observation point.
     *
     * @var int
     */
    private static $mark = 0;

    /**
     * @var array<string, bool> namespaces a sleep()/usleep() shim was installed in (or found
     *                          occupied and left alone)
     */
    private static $shimmed = [];

    /**
     * A test coroutine created by Counit::create() starts running: register its owner and claim
     * the counter for it.
     *
     * @param int|null $key
     */
    public static function coroutineStarted($key): void
    {
        if (!self::$enabled) {
            return;
        }

        if ($key !== null) {
            $cid = self::currentCoroutineId();
            if ($cid > 0) {
                self::$cidOwner[$cid] = $key;
            }
            self::$observedCoroutines[$key] = true;
        }

        self::switchTo($key);
    }

    /**
     * The coroutine's callable has finished: claim the tail segment and release the counter.
     */
    public static function coroutineFinished(): void
    {
        if (!self::$enabled) {
            return;
        }

        self::switchTo(null);
        unset(self::$cidOwner[self::currentCoroutineId()]);
    }

    /**
     * The current coroutine is about to yield: claim its segment and release the counter.
     */
    public static function suspended(): void
    {
        if (!self::$enabled) {
            return;
        }

        self::switchTo(null);
    }

    /**
     * The current coroutine has just been resumed: re-claim the counter for its owner.
     */
    public static function resumed(): void
    {
        if (!self::$enabled) {
            return;
        }

        $cid = self::currentCoroutineId();
        self::switchTo(isset(self::$cidOwner[$cid]) ? self::$cidOwner[$cid] : self::$mainOwner);
    }

    /**
     * PHPUnit starts running $key (its TestListener::startTest(), which fires right after PHPUnit
     * reset the counter and before setUp()).
     */
    public static function testStarting(int $key): void
    {
        if (!self::$enabled) {
            return;
        }

        self::$windowStart[$key] = Assert::getCount();

        self::claimMain($key);
    }

    /**
     * PHPUnit is running $key on the main coroutine. Called from testStarting(), and --
     * defensively -- from Counit::create(), which covers the one test whose startTest() happened
     * before counit's listener could be registered: at that point nothing has yielded yet, so the
     * counter's whole current value belongs to that test and is claimed retroactively.
     */
    public static function claimMain(int $key): void
    {
        if (!self::$enabled || (self::$mainOwner === $key)) {
            return;
        }

        if (self::$current !== null) {
            self::switchTo(null);
        }

        $now = Assert::getCount();
        // The max() guards against the counter having been reset since the last observation
        // point: PHPUnit resets it before each test starts, while no owner is current.
        self::$own[$key] = (isset(self::$own[$key]) ? self::$own[$key] : 0) + max(0, $now - self::$mark);

        self::$mark      = $now;
        self::$mainOwner = $key;
        self::$current   = $key;
    }

    /**
     * PHPUnit reported the test (endTest()): capture the window it harvested into the test and
     * release the counter.
     */
    public static function testFinished(int $key): void
    {
        if (!self::$enabled) {
            return;
        }

        self::switchTo(null);
        self::$mainOwner = null;

        // The window PHPUnit harvests into the test is what the counter gained while the test
        // ran; see $windowStart for why the bottom of that window is not always zero.
        $start                 = isset(self::$windowStart[$key]) ? self::$windowStart[$key] : 0;
        self::$harvested[$key] = max(0, Assert::getCount() - $start);
    }

    /**
     * The static counter was reset outside a test boundary: previous marks no longer compare.
     */
    public static function counterReset(): void
    {
        self::$current = null;
        self::$mark    = 0;
    }

    public static function ownFor(int $key): int
    {
        return isset(self::$own[$key]) ? self::$own[$key] : 0;
    }

    public static function harvestedFor(int $key): int
    {
        return isset(self::$harvested[$key]) ? self::$harvested[$key] : 0;
    }

    public static function harvestRecorded(int $key): bool
    {
        return array_key_exists($key, self::$harvested);
    }

    public static function observedCoroutineFor(int $key): bool
    {
        return isset(self::$observedCoroutines[$key]);
    }

    /**
     * Installs namespace-local sleep()/usleep() shims for the given test class, making the raw
     * sleep() calls of a test observable: PHP resolves an unqualified function call to the
     * current namespace first (at call time), so a shim defined before the first such call in
     * that namespace shadows the global function -- and brackets the same coroutine sleep the
     * Swoole hook would have performed with an observation point on each side. A class in the
     * global namespace cannot be shimmed (there is nothing to shadow); its post-yield assertions
     * stay unattributed, like any other unobserved yield. A namespace that already defines its
     * own sleep() or usleep() is left alone.
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
            Coroutine::sleep(max(0.001, $microseconds / 1000000));
            self::resumed();
        }
    }

    /**
     * Observation point: attribute the counter increments since the last one to their owner, then
     * hand the counter to $owner (null: nobody -- increments until the next point are discarded,
     * never guessed at).
     *
     * @param int|null $owner
     */
    private static function switchTo($owner): void
    {
        $now = Assert::getCount();

        if (self::$current !== null) {
            // The max() guards against a counter reset between two observation points; a negative
            // delta must never shrink an owner's tally.
            self::$own[self::$current] = (isset(self::$own[self::$current]) ? self::$own[self::$current] : 0) + max(0, $now - self::$mark);
        }

        self::$mark    = $now;
        self::$current = $owner;
    }

    /**
     * The ID of the coroutine the current code runs in, or -1 when not inside one.
     */
    private static function currentCoroutineId(): int
    {
        $cid = Coroutine::getCid();

        return is_int($cid) ? $cid : -1;
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Whether PHPUnit brackets a test with a global-state snapshot -- the input to counit's
 * barrier-and-join decision for @backupGlobals / @backupStaticAttributes (and the matching
 * backupGlobals/backupStaticAttributes XML configuration).
 *
 * PHPUnit 8/9 take the snapshot as (nearly) the first statement of runBare() and restore as
 * (nearly) the last. On this branch the WHOLE parent::runBare() runs inside the test's coroutine
 * for the automatic approach, so -- unlike on the 1.x branch -- a backed-up test's own isolation
 * was already correct: snapshot and restore bracket the real body. What was broken is the
 * collateral: the snapshot window spans the test's entire concurrent lifetime, during which every
 * other in-flight test's coroutine runs -- and the restore then reverts THEIR global writes
 * (sebastian/global-state's Restorer unsets every key absent from the snapshot). The manual
 * approach was additionally broken the 1.x way: its runBare() is PHPUnit's own, running on the
 * main coroutine, so the restore fired at the body's first yield -- reverting the body's own
 * pre-yield writes mid-test and letting post-yield writes leak. A @backupStaticAttributes
 * snapshot also captures counit's own static bookkeeping (counit's classes are user-defined, so
 * not on PHPUnit's exclude list); with the exclusive window below, that rewind is self-healing --
 * everything counit mutates inside the window belongs to the joined test itself, and the next
 * Attribution observation point re-attributes the whole window to it in one segment.
 *
 * The fix therefore needs two pieces, both keyed off this class:
 *  - a BARRIER: before the snapshot is taken, every in-flight test coroutine is drained. The one
 *    seam early enough for both approaches is AssertionCountListener::startTest() -- fired by
 *    TestResult::run() after the static assertion counter was reset (so drained assertions land
 *    in an open window: mis-attributed but never wiped, the bucket the end-of-run total
 *    correction already handles) and before runBare() takes the snapshot. The listener is
 *    attached lazily from the first Counit::create()/createAndJoin() call, which is sufficient:
 *    a pending coroutine can only exist if one of those already ran.
 *  - a JOIN: the test's coroutine runs to completion before its runBare() returns (the @depends
 *    producer mechanism), so PHPUnit's own snapshot/restore brackets the real body with nothing
 *    else in flight. TestCase::runBare() joins automatic-approach tests; Counit::create() joins
 *    manual-approach and nested calls, skipping the up-front assertion credit (the body completes
 *    before PHPUnit reads the count, so a backed-up test performing no assertions stays risky,
 *    exactly as in blocking mode).
 * Together they give the exclusive window blocking PHPUnit gets for free; --strict-global-state
 * becomes correct as a side effect (both of its comparison snapshots now bracket that window).
 *
 * Detection is trivial on this branch: TestBuilder resolved the @backupGlobals /
 * @backupStaticAttributes annotations and the configuration defaults into the test's protected
 * $backupGlobals / $backupStaticAttributes properties at construction time, long before any
 * counit seam runs -- they only need reading, via reflection (this code must serve the manual
 * approach's plain PHPUnit test objects too, where subclass access does not apply). If the
 * properties ever change shape, the probe reports "not backed up", degrading to counit's
 * pre-existing behavior rather than breaking a run.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class GlobalState
{
    /**
     * @var \ReflectionProperty|null
     */
    private static $backupGlobals;

    /**
     * @var \ReflectionProperty|null
     */
    private static $backupStaticAttributes;

    /**
     * @var bool
     */
    private static $resolved = false;

    /**
     * Whether PHPUnit brackets this test with a global-state snapshot -- i.e. whether the barrier
     * and the join apply. Mirrors the guard of TestCase::snapshotGlobalState(): the snapshot is
     * taken when either resolved flag is true (a process-isolated test is skipped there too, but
     * such a test never reaches counit's coroutine seams in the first place).
     */
    public static function isBackedUp(BaseTestCase $test): bool
    {
        if (!self::$resolved) {
            self::resolve();
        }

        try {
            return (self::$backupGlobals !== null && self::$backupGlobals->getValue($test) === true)
                || (self::$backupStaticAttributes !== null && self::$backupStaticAttributes->getValue($test) === true);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function resolve(): void
    {
        self::$resolved = true;

        foreach (['backupGlobals', 'backupStaticAttributes'] as $name) {
            try {
                if (!property_exists(BaseTestCase::class, $name)) {
                    continue;
                }

                $property = new \ReflectionProperty(BaseTestCase::class, $name);
                if (PHP_VERSION_ID < 80100) {
                    // A no-op since PHP 8.1 (and deprecated since 8.5), but required on the PHP
                    // 7.2 through 8.0 part of this branch's supported range.
                    $property->setAccessible(true);
                }

                if ($name === 'backupGlobals') {
                    self::$backupGlobals = $property;
                } else {
                    self::$backupStaticAttributes = $property;
                }
            } catch (\Throwable $e) {
                // Leave the probe null; isBackedUp() then reports false for that flag.
            }
        }
    }
}

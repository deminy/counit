<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the backupStaticAttributes annotation rewinding counit's OWN static state: counit's
 * classes are user-defined and not on PHPUnit's exclude list, so a backed-up test's snapshot
 * captures counit's bookkeeping (the Attribution ledgers included) and the restore rewinds it.
 * With the exclusive window (drain barrier + join, see GlobalState) that rewind is self-healing
 * -- everything counit mutates inside the window belongs to the joined test itself -- and this
 * file pins it: the run's summary must stay exact, in both modes.
 *
 * The arithmetic pinned by the final test, identical to blocking mode: testFirst's tearDown()
 * brings the counter to 1; the mid-class backed-up test snapshots that 1, its own tearDown()
 * makes 2, and the restore rewinds to 1 (the counter is itself a backed-up static -- in blocking
 * mode too); testThird's tearDown() makes 2. The final test is depends-anchored on testThird so
 * the joined producer's tearDown() has run before the assertion -- deterministically, in both
 * modes. Run by the compatibility workflow, which asserts the exact blocking-mode summary with
 * and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class BackupStaticsTest extends TestCase
{
    /**
     * @var int
     */
    public static $tearDowns = 0;

    protected function tearDown(): void
    {
        self::$tearDowns++;
    }

    public function testFirst(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    /**
     * @backupStaticAttributes enabled
     */
    public function testBackedUpMidClass(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    public function testThird(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    /**
     * @depends testThird
     */
    public function testTearDownsRanExactlyOncePerTest(): void
    {
        self::assertSame(2, self::$tearDowns);
    }
}

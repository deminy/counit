<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\BackupStaticProperties;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Regression guard for #[BackupStaticProperties] rewinding counit's OWN static state: counit's
 * classes are user-defined and not on PHPUnit's exclude list, so a backed-up test's snapshot
 * captures the after-test hook takeover, and the restore replaces it with clones -- which used to
 * make tearDown() run twice (natively and through the relocated replay) for every later test of
 * the class, and skew the run's assertion total. TestCase::repairAfterStaticRestore() now puts
 * the takeover back on real objects after the restore.
 *
 * The arithmetic pinned by the final test, identical to blocking mode: testFirst's tearDown()
 * brings the counter to 1; the mid-class backed-up test snapshots that 1, its own tearDown()
 * makes 2, and the restore rewinds to 1 (the counter is itself a backed-up static -- in blocking
 * mode too); testThird's tearDown() makes 2. The final test is #[Depends]-anchored on testThird
 * so the joined producer's hooks have run before the assertion -- deterministically, in both
 * modes. A double-run tearDown() would make it 3 and fail. Run by the compatibility workflow,
 * which asserts the exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class BackupStaticHooksTest extends TestCase
{
    public static int $tearDowns = 0;

    protected function tearDown(): void
    {
        self::$tearDowns++;
    }

    public function testFirst(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    #[BackupStaticProperties(true)]
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

    #[Depends('testThird')]
    public function testTearDownsRanExactlyOncePerTest(): void
    {
        self::assertSame(2, self::$tearDowns);
    }
}

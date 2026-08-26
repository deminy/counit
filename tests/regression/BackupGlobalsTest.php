<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\BackupGlobals;

/**
 * Regression guard for #[BackupGlobals] in the automatic approach. PHPUnit snapshots the globals
 * at the top of runBare() and restores at its bottom; under Swoole that restore used to fire at
 * the body's first yield -- reverting the test's own pre-yield write while the body still needed
 * it, and letting its post-yield write escape the restore and leak to later tests. With the test
 * joined (and the pre-snapshot drain in place, see GlobalState), the snapshot/restore brackets
 * the real body: the pre-yield write survives its own yield, and the witness sees neither key.
 * Run by the compatibility workflow, which asserts the exact blocking-mode summary -- with and
 * without Swoole, and again with a run-wide --globals-backup and with --strict-global-state.
 *
 * @internal
 * @coversNothing
 */
class BackupGlobalsTest extends TestCase
{
    #[BackupGlobals(true)]
    public function testBackedUpAcrossYield(): void
    {
        $GLOBALS['counit_bg_pre'] = 'pre-yield';

        sleep(1);

        self::assertSame('pre-yield', $GLOBALS['counit_bg_pre']);

        $GLOBALS['counit_bg_post'] = 'post-yield';
        self::assertArrayHasKey('counit_bg_post', $GLOBALS);
    }

    public function testWitnessSeesNoLeak(): void
    {
        sleep(1);

        self::assertArrayNotHasKey('counit_bg_pre', $GLOBALS);
        self::assertArrayNotHasKey('counit_bg_post', $GLOBALS);
    }
}

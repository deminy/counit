<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the backupGlobals annotation in the automatic approach. On this branch the whole
 * runBare() -- snapshot, body, restore -- runs inside the test's coroutine, so the backed-up
 * test's own isolation was already correct; what was broken is the collateral: the snapshot
 * window spanned the test's entire concurrent lifetime, and the restore reverted every
 * overlapping test's global writes. With the pre-snapshot drain barrier and the join (see
 * GlobalState), the window is exclusive: the pre-yield write survives its own yield, and the
 * witness sees neither key. Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary -- with and without Swoole, and again with a run-wide --globals-backup
 * and with --strict-global-state.
 *
 * @internal
 * @coversNothing
 */
class BackupGlobalsTest extends TestCase
{
    /**
     * @backupGlobals enabled
     */
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

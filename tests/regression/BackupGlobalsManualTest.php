<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the backupGlobals annotation in the manual approach: this runBare() is PHPUnit's own,
 * running on the main coroutine, so the restore used to fire at the body's first yield --
 * reverting the body's own pre-yield write mid-test and letting the post-yield write leak.
 * Counit::create() now joins the coroutine of a backed-up test (applying no assertion credit --
 * the joined body's real assertions are counted natively), so the restore follows the real body.
 * See BackupGlobalsTest for the automatic-approach counterpart and GlobalState for the design.
 * Run by the compatibility workflow, which asserts the exact blocking-mode summary with and
 * without Swoole.
 *
 * @internal
 * @coversNothing
 */
class BackupGlobalsManualTest extends TestCase
{
    /**
     * @backupGlobals enabled
     */
    public function testBackedUpAcrossYield(): void
    {
        Counit::create(function (): void {
            $GLOBALS['counit_bgm_pre'] = 'pre-yield';

            Counit::sleep(1);

            self::assertSame('pre-yield', $GLOBALS['counit_bgm_pre']);

            $GLOBALS['counit_bgm_post'] = 'post-yield';
        }, 1);
    }

    public function testWitnessSeesNoLeak(): void
    {
        Counit::create(function (): void {
            Counit::sleep(1);

            self::assertArrayNotHasKey('counit_bgm_pre', $GLOBALS);
            self::assertArrayNotHasKey('counit_bgm_post', $GLOBALS);
        }, 1);
    }
}

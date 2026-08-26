<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for #[BackupGlobals] in the manual approach: Counit::create() joins the
 * coroutine of a backed-up test at its first yield (applying no assertion credit -- the joined
 * body's real assertions are counted natively), so PHPUnit's snapshot/restore brackets the real
 * body. See BackupGlobalsTest for the automatic-approach counterpart and GlobalState for the
 * design. Run by the compatibility workflow, which asserts the exact blocking-mode summary with
 * and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class BackupGlobalsManualTest extends TestCase
{
    #[BackupGlobals(true)]
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

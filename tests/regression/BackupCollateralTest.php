<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\BackupGlobals;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Regression guard for the collateral-damage half of the global-state fix: a backed-up test's
 * restore reverts a snapshot taken at its start, so a test still in flight during that window
 * used to have its legitimate global write silently unset (the snapshot predates it). Here the
 * backed-up test is also a #[Depends] producer -- a join path, where the window used to span the
 * producer's whole duration with the bystander running inside it. The pre-snapshot drain (see
 * GlobalState) now waits for the bystander to finish completely before the snapshot is taken, so
 * its write and cleanup happen entirely outside the window. Run by the compatibility workflow,
 * which asserts the exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class BackupCollateralTest extends TestCase
{
    public function testBystander(): void
    {
        sleep(1);
        $GLOBALS['counit_bystander'] = 'mine';

        sleep(2);

        self::assertSame('mine', $GLOBALS['counit_bystander']);
        unset($GLOBALS['counit_bystander']);
    }

    #[BackupGlobals(true)]
    public function testBackedUpProducer(): string
    {
        sleep(1);
        self::assertTrue(true);

        return 'value';
    }

    #[Depends('testBackedUpProducer')]
    public function testConsumer(string $value): void
    {
        self::assertSame('value', $value);
    }
}

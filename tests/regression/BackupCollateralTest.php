<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the collateral-damage half of the global-state fix: a backed-up test's
 * restore reverts a snapshot taken at its start, so a test still in flight during that window
 * used to have its legitimate global write silently unset (the snapshot predates it). On this
 * branch that applied to EVERY backed-up automatic-approach test -- the snapshot window spans
 * the test's whole concurrent lifetime. The pre-snapshot drain barrier (see GlobalState) now
 * waits for the bystander to finish completely before the snapshot is taken, so its write and
 * cleanup happen entirely outside the window. The backed-up test doubles as a dependency producer
 * to pin the interaction of the two join reasons. Run by the compatibility workflow, which
 * asserts the exact blocking-mode summary with and without Swoole.
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

    /**
     * @backupGlobals enabled
     */
    public function testBackedUpProducer(): string
    {
        sleep(1);
        self::assertTrue(true);

        return 'value';
    }

    /**
     * @depends testBackedUpProducer
     */
    public function testConsumer(string $value): void
    {
        self::assertSame('value', $value);
    }
}

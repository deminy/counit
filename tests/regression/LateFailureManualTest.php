<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * The manual-approach companion to LateFailureTest: a body failure after the first yield, and a
 * Counit::defer() cleanup that throws after the test's report (in blocking mode the cleanup
 * runs synchronously inside the body, so its Throwable errors the test natively -- the replayed
 * Test\Errored now converges the Swoole run to that exact shape). Run by the compatibility
 * workflow with mode-identical assertions.
 *
 * @internal
 * @coversNothing
 */
class LateFailureManualTest extends TestCase
{
    public function testBodyFailsAfterYield(): void
    {
        Counit::create(function (): void {
            self::assertTrue(true);
            Counit::sleep(1);
            self::fail('deliberate manual-approach failure after the yield');
        }, 1);
    }

    public function testCleanupThrowsAfterYield(): void
    {
        Counit::create(function (): void {
            Counit::defer(static function (): void {
                throw new \RuntimeException('deliberate cleanup error after the yield');
            });
            self::assertTrue(true);
            Counit::sleep(1);
        }, 1);
    }
}

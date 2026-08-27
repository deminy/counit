<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * The manual-approach companion to LateFailureTest: a body failure after the first yield inside
 * a Counit::create() callable. In blocking mode the callable runs synchronously inside the test
 * method, so the failure is native; under Swoole it used to land in the deferred STDERR block
 * with a forced exit code 1 -- the replayed addFailure() (see CounitExtension) now converges
 * both modes to blocking's exact summary and exit code. Run by the compatibility workflow with
 * mode-identical assertions.
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

    public function testUnaffectedSibling(): void
    {
        Counit::create(function (): void {
            self::assertTrue(true);
            Counit::sleep(1);
        }, 1);
    }
}

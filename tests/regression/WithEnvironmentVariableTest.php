<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\WithEnvironmentVariable;

/**
 * Regression guard for #[WithEnvironmentVariable]: PHPUnit sets the variable at the top of
 * runBare() and restores it at the bottom; under Swoole the restore used to fire at the body's
 * first yield, so the injected value vanished while the body still needed it (getenv() returned
 * false mid-test). With the test joined (and the pre-snapshot drain in place, see GlobalState),
 * the variable spans the real body and is restored before the next test. Run by the
 * compatibility workflow, which asserts the exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class WithEnvironmentVariableTest extends TestCase
{
    #[WithEnvironmentVariable('COUNIT_WEV', 'injected')]
    public function testEnvironmentVariableAcrossYield(): void
    {
        self::assertSame('injected', getenv('COUNIT_WEV'));
        self::assertSame('injected', $_ENV['COUNIT_WEV'] ?? '<unset>');

        sleep(1);

        self::assertSame('injected', getenv('COUNIT_WEV'));
    }

    public function testEnvironmentVariableRestored(): void
    {
        sleep(1);

        self::assertFalse(getenv('COUNIT_WEV'));
    }
}

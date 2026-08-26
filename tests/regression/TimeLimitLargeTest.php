<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\Large;

/**
 * Companion to TimeLimitAutomaticTest: a #[Large]-sized class (the size attributes only target
 * classes) whose test sleeps past the run's 1-second default limit but stays comfortably under
 * the 60-second limit PHPUnit selects for large tests -- it must pass in both modes, proving the
 * per-size timeout selection is PHPUnit's own under counit too.
 *
 * @internal
 * @coversNothing
 */
#[Large]
class TimeLimitLargeTest extends TestCase
{
    public function testLargeSizeGetsItsOwnLimit(): void
    {
        sleep(3);

        self::assertTrue(true);
    }
}

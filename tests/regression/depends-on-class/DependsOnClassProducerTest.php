<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * The producer class for the whole-class dependency case in DependsOnClassUserTest (this
 * directory is PHPUnit-9-only; see that file). Every test of a depended-on class is joined --
 * the class-passed verdict needs all of them finished -- and one member deliberately fails
 * after a yield, so the class must NOT count as passed.
 *
 * @internal
 * @coversNothing
 */
class DependsOnClassProducerTest extends TestCase
{
    public function testMemberPasses(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    public function testMemberFailsAfterYield(): void
    {
        sleep(1);
        self::fail('deliberate class-member failure after a yield');
    }
}

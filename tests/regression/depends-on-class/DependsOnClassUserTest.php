<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the whole-class dependency form (a "depends" annotation targeting
 * "Class::class"). This directory is kept out of the gated compatibility suite and run by the
 * compatibility workflow only where PHPUnit supports class-level dependency targets -- i.e.
 * PHPUnit >= 9.3, where ExecutionOrderDependency exists; older versions (PHPUnit 8 and 9.0-9.2)
 * warn that "Class::class" does not exist, in blocking mode too, an upstream gap rather than a
 * counit one. One member of the depended-on class fails after a
 * yield, so this class-level dependent must be skipped -- which requires every test of that
 * class to have been joined (see DependencyMap), identically with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class DependsOnClassUserTest extends TestCase
{
    /**
     * Must never run: a member of the depended-on class failed, so the class never counts as
     * passed and PHPUnit skips this test -- in both modes.
     *
     * @depends Deminy\Counit\Tests\DependsOnClassProducerTest::class
     */
    public function testSkippedBecauseTheClassDidNotFullyPass(): void
    {
        self::fail('the dependent of a not-fully-passed class must be skipped, not run');
    }
}

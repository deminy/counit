<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * A cross-class dependency (a "depends" annotation targeting "Class::method"): must deliver
 * the other class's real return value, exercising the run-wide reverse-dependency graph (see
 * DependencyMap) rather
 * than same-class metadata. The whole-class form (targeting "Class::class") is deliberately
 * NOT covered here: upstream, only PHPUnit >= 9.3 supports it (older versions warn that the
 * target does not exist, in blocking mode too), so it cannot live in this exact-summary-gated
 * suite -- see the
 * version-gated regression tests under tests/regression/depends-on-class/ instead.
 *
 * @internal
 * @coversNothing
 */
class DependsExternalUserTest extends TestCase
{
    /**
     * @depends Deminy\Counit\Tests\DependsExternalProducerTest::testExternalProducer
     */
    public function testConsumesExternalProducer(string $value): void
    {
        self::assertSame('external-value', $value);
    }
}

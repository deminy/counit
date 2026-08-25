<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\DependsExternal;
use PHPUnit\Framework\Attributes\DependsOnClass;

/**
 * Cross-class dependencies: #[DependsExternal] must deliver the other class's real return value,
 * and #[DependsOnClass] must gate on the whole class having genuinely passed. Both exercise the
 * run-wide reverse-dependency graph (see DependencyMap) rather than same-class metadata.
 *
 * @internal
 * @coversNothing
 */
class DependsExternalUserTest extends TestCase
{
    #[DependsExternal(DependsExternalProducerTest::class, 'testExternalProducer')]
    public function testConsumesExternalProducer(string $value): void
    {
        self::assertSame('external-value', $value);
    }

    #[DependsOnClass(DependsExternalProducerTest::class)]
    public function testRunsOnceTheWholeClassPassed(): void
    {
        self::assertTrue(true);
    }
}

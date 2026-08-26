<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Dependencies ("depends" annotation) in the manual approach. Counit::create() consults the
 * reverse-dependency graph itself: called directly from a test method something depends on,
 * it joins the coroutine
 * instead of merely starting it, so the idiomatic by-ref shape below delivers the real value
 * (and the real verdict) with no test changes. A producer can also call Counit::createAndJoin()
 * directly and return its result as-is.
 *
 * @internal
 * @coversNothing
 */
class DependsManualCompatibilityTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    public function testManualProducer(): array
    {
        $value = null;
        Counit::create(function () use (&$value): void {
            Counit::sleep(1);
            self::assertTrue(true);
            $value = 'manual-value';
        });

        return ['v' => $value];
    }

    /**
     * @depends testManualProducer
     *
     * @param array<string, mixed> $data
     */
    public function testManualConsumer(array $data): void
    {
        self::assertSame('manual-value', $data['v']);
    }

    public function testManualProducerViaCreateAndJoin(): string
    {
        $joined = Counit::createAndJoin(function (): string {
            Counit::sleep(1);
            self::assertTrue(true);

            return 'joined-value';
        });
        \assert(\is_string($joined)); // createAndJoin() returns the callable's value verbatim.

        return $joined;
    }

    /**
     * @depends testManualProducerViaCreateAndJoin
     */
    public function testManualConsumerViaCreateAndJoin(string $value): void
    {
        self::assertSame('joined-value', $value);
    }
}

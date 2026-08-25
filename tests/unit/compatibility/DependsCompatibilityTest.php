<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\DependsUsingDeepClone;
use PHPUnit\Framework\Attributes\DependsUsingShallowClone;

/**
 * Same-class #[Depends] compatibility in the automatic approach: dependent tests must receive
 * the producer's real return value (not NULL), through chains and both clone variants alike.
 * counit joins a producer's coroutine before PHPUnit records its result (see DependencyMap and
 * TestCase::invokeTestMethod()), so these behave exactly as under blocking PHPUnit -- whether
 * the producer yields or not.
 *
 * @internal
 * @coversNothing
 */
class DependsCompatibilityTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    public function testProducerWithoutYield(): array
    {
        self::assertTrue(true);

        return ['key' => 'sync-value'];
    }

    /**
     * @param array<string, string> $data
     */
    #[Depends('testProducerWithoutYield')]
    public function testConsumesProducerWithoutYield(array $data): void
    {
        self::assertSame('sync-value', $data['key']);
    }

    public function testProducerReturningAfterYield(): string
    {
        sleep(1);
        self::assertTrue(true);

        return 'slow-value';
    }

    #[Depends('testProducerReturningAfterYield')]
    public function testConsumesProducerReturningAfterYield(string $value): void
    {
        self::assertSame('slow-value', $value);
    }

    public function testChainStart(): string
    {
        self::assertTrue(true);

        return 'A';
    }

    #[Depends('testChainStart')]
    public function testChainMiddle(string $value): string
    {
        self::assertSame('A', $value);
        sleep(1);

        return $value . 'B';
    }

    #[Depends('testChainMiddle')]
    public function testChainEnd(string $value): void
    {
        self::assertSame('AB', $value);
    }

    /**
     * @return \ArrayObject<string, int>
     */
    public function testProducerReturningAnObject(): \ArrayObject
    {
        self::assertTrue(true);

        return new \ArrayObject(['n' => 1]);
    }

    /**
     * @param \ArrayObject<string, int> $object
     */
    #[DependsUsingDeepClone('testProducerReturningAnObject')]
    public function testDeepCloneReceivesACopy(\ArrayObject $object): void
    {
        self::assertSame(['n' => 1], $object->getArrayCopy());
        $object['n'] = 2; // Mutating the copy must not leak into the shallow-clone test below.
    }

    /**
     * @param \ArrayObject<string, int> $object
     */
    #[DependsUsingShallowClone('testProducerReturningAnObject')]
    public function testShallowCloneReceivesACopy(\ArrayObject $object): void
    {
        self::assertSame(['n' => 1], $object->getArrayCopy());
    }
}

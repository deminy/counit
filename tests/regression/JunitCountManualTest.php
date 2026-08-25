<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Like JunitCountTest, but for the manual approach, whose credit is applied *before* the
 * coroutine spawns (the automatic approach credits after create() returns) and whose test bodies
 * run partly on the main coroutine -- both paths must land on the same corrected numbers.
 *
 * @internal
 * @coversNothing
 */
class JunitCountManualTest extends TestCase
{
    /**
     * Four assertions: on the main coroutine before and after the create() call, and inside the
     * coroutine on both sides of the yield.
     */
    public function testImmediateBeforeAndAfterTheCoroutine(): void
    {
        self::assertTrue(true);
        Counit::create(function (): void {
            self::assertTrue(true);
            Counit::sleep(1);
            self::assertTrue(true);
        }, 1);
        self::assertTrue(true);
    }

    /**
     * Two assertions, both after the yield: one through the static counter, one counted directly
     * on the test object.
     */
    public function testOnlyInsideTheCoroutine(): void
    {
        Counit::create(function (): void {
            Counit::sleep(1);
            self::assertTrue(true);
            $this->addToAssertionCount(1);
        }, 1);
    }

    /**
     * Two assertions in a coroutine that never yields: the credit must not push the count to 3.
     */
    public function testFullySynchronousCoroutine(): void
    {
        Counit::create(function (): void {
            self::assertTrue(true);
            self::assertTrue(true);
        }, 1);
    }
}

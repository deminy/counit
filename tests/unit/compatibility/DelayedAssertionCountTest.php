<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * addToAssertionCount() writes to the test object directly, bypassing PHPUnit's static assertion
 * counter -- so a call made after the test's coroutine has yielded (from the body's tail, or from
 * tearDown(), which counit relocates into the coroutine) happens after PHPUnit already reported the
 * test's count, and used to vanish from the run's total. The late-count correction must include
 * them, exactly as blocking mode does.
 *
 * @internal
 * @coversNothing
 */
class DelayedAssertionCountTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        // Counted after the body, inside the coroutine -- one extra assertion for every test in
        // this class, same as under blocking PHPUnit.
        $this->addToAssertionCount(1);
        parent::tearDown();
    }

    /**
     * Three assertions: the assertTrue(), the post-yield addToAssertionCount(), and tearDown()'s.
     */
    public function testCountAddedAfterTheYield(): void
    {
        self::assertTrue(true);
        sleep(1);
        $this->addToAssertionCount(1);
    }

    /**
     * Two assertions: the assertTrue() and tearDown()'s.
     */
    public function testCountAddedFromTearDownOnly(): void
    {
        self::assertTrue(true);
        sleep(1);
    }
}

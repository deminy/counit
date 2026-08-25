<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Assertions a consumer counts from tearDown() -- which the automatic approach runs inside the
 * test's coroutine, i.e. after PHPUnit read the test's count -- must still show up in the run's
 * reported total, exactly as they do in blocking mode.
 *
 * @internal
 * @coversNothing
 */
class DelayedTearDownAssertionCountTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->addToAssertionCount(1);

        parent::tearDown();
    }

    /**
     * Two assertions: the assertTrue() below, and the one tearDown() counts.
     */
    public function testAssertionCountedFromTearDown(): void
    {
        self::assertTrue(true);

        sleep(1);
    }
}

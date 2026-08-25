<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * To test the tearDownCoroutine() hook: unlike tearDown() -- which PHPUnit invokes as soon as the
 * test body first yields on a sleep/IO call, possibly while the body is still running -- the hook
 * must observe a finished test body, in both coroutine and blocking mode.
 *
 * @internal
 * @coversNothing
 */
class TearDownCoroutineTest extends TestCase
{
    private ?string $fixture = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = 'ready';
    }

    /**
     * The body yields first, so under Swoole the cleanup runs while the coroutine is pending --
     * the exact path where tearDown() would have destroyed the fixture too early.
     */
    public function testFixtureSurvivesUntilBodyEnds(): void
    {
        sleep(1);
        self::assertSame('ready', $this->fixture, 'The fixture must still exist after the yield.');
    }

    /**
     * The body finishes without yielding, exercising the synchronous path of the hook.
     */
    public function testFixtureSurvivesWithoutYield(): void
    {
        self::assertSame('ready', $this->fixture, 'The fixture must exist while the body runs.');
    }

    /**
     * Order-sensitive cleanup: it destroys state the test body still reads after its yield. Placed
     * in tearDown() this would corrupt the still-running body under Swoole.
     */
    #[\Override]
    protected function tearDownCoroutine(): void
    {
        $this->fixture = null;
    }
}

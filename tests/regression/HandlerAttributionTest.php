<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression guard against MISATTRIBUTION: the leaking test registers its handler while the
 * innocent tests below are still inside their own snapshot windows (their setUp() yields), so
 * PHPUnit's compare/restore used to flag one of THEM for the leak while the actual leaker went
 * free -- under --fail-on-risky that failed the run naming the wrong test. With the handler
 * isolation, a suspended test's handlers are not on the shared stack at all, and the leak is
 * attributed to the coroutine that actually holds it. The workflow must therefore grep the
 * risky TEST NAME, not just the count -- the broken behavior has the same count with the wrong
 * name. Run by the compatibility workflow with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class HandlerAttributionTest extends TestCase
{
    protected function setUp(): void
    {
        if (str_starts_with($this->name(), 'testInnocent')) {
            usleep(300000);
        }
    }

    public function testALeaksAfterAShortYield(): void
    {
        usleep(200000);

        set_error_handler(static fn (): bool => false);

        self::assertTrue(true);
    }

    #[DataProvider('sets')]
    public function testInnocentBystander(int $i): void
    {
        usleep(1000);

        self::assertTrue(true);
    }

    /**
     * @return list<array{0: int}>
     */
    public static function sets(): array
    {
        return [[1], [2], [3]];
    }
}

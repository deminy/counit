<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression guard for a late skip in a DATA-PROVIDER test: the deferred verdict is stashed with
 * the test's event value object at deferral time, because matching ID strings after the fact
 * silently misses data-set tests (the "with data set #1" formats differ between surfaces) -- the
 * skip would then fall back to the STDERR notice instead of the summary. Run by the
 * compatibility workflow, which asserts the exact blocking-mode summary, identical with and
 * without Swoole.
 *
 * @internal
 * @coversNothing
 */
class LateSkipDataSetTest extends TestCase
{
    #[DataProvider('provideSkipCases')]
    public function testMaybeSkipsAfterYield(bool $skip): void
    {
        usleep(200000);

        if ($skip) {
            self::markTestSkipped('skipped after the first yield, with a data set');
        }

        self::assertTrue(true);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function provideSkipCases(): array
    {
        return [
            'passes' => [false],
            'skips'  => [true],
        ];
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a late skip in a DATA-PROVIDER test: the deferred verdict is stashed
 * with the TestCase object itself at deferral time -- each data set runs on its own object, so
 * any after-the-fact lookup by name would have to reconcile the "with data set" description
 * formats and could silently miss, dropping the skip back to the STDERR notice instead of the
 * summary. Run by the compatibility workflow, which asserts the exact blocking-mode summary,
 * identical with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class LateSkipDataSetTest extends TestCase
{
    /**
     * @dataProvider provideSkipCases
     */
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

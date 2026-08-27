<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

/**
 * Coverage subject for CoverageDrainTest: one method called before the tests' first yield, one
 * called only after it, one never called. The compatibility workflow points the coverage filter
 * at this directory and asserts the aggregate percentages are identical with and without Swoole
 * -- without the drain coverage window (see Deminy\Counit\Coverage), afterYield()'s lines vanish
 * from the report under Swoole.
 *
 * @internal
 */
final class CoverageSubject
{
    public static function beforeYield(int $n): int
    {
        $x = $n + 1;
        return $x * 2;
    }

    public static function afterYield(int $n): int
    {
        $x = $n + 3;
        $y = $x * 4;
        return $y - 1;
    }

    public static function neverCalled(): int
    {
        $a = 1;
        $b = 2;

        return $a + $b;
    }
}

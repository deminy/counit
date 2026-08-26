<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for output isolation under concurrency in the manual approach: noisy tests
 * echo before AND after a yield with no expectation of their own, while expecting tests run
 * concurrently with them. Each coroutine's output lives in its own Swoole buffer (see
 * OutputCapture), so the expecting tests must verify exactly their own bytes -- nothing from the
 * bystanders -- and the whole file must report the clean blocking-mode summary. The noisy tests
 * keep overlapping (only the expecting tests join), so this also pins the cost model. Run by the
 * compatibility workflow, which asserts the exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class OutputPollutionTest extends TestCase
{
    /**
     * @dataProvider noisy
     */
    public function testNoisyBystander(int $i): void
    {
        Counit::create(function () use ($i): void {
            echo "N{$i}-pre;";

            Counit::sleep(1);

            echo "N{$i}-post;";

            self::assertTrue(true);
        }, 1);
    }

    /**
     * @return array<array{int}>
     */
    public static function noisy(): array
    {
        return [[1], [2], [3]];
    }

    /**
     * @dataProvider expecting
     */
    public function testExpectsOwnOutputOnly(string $tag): void
    {
        Counit::create(function () use ($tag): void {
            $this->expectOutputString("E{$tag}-pre;E{$tag}-post;");

            echo "E{$tag}-pre;";

            Counit::sleep(1);

            echo "E{$tag}-post;";
        }, 1);
    }

    /**
     * @return array<array{string}>
     */
    public static function expecting(): array
    {
        return [['a'], ['b']];
    }
}

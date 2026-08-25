<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the per-testcase `assertions` attributes in the JUnit XML report; run by
 * the compatibility workflow with `--log-junit`, which then asserts the exact attribute values.
 * Deliberately NOT part of the gated compatibility suite (it needs its own XML-level assertions).
 *
 * Without the correction, every test here reported one extra assertion under Swoole (the up-front
 * credit, read from the test object by the JUnit logger's endTest() -- even with no yield
 * anywhere), assertions counted after a yield were missing, and assertions performed after a
 * yield were attributed to whatever test happened to be counting.
 *
 * @internal
 * @coversNothing
 */
class JunitCountTest extends TestCase
{
    /**
     * One assertion, no yield: the credit must not inflate the reported count to 2.
     */
    public function testOneImmediateAssertion(): void
    {
        self::assertTrue(true);
    }

    /**
     * Two assertions, no yield.
     */
    public function testTwoImmediateAssertions(): void
    {
        self::assertTrue(true);
        self::assertTrue(true);
    }

    /**
     * Three assertions: one through the static counter before the yield, one through it after the
     * yield, and one counted directly on the test object after the yield -- the late classes the
     * correction must attribute back to this test.
     */
    public function testLateCountsAfterYield(): void
    {
        self::assertTrue(true);
        sleep(1);
        self::assertTrue(true);
        $this->addToAssertionCount(1);
    }

    /**
     * One assertion, performed only after the yield, through the static counter: segment
     * accounting (see Attribution) must attribute it back to this test -- the credit/late
     * arithmetic alone would report 0 here.
     */
    public function testAssertsOnlyAfterYield(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    /**
     * Each data-provider row is its own testcase element (named "... with data set #N") and must
     * carry its own count, post-yield assertions included.
     *
     * @dataProvider provideAssertionCounts
     */
    public function testEachDataSetCountsSeparately(int $count): void
    {
        sleep(1);

        for ($i = 0; $i < $count; $i++) {
            self::assertTrue(true);
        }
    }

    /**
     * @return array<int, array<int, int>>
     */
    public function provideAssertionCounts(): array
    {
        return [[1], [2]];
    }
}

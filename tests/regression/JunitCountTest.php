<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression guard for the per-testcase `assertions` attributes in the JUnit XML report; run by
 * the compatibility workflow with `--log-junit`, which then asserts the exact attribute values.
 * Deliberately NOT part of the gated compatibility suite (it needs its own XML-level assertions).
 *
 * Without the correction, every test here reported one extra assertion under Swoole (the up-front
 * credit, baked into the Test\Finished event the JUnit logger consumes -- even with no yield
 * anywhere), assertions counted directly on the test object after a yield were missing, and
 * assertions performed after a yield were attributed to whatever test happened to be counting.
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
     * Three assertions: one through the static counter before the yield, two counted directly on
     * the test object after the yield -- the late-instance-count class the correction must add.
     */
    public function testLateCountsAfterYield(): void
    {
        self::assertTrue(true);
        sleep(1);
        $this->addToAssertionCount(2);
    }

    /**
     * One assertion, performed only after the yield, through the static counter: segment
     * accounting (see Attribution) must attribute it back to this test -- the credit/late
     * arithmetic alone would report 0 here, and the uncorrected report whatever test's counting
     * window happened to be open when the assertion ran.
     */
    public function testAssertsOnlyAfterYield(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    /**
     * Each data-provider row is its own testcase element (named "... with data set #N") and must
     * carry its own count, post-yield assertions included.
     */
    #[DataProvider('provideAssertionCounts')]
    public function testEachDataSetCountsSeparately(int $count): void
    {
        sleep(1);

        for ($i = 0; $i < $count; $i++) {
            self::assertTrue(true);
        }
    }

    /**
     * @return list<array{int}>
     */
    public static function provideAssertionCounts(): array
    {
        return [[1], [2]];
    }
}

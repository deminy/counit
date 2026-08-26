<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the "This test did not perform any assertions" risky check in the
 * automatic approach. counit's up-front assertion credit exists to suppress FALSE risky verdicts
 * (PHPUnit reads the count at the body's first yield, before post-yield assertions ran), but it
 * used to also suppress every TRUE one. Now: a body that finishes without ever yielding gets no
 * credit, so PHPUnit reaches the verdict natively at the right moment; and a yielding
 * no-assertion test whose yields counit can observe is reported at the end of the run through
 * PHPUnit's own Test\ConsideredRisky event -- risky listing, summary count and the
 * --fail-on-risky exit code all included (see UselessTests). Run by the compatibility workflow
 * in three flag states, asserting the exact blocking-mode summaries and exit codes with and
 * without Swoole.
 *
 * @internal
 * @coversNothing
 */
class UselessTestTest extends TestCase
{
    public function testNoAssertionsAfterYield(): void
    {
        sleep(1);
    }

    public function testNoAssertionsWithoutYield(): void
    {
        $noop = 1 + 1;
    }

    public function testAssertsAfterYield(): void
    {
        sleep(1);

        self::assertTrue(true);
    }
}

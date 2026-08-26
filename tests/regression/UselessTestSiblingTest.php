<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the mirror check ("This test is annotated with ... but performed N
 * assertions"): PHPUnit reads the same too-early count for it, so a declared-none test whose
 * assertion runs only after a yield used to escape the verdict. The pre-yield shape is exact
 * natively (the count is real when read); the post-yield shape is restored by the same deferred
 * pass as the main check (see UselessTests), with the count taken from the corrected per-test
 * tally. Run by the compatibility workflow, asserting the exact blocking-mode summary with and
 * without Swoole.
 *
 * @internal
 * @coversNothing
 */
class UselessTestSiblingTest extends TestCase
{
    /**
     * @doesNotPerformAssertions
     */
    public function testDeclaredNoneButAssertsAfterYield(): void
    {
        sleep(1);

        self::assertTrue(true);
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testDeclaredNoneButAssertsBeforeYield(): void
    {
        self::assertTrue(true);

        sleep(1);
    }
}

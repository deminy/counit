<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the "test printed unexpected output" risky check: TestRunner reads the
 * test's captured output right after runBare(), so only a joined-and-replayed body can satisfy
 * it. While --disallow-test-output is active, counit joins every test (see OutputExpectations)
 * and the check is exact -- this test must then be flagged risky with the literal printed text,
 * as under blocking PHPUnit, where before the fix the option was a silent no-op (the output
 * never reached PHPUnit at all). Without the option the run must stay green: the post-yield
 * output goes to the terminal, not into a verdict. Run by the compatibility workflow in both
 * variants, asserting the exact summaries with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class OutputRiskyTest extends TestCase
{
    public function testPrintsAfterYield(): void
    {
        sleep(1);

        echo 'unexpected-output';

        self::assertTrue(true);
    }
}

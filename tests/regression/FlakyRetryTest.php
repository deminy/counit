<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for --retry (PHPUnit 13 only): a test that fails on its first attempt --
 * only after a yield -- and passes on the second. Blocking PHPUnit retries and the run ends
 * green; under counit the first attempt was recorded as passed at its first yield, so the retry
 * machinery never fired and the flag was a silent no-op for exactly the flaky post-yield tests
 * it exists for. With the verdict-sequencing join (see VerdictSequencing) the first attempt's
 * failure is native and the retry happens, exactly as in blocking mode. Run by the
 * compatibility workflow with mode-identical assertions.
 *
 * @internal
 * @coversNothing
 */
class FlakyRetryTest extends TestCase
{
    private static int $attempts = 0;

    public function testFlakyAfterYield(): void
    {
        self::$attempts++;
        self::assertTrue(true);
        sleep(1);
        if (self::$attempts < 2) {
            self::fail('deliberate first-attempt failure after the yield');
        }
    }
}

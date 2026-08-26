<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for --enforce-time-limit in the automatic approach. PHPUnit times a limited
 * test by wrapping the whole runBare() call in a pcntl_alarm() guard; under Swoole that call used
 * to return at the body's first yield, so the over-limit test below simply passed. With the run
 * joined while enforcement is active, PHPUnit times the real runBare() and reports the timeout
 * natively. Run by the compatibility workflow with `--enforce-time-limit --default-time-limit=1`,
 * which asserts the exact blocking-mode summary -- the slow test risky ("This test was aborted
 * after 1 second"), the sized test passing under its own larger limit -- with and without Swoole,
 * plus the native --fail-on-risky exit code. Requires pcntl in both modes (PHPUnit itself
 * silently skips enforcement without it).
 *
 * The sleeps are generous on purpose: at the exact boundary (a sleep(N) under an N-second limit)
 * even blocking PHPUnit is flaky, and counit is documented as marginally more lenient there.
 *
 * @internal
 * @coversNothing
 */
class TimeLimitAutomaticTest extends TestCase
{
    public function testWithinLimit(): void
    {
        self::assertTrue(true);
    }

    public function testExceedsDefaultLimit(): void
    {
        sleep(3);

        // Must never be reached under a 1-second limit: blocking PHPUnit aborts the sleep at the
        // limit; under Swoole the joined coroutine is aborted at the sleep's end, before this line.
        // The aborted test is flagged risky twice -- "aborted after 1 second" and, having reached
        // no assertion, "did not perform any assertions" -- in both modes alike (the joined test
        // gets no up-front assertion credit).
        self::assertTrue(true);
    }
}

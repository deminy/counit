<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for diagnostics triggered after a test's first yield in the automatic
 * approach: PHPUnit's converting error handler is disabled the moment the test-method invocation
 * returns -- the first yield -- so post-yield deprecations/warnings/notices used to reach PHP's
 * default handler instead: printed raw, never counted, invisible to --display-*, the baseline
 * and --fail-on-*. counit now arms a delegating handler for exactly the windows PHPUnit's own
 * cannot cover (while the coroutine PHPUnit runs on is suspended) and hands every diagnostic to
 * PHPUnit's own ErrorHandler at trigger time, so attribution, @-suppression and all downstream
 * decisions stay upstream code (see Diagnostics). Run by the compatibility workflow, which
 * asserts the exact blocking-mode summary -- identical with and without Swoole -- and the
 * absence of raw diagnostic output; a lost diagnostic lowers a count without failing the run,
 * so the exact counts are the guard.
 *
 * @internal
 * @coversNothing
 */
class PostYieldDiagnosticsTest extends TestCase
{
    public function testDeprecationBeforeYield(): void
    {
        trigger_error('early user deprecation', E_USER_DEPRECATED);

        usleep(200000);

        self::assertTrue(true);
    }

    public function testDeprecationAfterYield(): void
    {
        usleep(200000);

        trigger_error('late user deprecation', E_USER_DEPRECATED);

        self::assertTrue(true);
    }

    public function testWarningAfterYield(): void
    {
        usleep(200000);

        trigger_error('late user warning', E_USER_WARNING);

        self::assertTrue(true);
    }

    public function testNoticeAfterYield(): void
    {
        usleep(200000);

        trigger_error('late user notice', E_USER_NOTICE);

        self::assertTrue(true);
    }

    public function testNativeWarningAfterYield(): void
    {
        usleep(200000);

        // unserialize() on garbage raises a genuine native E_WARNING ("Error at offset ...").
        unserialize('not-serialized-data');

        self::assertTrue(true);
    }

    public function testSuppressedDeprecationAfterYield(): void
    {
        usleep(200000);

        @trigger_error('late suppressed deprecation', E_USER_DEPRECATED);

        self::assertTrue(true);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard against a leaked swallow-everything error handler eating a later test's
 * diagnostics: the canary deprecation below used to disappear from the run entirely (blocking
 * reports `Deprecations: 1`). With the handler isolation, the leaker's handler is lifted off the
 * shared stack whenever its coroutine is suspended, so the canary's deprecation reaches
 * PHPUnit's own converting handler untouched -- the canary triggers it before its own first
 * yield on purpose, to isolate the leak effect from the separate (documented) gap that
 * post-yield diagnostics of a non-joined test are not converted at all. Run by the
 * compatibility workflow, which asserts the exact blocking-mode summary including the
 * `Deprecations:` count, with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class HandlerCanaryTest extends TestCase
{
    protected function setUp(): void
    {
        if ($this->name() === 'testBCanaryDeprecationIsReported') {
            usleep(300000);
        }
    }

    public function testALeaksSwallowEverythingHandler(): void
    {
        usleep(1000);

        set_error_handler(static fn (): bool => true);

        self::assertTrue(true);
    }

    public function testBCanaryDeprecationIsReported(): void
    {
        trigger_error('canary deprecation', E_USER_DEPRECATED);

        self::assertTrue(true);

        usleep(1000);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for deferred-failure reporting under --repeat (which PHPUnit 13 ships
 * again, together with --retry; neither exists on the 12.5 line, so the workflow step
 * feature-detects). A post-yield failure is invisible at the repetition boundary
 * under Swoole, so every repetition runs and fails late -- and the deferred entries used to
 * share one description-based key, silently overwriting each other: only one of N failing
 * repetitions was reported. Each occurrence now gets its own entry (see
 * Counit::uniqueDeferredKey()) and its own replayed Test\Failed event (see LateFailures). Run
 * by the compatibility workflow with --repeat 2, asserting exit code 1 in both modes, both
 * native failure entries under Swoole, and blocking mode's native stop-at-first-failure summary
 * (the second repetition is skipped there).
 *
 * @internal
 * @coversNothing
 */
class RepeatFailureTest extends TestCase
{
    public function testFailsAfterYield(): void
    {
        self::assertTrue(true);
        sleep(1);
        self::fail('fails after the yield');
    }
}

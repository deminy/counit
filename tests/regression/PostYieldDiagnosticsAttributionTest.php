<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Attribution guard: two tests trigger the SAME deprecation from the same line, after different
 * sleeps, so their wall-clock completion order is the reverse of their start order -- a
 * misattribution (the diagnostic pinned on whichever test happens to be "current") becomes
 * visible in the --display-deprecations "Triggered by" blocks, which must name BOTH tests. This
 * fails loudly if the delegation ever stops resolving the test from the triggering coroutine's
 * own call stack. Run by the compatibility workflow, which greps both test names from the
 * listing, with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class PostYieldDiagnosticsAttributionTest extends TestCase
{
    public function testAFirst(): void
    {
        $this->triggerSharedDeprecationAfter(400000);
    }

    public function testBSecond(): void
    {
        $this->triggerSharedDeprecationAfter(200000);
    }

    private function triggerSharedDeprecationAfter(int $microseconds): void
    {
        usleep($microseconds);

        trigger_error('shared late deprecation', E_USER_DEPRECATED);

        self::assertTrue(true);
    }
}

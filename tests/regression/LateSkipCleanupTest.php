<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for a skip signalled from a Counit::defer() cleanup after the body yielded
 * (manual approach -- defer() applies within a create() call): blocking PHPUnit turns it into a
 * skipped test with exit code 0, but the deferred-cleanup catch used to file EVERY Throwable as
 * a deferred failure -- forcing exit code 1 for a run blocking mode passes. The catch now
 * classifies SkippedTest/IncompleteTest like the body catch does, and the skip is replayed
 * through PHPUnit's own event at the end of the run. Run by the compatibility workflow, which
 * asserts the exact blocking-mode summary and exit code 0, with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class LateSkipCleanupTest extends TestCase
{
    public function testSkipFromDeferredCleanup(): void
    {
        Counit::create(function (): void {
            Counit::defer(static function (): void {
                self::markTestSkipped('skipped from a deferred cleanup');
            });

            self::assertTrue(true);

            Counit::sleep(1);
        }, 1);
    }
}

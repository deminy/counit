<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for skip/incomplete handling under the coroutine runner. A skip signalled
 * before the first yield is native in both modes; one signalled after the first yield lands
 * after PHPUnit already reported the test as passed and used to surface only as a counit STDERR
 * notice -- absent from the summary, invisible to --fail-on-skipped/--fail-on-incomplete. It is
 * now replayed into the run's TestResult through the public addError() once every coroutine has
 * drained (see CounitExtension::executeAfterLastTest()), so the summary counts, the listings
 * and the --fail-on-* exit codes (PHPUnit 9) match blocking mode exactly. Run by the
 * compatibility workflow, which asserts the exact blocking-mode summary -- identical with and
 * without Swoole -- plus the flag exit codes where PHPUnit supports the flags, and the absence
 * of the (now fallback-only) notice.
 *
 * @internal
 * @coversNothing
 */
class LateSkipTest extends TestCase
{
    public function testSkippedBeforeYield(): void
    {
        self::markTestSkipped('skipped before the first yield: native in both modes');
    }

    public function testSkippedAfterYield(): void
    {
        sleep(1);
        self::markTestSkipped('skipped after the first yield: replayed at the end of the run');
    }

    public function testIncompleteAfterYield(): void
    {
        sleep(1);
        self::markTestIncomplete('incomplete after the first yield: replayed at the end of the run');
    }
}

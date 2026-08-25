<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for skip/incomplete handling under the coroutine runner; deliberately NOT part
 * of the gated compatibility suite, because the two modes are expected to differ here: a skip
 * signalled before the first yield is honored in both modes, while one signalled after the first
 * yield cannot change the test's status anymore (PHPUnit already reported the test as passed) and
 * must be listed in an end-of-run notice without failing the run. The compatibility workflow runs
 * this file separately and asserts each mode's own expected output, including exit code 0.
 *
 * @internal
 * @coversNothing
 */
class LateSkipTest extends TestCase
{
    public function testSkippedBeforeYield(): void
    {
        self::markTestSkipped('skipped before the first yield: honored in both modes');
    }

    public function testSkippedAfterYield(): void
    {
        sleep(1);
        self::markTestSkipped('skipped after the first yield: status remains "passed" under Swoole');
    }

    public function testIncompleteAfterYield(): void
    {
        sleep(1);
        self::markTestIncomplete('incomplete after the first yield: status remains "passed" under Swoole');
    }
}

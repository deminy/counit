<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for diagnostics triggered after a test's first yield in the automatic
 * approach. PHPUnit 8/9 convert deprecations/notices/warnings into exceptions thrown at the
 * call site, through an error handler TestResult::run() registers OUTSIDE runBare() -- so the
 * "whole runBare() inside the coroutine" property does not reach it, and a post-yield
 * convertible diagnostic used to hit no handler at all: the test silently PASSED (exit 0) where
 * blocking mode errors it (exit 2). counit now arms a delegating handler for exactly the
 * windows PHPUnit's own cannot cover (while the coroutine PHPUnit runs on is suspended) and
 * hands each diagnostic to PHPUnit's own converting handler, which throws the exact Error\*
 * exception at the trigger site: the body aborts into the deferred-failure block, exit code 1
 * (see Diagnostics). The compatibility workflow asserts each mode's exact output -- the
 * blocking-native error vs the deferred report -- and above all that the run FAILS in both.
 *
 * @internal
 * @coversNothing
 */
class PostYieldDiagnosticsTest extends TestCase
{
    public function testNoticeBeforeYield(): void
    {
        trigger_error('early user notice', E_USER_NOTICE);

        usleep(200000);

        self::assertTrue(true);
    }

    public function testNoticeAfterYield(): void
    {
        usleep(200000);

        trigger_error('late user notice', E_USER_NOTICE);

        self::assertTrue(true);
    }

    public function testWarningAfterYield(): void
    {
        usleep(200000);

        trigger_error('late user warning', E_USER_WARNING);

        self::assertTrue(true);
    }

    public function testSuppressedNoticeAfterYield(): void
    {
        usleep(200000);

        @trigger_error('late suppressed notice', E_USER_NOTICE);

        self::assertTrue(true);
    }
}

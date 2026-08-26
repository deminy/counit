<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a test whose own error handler has to survive its sleep/IO yield. PHPUnit
 * snapshots both handler stacks at the top of runBare() and compares/restores them at its bottom
 * -- under counit, the body's first yield -- so this (perfectly legal, blocking-clean) shape used
 * to fail three ways at once: the handler was stripped mid-body (the post-yield trigger_error()
 * escaped raw to the terminal), the test was falsely flagged risky for "not removing" it, and the
 * test's own restore_error_handler() then popped PHPUnit's converting handler. With counit's
 * per-coroutine handler isolation (see HandlerIsolation) the handler is lifted off the shared
 * stack while the coroutine sleeps and put back when it resumes, so the whole shape behaves
 * exactly as in blocking mode. Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary and the absence of any risky/deferred entry, with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class HandlerSpanTest extends TestCase
{
    /**
     * @var list<string>
     */
    public static array $caught = [];

    public function testOwnHandlerSurvivesTheYield(): void
    {
        self::$caught = [];

        set_error_handler(static function (int $number, string $message): bool {
            HandlerSpanTest::$caught[] = $message;

            return true;
        });

        sleep(1);

        trigger_error('mine', E_USER_WARNING);

        restore_error_handler();

        self::assertSame(['mine'], self::$caught);
    }
}

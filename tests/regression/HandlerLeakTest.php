<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the four handler verdicts, before and after the first yield: a handler
 * registered pre-yield and never removed, one registered only post-yield (previously an
 * unreported leak into the rest of the run), a post-yield exception-handler leak, and a test
 * popping a handler that is not its own. Each must be flagged with PHPUnit's exact wording
 * against the right test -- natively where PHPUnit can still see it, through the end-of-run
 * emit otherwise (see HandlerIsolation) -- and nothing may leak into the shared stacks after
 * the run. Run by the compatibility workflow, which asserts the exact blocking-mode summary
 * and counts each message kind, with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class HandlerLeakTest extends TestCase
{
    public function testLeaksErrorHandlerBeforeYield(): void
    {
        set_error_handler(static fn (): bool => false);

        self::assertTrue(true);

        sleep(1);
    }

    public function testLeaksErrorHandlerAfterYield(): void
    {
        sleep(1);

        set_error_handler(static fn (): bool => false);

        self::assertTrue(true);
    }

    public function testLeaksExceptionHandlerAfterYield(): void
    {
        sleep(1);

        set_exception_handler(static function (\Throwable $throwable): void {});

        self::assertTrue(true);
    }

    public function testRemovesForeignHandlerBeforeYield(): void
    {
        restore_error_handler();

        self::assertTrue(true);

        sleep(1);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\After;

/**
 * Regression guard for an after-test hook that skips the test followed by one that errors.
 * PHPUnit up to 12.5.42 / 13.4.5 keeps calling the remaining after-test hooks after a skip, so
 * the later hook's error decides the outcome; as of 12.5.43 / 13.4.6 a skip stops the remaining
 * hooks, and the test is reported as skipped. Under the coroutine runner the relocated hooks
 * must follow whichever rule the installed PHPUnit applies. Run by the compatibility workflow
 * with mode-identical assertions.
 *
 * @internal
 * @coversNothing
 */
class AfterHookSkipThenErrorTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->name() === 'testSkipThenError') {
            $this->markTestSkipped('deliberate skip from tearDown()');
        }
    }

    #[After]
    public function failAfterTearDown(): void
    {
        if ($this->name() === 'testSkipThenError') {
            throw new \RuntimeException('deliberate error after tearDown()');
        }
    }

    public function testSkipThenError(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    public function testUnaffected(): void
    {
        self::assertTrue(true);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a non-assertion Throwable from tearDown() in a test whose body already
 * failed after a yield. PHPUnit ignores such a hook error under every generation of its
 * after-test hook rules (only an assertion-level hook failure counts after a failed body, and
 * only before 12.5.43 / 13.4.6), so the test fails once, with its own failure. Run by the
 * compatibility workflow with mode-identical assertions.
 *
 * @internal
 * @coversNothing
 */
class TearDownErrorAfterFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->name() === 'testBodyFailsThenTearDownErrors') {
            throw new \LogicException('deliberate error in tearDown()');
        }
    }

    public function testBodyFailsThenTearDownErrors(): void
    {
        sleep(1);
        self::fail('deliberate failure in the test body');
    }

    public function testUnaffected(): void
    {
        self::assertTrue(true);
    }
}

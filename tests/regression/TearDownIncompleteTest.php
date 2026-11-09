<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for markTestIncomplete() called from tearDown() after the test body passed.
 * PHPUnit up to 12.5.42 / 13.4.5 reports such a test as FAILED; as of 12.5.43 / 13.4.6 it is
 * reported as incomplete. Under the coroutine runner the relocated hook must follow whichever
 * rule the installed PHPUnit applies. Run by the compatibility workflow with mode-identical
 * assertions.
 *
 * @internal
 * @coversNothing
 */
class TearDownIncompleteTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->name() === 'testIncompleteFromTearDown') {
            $this->markTestIncomplete('deliberately incomplete from tearDown()');
        }
    }

    public function testIncompleteFromTearDown(): void
    {
        sleep(1);
        self::assertTrue(true);
    }

    public function testUnaffected(): void
    {
        self::assertTrue(true);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for an assertion failing in tearDown() of a test whose body already failed
 * after a yield. PHPUnit up to 12.5.42 / 13.4.5 reports the tearDown() failure in addition to
 * the test's own, counting the test twice; as of 12.5.43 / 13.4.6 the tearDown() outcome of a
 * test that did not pass is ignored. Under the coroutine runner the relocated hook must follow
 * whichever rule the installed PHPUnit applies. Run by the compatibility workflow with
 * mode-identical assertions.
 *
 * @internal
 * @coversNothing
 */
class TearDownAfterFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->name() === 'testBodyAndTearDownFail') {
            self::fail('deliberate failure in tearDown()');
        }
    }

    public function testBodyAndTearDownFail(): void
    {
        sleep(1);
        self::fail('deliberate failure in the test body');
    }

    public function testUnaffected(): void
    {
        self::assertTrue(true);
    }
}

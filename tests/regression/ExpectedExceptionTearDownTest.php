<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a tearDown() failure in a test whose body throws the exception it expects,
 * before any yield. PHPUnit counts such a body as passing, so the tearDown() failure fails the
 * test under every generation of its after-test hook rules. counit used to replay the hooks
 * inside the coroutine with the expected exception taken for a failing body, and on PHPUnit
 * 12.5.43 / 13.4.6 and later dropped the tearDown() failure. A body that never yields now hands
 * its hooks back to PHPUnit's own invocation. Run by the compatibility workflow with
 * mode-identical assertions.
 *
 * @internal
 * @coversNothing
 */
class ExpectedExceptionTearDownTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->name() === 'testThrowsExpectedExceptionWithoutYield') {
            self::fail('deliberate failure in tearDown()');
        }
    }

    public function testThrowsExpectedExceptionWithoutYield(): void
    {
        $this->expectException(\RuntimeException::class);

        throw new \RuntimeException('expected');
    }

    public function testUnaffected(): void
    {
        self::assertTrue(true);
    }
}

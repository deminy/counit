<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * A tearDown() that throws after a passing, yielding body: blocking PHPUnit errors the test
 * natively (Errors: 1, exit code 2). Under Swoole the relocated hook's Throwable only exists
 * after the test's report; it is now replayed as PHPUnit's own Test\Errored event (see
 * LateFailures), converging the summary, listing and exit code with blocking mode. Run by the
 * compatibility workflow with mode-identical assertions.
 *
 * @internal
 * @coversNothing
 */
class TearDownErrorTest extends TestCase
{
    private bool $throwFromTearDown = false;

    #[\Override]
    protected function tearDown(): void
    {
        if ($this->throwFromTearDown) {
            throw new \RuntimeException('deliberate tearDown() error');
        }
    }

    public function testTearDownThrowsAfterYieldingBody(): void
    {
        $this->throwFromTearDown = true;
        self::assertTrue(true);
        sleep(1);
    }

    public function testUnaffectedSibling(): void
    {
        self::assertTrue(true);
        sleep(1);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * A tearDown() that throws after a passing, yielding body: blocking PHPUnit errors the test
 * natively (Errors: 1, exit code 2). Under Swoole the whole runBare() -- tearDown() included --
 * runs inside the coroutine, so the Throwable propagates out only after the test's report and
 * used to land in the deferred STDERR block with a forced exit code 1; the replayed addError()
 * (see CounitExtension) now converges the summary, listing and exit code with blocking mode.
 * Run by the compatibility workflow with mode-identical assertions.
 *
 * @internal
 * @coversNothing
 */
class TearDownErrorTest extends TestCase
{
    /**
     * @var bool
     */
    private $throwFromTearDown = false;

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

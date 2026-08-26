<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the failing shapes of an output expectation: a mismatch (or no output at
 * all) must fail the test natively -- PHPUnit's own message, real actual-output diff, exit code
 * 1, no deferred end-of-run entry -- exactly as in blocking mode. Before the fix these could not
 * even mismatch honestly: the actual output was always '' (see OutputExpectationTest). Run by
 * the compatibility workflow, which asserts the exact blocking-mode summary and the absence of
 * the deferred-failure marker with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class OutputMismatchTest extends TestCase
{
    public function testWrongOutput(): void
    {
        $this->expectOutputString('expected');

        sleep(1);

        echo 'actual';
    }

    public function testNoOutput(): void
    {
        $this->expectOutputString('expected');

        sleep(1);
    }
}

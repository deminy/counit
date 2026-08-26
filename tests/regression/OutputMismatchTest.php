<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the failing shapes of a manual-approach output expectation: a mismatch
 * (or no output at all) must fail the test natively -- PHPUnit's own message, the real
 * actual-output diff, exit code 1, no deferred end-of-run entry -- exactly as in blocking mode.
 * Before the fix these could not even mismatch honestly: the actual output was always '' (see
 * OutputExpectationManualTest). Deliberately manual-approach: a joined manual test reports
 * natively, while the automatic approach's post-yield verdicts follow this branch's deferred
 * reporting model by design. Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary and the absence of the deferred-failure marker with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class OutputMismatchTest extends TestCase
{
    public function testWrongOutput(): void
    {
        Counit::create(function (): void {
            $this->expectOutputString('expected');

            Counit::sleep(1);

            echo 'actual';
        }, 1);
    }

    public function testNoOutput(): void
    {
        Counit::create(function (): void {
            $this->expectOutputString('expected');

            Counit::sleep(1);
        }, 1);
    }
}

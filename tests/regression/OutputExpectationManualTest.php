<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for expectOutputString()/expectOutputRegex() in the manual approach: such a
 * test's runBare() is PHPUnit's own, running on the main coroutine, while the callable passed to
 * Counit::create() runs in a spawned coroutine with its own (Swoole-per-coroutine) output-buffer
 * stack -- so its echo never reached PHPUnit's buffer and expectations compared against '',
 * before or after a yield alike. Counit::create() now captures the coroutine's output (see
 * OutputCapture), joins a test with a registered expectation at its first yield -- detected
 * through the public hasExpectationOnOutput(), like an exception expectation the output
 * expectation is declared inside the body -- and replays the captured bytes into PHPUnit's
 * still-open buffer, where the native verification runs against the real, complete output. Run
 * by the compatibility workflow, which asserts the exact blocking-mode summary with and without
 * Swoole.
 *
 * @internal
 * @coversNothing
 */
class OutputExpectationManualTest extends TestCase
{
    public function testOutputAfterYield(): void
    {
        Counit::create(function (): void {
            $this->expectOutputString('after');

            Counit::sleep(1);

            echo 'after';
        }, 1);
    }

    public function testOutputAroundYield(): void
    {
        Counit::create(function (): void {
            $this->expectOutputRegex('/^be-af$/');

            echo 'be-';

            Counit::sleep(1);

            echo 'af';
        }, 1);
    }
}

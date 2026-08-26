<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for expectOutputString()/expectOutputRegex() in the automatic approach: Swoole
 * gives every coroutine its own output-buffer stack, so the body's echo -- running inside the
 * coroutine counit spawns -- never reached PHPUnit's buffer, which is opened on the runner
 * coroutine at the top of runBare(). The expectation was verified against '' unconditionally,
 * before OR after a yield (the old matrix row's "after a yield" qualifier was wrong). counit now
 * captures the coroutine's output (OutputCapture), joins a test with a registered expectation at
 * its first yield, and replays the captured bytes into PHPUnit's still-open buffer, where the
 * native verification runs against the real, complete output. Run by the compatibility workflow,
 * which asserts the exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class OutputExpectationTest extends TestCase
{
    public function testOutputBeforeYield(): void
    {
        $this->expectOutputString('before');

        echo 'before';

        sleep(1);
    }

    public function testOutputAfterYield(): void
    {
        $this->expectOutputString('after');

        sleep(1);

        echo 'after';
    }

    public function testOutputAroundYield(): void
    {
        $this->expectOutputRegex('/^be-af$/');

        echo 'be-';

        sleep(1);

        echo 'af';
    }
}

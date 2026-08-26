<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Guard for expectOutputString()/expectOutputRegex() in the automatic approach: on this branch
 * the behavior holds by architecture -- the WHOLE parent::runBare(), PHPUnit's own ob_start()
 * and output verification included, runs inside the test's coroutine, whose private
 * Swoole-per-coroutine buffer survives its yields -- so expectations verify against the real,
 * complete output with full concurrency kept (unlike the 1.x branch, which needed the
 * capture-and-replay fix for this; and unlike this branch's manual approach, see
 * OutputExpectationManualTest). This pins that no future change here loses it -- in particular
 * that the manual-approach capture never double-wraps the automatic wrapper's own
 * Counit::create() call. Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary with and without Swoole.
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

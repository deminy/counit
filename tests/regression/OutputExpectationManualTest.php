<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for expectOutputString()/expectOutputRegex() in the manual approach: the same
 * capture-and-replay applies (see OutputExpectationTest and OutputCapture), with the join decided
 * inside Counit::create() through the public expectsOutput() -- like an exception expectation,
 * the output expectation is declared inside the body, so it only exists once the body has run to
 * its first yield. No assertion credit is applied on the join path; the output verification
 * counts its own assertion natively. Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary with and without Swoole.
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

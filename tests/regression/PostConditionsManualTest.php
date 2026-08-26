<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for an overridden assertPostConditions() in the manual approach: such a test's
 * runBare() is PHPUnit's own, running on the main coroutine, so the post-condition phase used to
 * fire at the body's first yield -- the hook inspected the test while its body was still sleeping
 * ($bodyFinished below observed as false). Counit::create() now joins the coroutine of a test
 * whose class customizes the phase (applying no assertion credit -- the joined body's real
 * assertions are counted natively), so PHPUnit's own hook invocation follows the truly finished
 * body. See PostConditionsTest for the automatic approach, which was never broken here, and
 * PostConditions for the design. Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class PostConditionsManualTest extends TestCase
{
    /**
     * @var bool
     */
    private $bodyFinished = false;

    protected function assertPostConditions(): void
    {
        self::assertTrue($this->bodyFinished, 'assertPostConditions() ran before the test body finished');
    }

    public function testFirstBodyFinishesBeforePostConditions(): void
    {
        Counit::create(function (): void {
            self::assertFalse($this->bodyFinished);

            Counit::sleep(1);

            $this->bodyFinished = true;
        }, 1);
    }

    public function testSecondBodyFinishesBeforePostConditions(): void
    {
        Counit::create(function (): void {
            self::assertFalse($this->bodyFinished);

            Counit::sleep(1);

            $this->bodyFinished = true;
        }, 1);
    }
}

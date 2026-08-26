<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for an overridden assertPostConditions() in the automatic approach: PHPUnit
 * runs the post-condition phase from runBare(), right after invokeTestMethod() returns -- which
 * under counit used to be the test body's first yield, so the hook inspected the test while its
 * body was still sleeping ($bodyFinished below observed as false). A class customizing the phase
 * is now detected by reflection (see PostConditions) and its tests are joined, so the hook runs
 * after the truly finished body -- native timing, native failure classification. Run by the
 * compatibility workflow, which asserts the exact blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class PostConditionsTest extends TestCase
{
    private bool $bodyFinished = false;

    protected function assertPostConditions(): void
    {
        self::assertTrue($this->bodyFinished, 'assertPostConditions() ran before the test body finished');
    }

    public function testFirstBodyFinishesBeforePostConditions(): void
    {
        self::assertFalse($this->bodyFinished);

        sleep(1);

        $this->bodyFinished = true;
    }

    public function testSecondBodyFinishesBeforePostConditions(): void
    {
        self::assertFalse($this->bodyFinished);

        sleep(1);

        $this->bodyFinished = true;
    }
}

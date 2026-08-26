<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Guard for an overridden assertPostConditions() in the automatic approach: on this branch the
 * behavior holds by architecture -- the WHOLE parent::runBare() runs inside the test's coroutine,
 * so PHPUnit's post-condition phase always follows the truly finished body, with full concurrency
 * kept (unlike the 1.x branch, which needed a join for this; and unlike this branch's manual
 * approach, see PostConditionsManualTest). This pins that no future change here loses it -- in
 * particular that the manual-approach join's detection never leaks into the automatic wrapper's
 * own Counit::create() call. Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class PostConditionsTest extends TestCase
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

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard for annotated post-condition hook methods in the manual approach: PHPUnit
 * (9.1 and later) appends every method carrying the postCondition annotation to the same
 * per-class hook list as the assertPostConditions() default, invoked from the same runBare()
 * call site -- so a class
 * using only the annotation (no assertPostConditions() override) had the identical mistiming, and
 * must be detected and joined the same way (see PostConditions, which counts the list's entries
 * beyond the always-present default). The preCondition-annotated method double-checks that the
 * pre-condition phase needs no handling: it runs before the test method is invoked. Run by the
 * compatibility workflow -- only where upstream PHPUnit honors these annotations (they, and the
 * hook list backing them, exist as of PHPUnit 9.1) -- which asserts the exact blocking-mode
 * summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class PostConditionAnnotationTest extends TestCase
{
    /**
     * @var bool
     */
    private $bodyFinished = false;

    /**
     * @var bool
     */
    private $preConditionRan = false;

    public function testBodyFinishesBeforePostConditionHook(): void
    {
        Counit::create(function (): void {
            self::assertTrue($this->preConditionRan);

            Counit::sleep(1);

            $this->bodyFinished = true;
        }, 1);
    }

    /**
     * @preCondition
     */
    protected function verifyPreCondition(): void
    {
        $this->preConditionRan = true;
    }

    /**
     * @postCondition
     */
    protected function verifyPostCondition(): void
    {
        self::assertTrue($this->bodyFinished, 'the annotated post-condition hook ran before the test body finished');
    }
}

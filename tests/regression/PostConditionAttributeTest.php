<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\PostCondition;
use PHPUnit\Framework\Attributes\PreCondition;

/**
 * Regression guard for #[PostCondition] hook methods: they land in the same hook collection as
 * assertPostConditions() and are invoked from the same runBare() call site, so a class using only
 * the attribute (no assertPostConditions() override) had the identical mistiming -- and must be
 * detected and joined the same way (see PostConditions, which counts the collection's entries
 * beyond the always-present default). The #[PreCondition] method double-checks that the
 * pre-condition phase needs no handling: it runs before the test method is invoked, i.e. before
 * the test's coroutine exists. Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class PostConditionAttributeTest extends TestCase
{
    private bool $bodyFinished = false;

    private bool $preConditionRan = false;

    public function testBodyFinishesBeforePostConditionHook(): void
    {
        self::assertTrue($this->preConditionRan);

        sleep(1);

        $this->bodyFinished = true;
    }

    #[PreCondition]
    protected function verifyPreCondition(): void
    {
        $this->preConditionRan = true;
    }

    #[PostCondition]
    protected function verifyPostCondition(): void
    {
        self::assertTrue($this->bodyFinished, 'the #[PostCondition] hook ran before the test body finished');
    }
}

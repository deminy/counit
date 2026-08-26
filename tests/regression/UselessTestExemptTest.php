<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the exemptions of the deferred no-assertions pass: a test that declares
 * it performs none (by the doesNotPerformAssertions annotation, or by an
 * expectNotToPerformAssertions() call inside the body -- resolved only once the coroutine
 * finished, since the call may sit anywhere in the body) and a test that aborts only after a
 * yield (PHPUnit 8/9 exempt every non-passing test from the check) must never be reported. The
 * compatibility workflow asserts the ABSENCE of the risky message for this file, in both modes
 * -- guarding against a future change accidentally flagging any of these three shapes.
 *
 * @internal
 * @coversNothing
 */
class UselessTestExemptTest extends TestCase
{
    /**
     * @doesNotPerformAssertions
     */
    public function testDeclaredByAnnotation(): void
    {
        sleep(1);
    }

    public function testDeclaredInBody(): void
    {
        $this->expectNotToPerformAssertions();

        sleep(1);
    }

    public function testSkippedAfterYield(): void
    {
        sleep(1);

        self::markTestSkipped('late skip');
    }
}

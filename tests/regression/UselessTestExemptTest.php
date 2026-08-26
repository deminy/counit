<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;

/**
 * Regression guard for the exemptions of the deferred no-assertions pass: a test that declares
 * it performs none (by attribute, or by an expectNotToPerformAssertions() call inside the body
 * -- resolved only once the coroutine finished, since the call may sit anywhere in the body) and
 * a test that aborts only after a yield (blocking PHPUnit exempts aborted tests from the check)
 * must never be reported. The compatibility workflow asserts the ABSENCE of the risky message
 * for this file, in both modes -- guarding against a future change accidentally flagging any of
 * these three shapes. Since the LateSkips replay, the post-yield skip also appears in the
 * summary (Skipped: 1) identically in both modes -- and must STILL not be flagged risky.
 *
 * @internal
 * @coversNothing
 */
class UselessTestExemptTest extends TestCase
{
    #[DoesNotPerformAssertions]
    public function testDeclaredByAttribute(): void
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

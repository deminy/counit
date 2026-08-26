<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use PHPUnit\Framework\TestCase;

/**
 * The manual-approach counterpart of ExceptionFailureTest: the failing shapes of a post-yield
 * exception expectation must report exactly as under blocking PHPUnit -- these were the cases
 * genuinely broken on this branch before the expectation-join in Counit::create() (the
 * automatic approach already verified natively inside the coroutine). Run by the compatibility
 * workflow, which asserts the exact summary, exit code, and the absence of the deferred block,
 * identically with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class ExceptionFailureManualTest extends TestCase
{
    public function testMismatchedExceptionAfterYield(): void
    {
        $this->expectException(\RuntimeException::class);
        Counit::create(function (): void {
            Counit::sleep(1);

            throw new \LogicException('deliberate manual mismatch');
        });
    }

    public function testExpectedExceptionNeverThrown(): void
    {
        $this->expectException(\RuntimeException::class);
        Counit::create(function (): void {
            Counit::sleep(1);
            // Deliberately returns without throwing.
        });
    }
}

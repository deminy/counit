<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * To test and check compatibility with PHPUnit.
 *
 * @internal
 * @coversNothing
 */
class ManualTest extends TestCase
{
    /**
     * Trigger an immediate assertion and see if warning message "This test did not perform any assertions" is suppressed properly.
     */
    public function testAssertionSuppression1(): void
    {
        Counit::create(
            function () {
                self::assertTrue(true, 'Trigger an immediate assertion and see if warning message "This test did not perform any assertions" is suppressed properly.');
            }
        );
    }

    /**
     * To trigger a delayed assertion only in the test case. This is used
     *   1. to test and see if warning message "This test did not perform any assertions" is suppressed properly.
     *   2. to test and see if the # of assertion matches.
     */
    public function testAssertionSuppression2(): void
    {
        Counit::create(
            function () {
                sleep(1);
                self::assertTrue(true, 'A delayed assertion is triggered.');
            },
            1 // The wrapped function call has one delayed assertion in it.
        );
    }

    /**
     * To trigger an immediate assertion and a delayed assertion within the same test case. This is used to test and see
     * if the # of assertion matches.
     */
    public function testAssertionSuppression3(): void
    {
        Counit::create(
            function () {
                self::assertTrue(true, 'An immediate assertion is triggered when start running the test case.');
                sleep(1);
                self::assertTrue(true, 'A delayed assertion is triggered.');
            },
            1 // The wrapped function call has one delayed assertion in it.
        );
    }

    /**
     * To expect an exception thrown out.
     */
    public function testExpectedException(): void
    {
        self::expectException(\Exception::class);
        throw new \Exception();
    }

    /**
     * Counit::create() must decline the requested assertion credit for a test that declares it
     * performs no assertions, even when the test explicitly asks for one (e.g. a $count left behind
     * after the annotation was added); otherwise PHPUnit reports the test as risky. In blocking mode
     * the $count argument is ignored altogether, so both modes report this test clean.
     *
     * @doesNotPerformAssertions
     */
    public function testDoesNotPerformAssertions(): void
    {
        Counit::create(
            function () {
                Counit::sleep(1);
            },
            1 // Requested on purpose: the credit must be declined for this test.
        );
    }
}

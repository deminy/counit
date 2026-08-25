<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use Exception;

/**
 * To test and check compatibility with PHPUnit.
 *
 * @internal
 * @coversNothing
 */
class AutomaticTest extends TestCase
{
    /**
     * Trigger an immediate assertion and see if warning message "This test did not perform any assertions" is suppressed properly.
     */
    public function testAssertionSuppression1(): void
    {
        self::assertTrue(true, 'Trigger an immediate assertion and see if warning message "This test did not perform any assertions" is suppressed properly.');
    }

    /**
     * To trigger a delayed assertion only in the test case. This is used
     *   1. to test and see if warning message "This test did not perform any assertions" is suppressed properly.
     *   2. to test and see if the # of assertion matches.
     */
    public function testAssertionSuppression2(): void
    {
        sleep(1);
        self::assertTrue(true, 'A delayed assertion is triggered.');
    }

    /**
     * To trigger an immediate assertion and a delayed assertion within the same test case. This is used to test and see
     * if the # of assertion matches.
     */
    public function testAssertionSuppression3(): void
    {
        self::assertTrue(true, 'An immediate assertion is triggered when start running the test case.');
        sleep(1);
        self::assertTrue(true, 'A delayed assertion is triggered.');
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
     * A test that declares it performs no assertions must not receive the up-front assertion credit;
     * otherwise PHPUnit reports it as risky ('This test is annotated with "@doesNotPerformAssertions"
     * but performed 1 assertions'). Here the test body finishes without yielding.
     *
     * @doesNotPerformAssertions
     */
    public function testDoesNotPerformAssertions(): void
    {
    }

    /**
     * Same as above, but the test body yields first, so the credit decision in runBare() is made
     * while the coroutine is still pending -- the path where the credit used to be applied
     * unconditionally.
     *
     * @doesNotPerformAssertions
     */
    public function testDoesNotPerformAssertionsAfterAYield(): void
    {
        sleep(1);
    }

    /**
     * expectNotToPerformAssertions() sets the same PHPUnit flag as the annotation. Called at the top
     * of the test body it runs inside the coroutine before its first yield, so it is already visible
     * when the credit decision is made after Counit::create() returns.
     */
    public function testExpectNotToPerformAssertions(): void
    {
        $this->expectNotToPerformAssertions();
        sleep(1);
    }
}

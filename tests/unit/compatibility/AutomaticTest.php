<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use Exception;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;

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
     * otherwise PHPUnit reports it as risky ("This test is not expected to perform assertions but
     * performed 1 assertion"). Here the test body finishes without yielding.
     */
    #[DoesNotPerformAssertions]
    public function testDoesNotPerformAssertions(): void
    {
    }

    /**
     * Same as above, but the test body yields first, so the credit decision in Counit::create() is
     * made while the coroutine is still pending -- the path where the credit used to be applied
     * unconditionally.
     */
    #[DoesNotPerformAssertions]
    public function testDoesNotPerformAssertionsAfterAYield(): void
    {
        sleep(1);
    }

    /**
     * expectNotToPerformAssertions() sets the same PHPUnit flag as the attribute. Called at the top
     * of the test body it runs before the first yield, so it is already visible when Counit::create()
     * decides whether to apply the credit.
     */
    public function testExpectNotToPerformAssertions(): void
    {
        $this->expectNotToPerformAssertions();
        sleep(1);
    }
}

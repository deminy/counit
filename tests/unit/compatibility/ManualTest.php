<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Counit;
use Exception;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
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
            function (): void {
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
            function (): void {
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
            function (): void {
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
     * after the attribute was added); otherwise PHPUnit reports the test as risky. In blocking mode
     * the $count argument is ignored altogether, so both modes report this test clean.
     */
    #[DoesNotPerformAssertions]
    public function testDoesNotPerformAssertions(): void
    {
        Counit::create(
            function (): void {
                Counit::sleep(1);
            },
            1 // Requested on purpose: the credit must be declined for this test.
        );
    }

    /**
     * Counit::defer() registers cleanup that runs right after the wrapped callable finishes --
     * unlike tearDown(), which PHPUnit invokes at the body's first yield. The delayed assertion
     * must therefore still observe the resource in its open state.
     */
    public function testDefer(): void
    {
        $resource        = new \stdClass();
        $resource->state = 'open';

        Counit::create(
            function () use ($resource): void {
                Counit::defer(static function () use ($resource): void {
                    $resource->state = 'closed';
                });
                Counit::sleep(1);
                self::assertSame('open', $resource->state, 'Deferred cleanup must not run before the body finishes.');
            },
            1 // The wrapped function call has one delayed assertion in it.
        );
    }

    /**
     * An addToAssertionCount() call made after the wrapped callable's first yield writes to the
     * test object directly, after PHPUnit already reported this test's count; the late-count
     * correction must include it. Two assertions: the assertTrue() and the direct count.
     */
    public function testCountAddedAfterTheYield(): void
    {
        Counit::create(
            function (): void {
                self::assertTrue(true);
                Counit::sleep(1);
                $this->addToAssertionCount(1);
            },
            1 // The wrapped function call has one delayed assertion in it.
        );
    }
}

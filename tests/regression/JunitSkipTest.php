<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the JUnit XML correction when a class contains a test that never emits
 * Test\Finished: PHPUnit only emits that event for tests that were prepared, so a test skipped
 * from setUp() still gets a `<testcase>` element (with assertions="0") but no record in counit's
 * ledgers. The corrector must leave that element alone and still correct its siblings -- an
 * earlier positional (FIFO) implementation shifted every following element of the class by one,
 * silently writing each test's count onto the next element. Run by the compatibility workflow
 * with `--log-junit`, which asserts the exact attribute values.
 *
 * @internal
 * @coversNothing
 */
class JunitSkipTest extends TestCase
{
    protected function setUp(): void
    {
        if ($this->name() === 'testSkippedFromSetUp') {
            self::markTestSkipped('Skipped before the test was prepared; its element must keep assertions="0".');
        }
    }

    public function testBeforeTheSkippedOne(): void
    {
        self::assertTrue(true);
    }

    /**
     * Never runs: the skip above fires before the test is prepared, so no Test\Finished event is
     * ever emitted for it -- only its (unrecorded) `<testcase>` element exists.
     */
    public function testSkippedFromSetUp(): void
    {
        self::assertTrue(true);
        self::assertTrue(true);
    }

    public function testAfterTheSkippedOne(): void
    {
        sleep(1);
        self::assertTrue(true);
        self::assertTrue(true);
        self::assertTrue(true);
    }
}

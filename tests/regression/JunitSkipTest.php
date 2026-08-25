<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the JUnit XML correction when a class contains a skipped test: its
 * `<testcase>` element (with assertions="0") must be left alone, and its siblings must still be
 * corrected with their own numbers -- a positional matcher would shift every following element of
 * the class by one. Run by the compatibility workflow with `--log-junit`, which asserts the exact
 * attribute values.
 *
 * @internal
 * @coversNothing
 */
class JunitSkipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->getName() === 'testSkippedFromSetUp') {
            $this->markTestSkipped('Skipped from setUp(); its element must keep assertions="0".');
        }
    }

    public function testBeforeTheSkippedOne(): void
    {
        self::assertTrue(true);
    }

    /**
     * Never runs: setUp() skips it before the body starts.
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

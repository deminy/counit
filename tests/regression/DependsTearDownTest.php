<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the after-test hooks of a joined producer (a test something depends on):
 * its whole runBare() -- tearDown() included -- runs to completion inside the joined coroutine,
 * so a throwing tearDown() errors the producer itself with PHPUnit's native semantics and its
 * dependent is skipped, identically with and without Swoole. On the 1.x branch this took a
 * dedicated fix (its 94cd806); here it comes with the join by architecture, and this test pins
 * it. Run by the compatibility workflow, which asserts the exact summary, exit code, and the
 * absence of the deferred block in both modes.
 *
 * @internal
 * @coversNothing
 */
class DependsTearDownTest extends TestCase
{
    protected function tearDown(): void
    {
        if ($this->getName(false) === 'testProducerWithThrowingTearDown') {
            throw new \RuntimeException('deliberate tearDown() failure of a joined producer');
        }
    }

    public function testProducerWithThrowingTearDown(): string
    {
        sleep(1);
        self::assertTrue(true);

        return 'value';
    }

    /**
     * Must never run: its producer errored (in tearDown()), so PHPUnit skips it -- in both modes.
     *
     * @depends testProducerWithThrowingTearDown
     */
    public function testDependentIsSkipped(string $value): void
    {
        self::fail('the dependent of an errored producer must be skipped, not run (received: ' . $value . ')');
    }

    public function testIndependentStillRuns(): void
    {
        self::assertTrue(true);
    }
}

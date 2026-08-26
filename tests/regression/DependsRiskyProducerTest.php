<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for a joined producer that performs no assertions: blocking PHPUnit flags it
 * risky ("This test did not perform any assertions"), which keeps it out of the passed list and
 * so skips its dependent. The join path must therefore NOT apply counit's up-front assertion
 * credit -- with the credit, the producer would count one assertion, stop being risky, and its
 * dependent would run. Run by the compatibility workflow, which asserts the exact summary and
 * exit code, identically with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class DependsRiskyProducerTest extends TestCase
{
    public function testProducerWithoutAssertions(): string
    {
        sleep(1);

        return 'value';
    }

    /**
     * Must never run: its producer was risky (performed no assertions), so it never entered
     * PHPUnit's passed list -- in both modes.
     *
     * @depends testProducerWithoutAssertions
     */
    public function testDependentIsSkipped(string $value): void
    {
        self::fail('the dependent of a risky producer must be skipped, not run (received: ' . $value . ')');
    }
}

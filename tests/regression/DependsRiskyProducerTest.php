<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Regression guard for a joined #[Depends] producer that performs no assertions: blocking
 * PHPUnit flags it risky ("This test did not perform any assertions") — under Swoole it must be
 * flagged the same way, which requires the join path to NOT apply counit's up-front assertion
 * credit (a credited producer would count one assertion and stop being risky). Unlike on
 * PHPUnit 8/9 — where a risky producer never enters the passed list, so its dependents are
 * skipped — PHPUnit 12/13 records the producer's pass and return value regardless of the risky
 * verdict, so the dependent still runs and receives the real value; this file pins that exact
 * blocking shape in both modes. Run by the compatibility workflow, which asserts the exact
 * summary and exit code, identically with and without Swoole.
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

    #[Depends('testProducerWithoutAssertions')]
    public function testDependentReceivesTheValue(string $value): void
    {
        self::assertSame('value', $value);
    }
}

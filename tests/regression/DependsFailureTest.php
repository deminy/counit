<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Regression guard for the #[Depends] failure semantics under the coroutine runner; deliberately
 * NOT part of the gated compatibility suite, because this run is expected to fail. A producer
 * that fails only after a yield used to be recorded as passed at that yield: its failure was
 * deferred to an end-of-run notice and its dependent ran anyway (against a NULL input). With the
 * producer's coroutine joined, the failure lands inside runBare() like in blocking mode -- the
 * producer counts as a real Failure, the dependent is Skipped, and no deferred-failure block is
 * printed. The compatibility workflow runs this file and asserts the exact summary, exit code 1,
 * and the absence of the deferred block, identically with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class DependsFailureTest extends TestCase
{
    public function testProducerFailsAfterYield(): string
    {
        sleep(1);
        self::fail('deliberate failure after the yield');
    }

    /**
     * Must never run: its producer failed, so PHPUnit skips it -- in both modes.
     */
    #[Depends('testProducerFailsAfterYield')]
    public function testDependentIsSkipped(string $value): void
    {
        self::fail('the dependent of a failed producer must be skipped, not run (received: ' . $value . ')');
    }

    public function testIndependentStillRuns(): void
    {
        self::assertTrue(true);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for the dependency-failure semantics ("depends" annotation): a producer
 * that fails only after a yield used to be recorded as passed at that yield -- its failure was deferred to an end-of-run
 * notice and its dependent ran anyway (with a NULL input). With the producer's coroutine joined
 * (see DependencyMap and TestCase::runBare()), the failure lands inside runBare() like in
 * blocking mode: a real Failure, the dependent Skipped, exit code 1, and no deferred-failure
 * block. Run by the compatibility workflow, which asserts the exact summary, exit code, and the
 * absence of the deferred block, identically with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class DependsFailureTest extends TestCase
{
    public function testFailingProducer(): string
    {
        sleep(1);
        self::fail('deliberate producer failure after a yield');
    }

    /**
     * Must never run: its producer failed, so PHPUnit skips it -- in both modes.
     *
     * @depends testFailingProducer
     */
    public function testDependentIsSkipped(string $value): void
    {
        self::fail('the dependent of a failed producer must be skipped, not run (received: ' . $value . ')');
    }

    public function testIndependentStillRuns(): void
    {
        self::assertTrue(true);
    }
}

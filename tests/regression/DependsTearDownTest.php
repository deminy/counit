<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Regression guard for the after-test hooks of a joined #[Depends] producer: its body is fully
 * finished inside invokeTestMethod(), so counit hands tearDown()/#[After] back to PHPUnit's
 * native invocation -- with native error semantics. A throwing tearDown() must error the
 * producer itself (not surface as a deferred end-of-run notice, as it does for non-joined
 * tests), and the producer's dependents must be skipped, exactly as under blocking PHPUnit. Run
 * by the compatibility workflow, which asserts the exact summary, exit code, and the absence of
 * the deferred block, identically with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class DependsTearDownTest extends TestCase
{
    private bool $throwFromTearDown = false;

    private bool $bodyFinished = false;

    protected function tearDown(): void
    {
        if ($this->throwFromTearDown) {
            throw new \RuntimeException('deliberate tearDown() failure of a joined producer');
        }

        // Guards the re-suppression: after a joined producer hands the hooks back to PHPUnit for
        // its own run, the next (non-joined) test must get the relocated replay again -- i.e.
        // tearDown() strictly after its finished body, not at its first yield.
        if ($this->name() === 'testRelocatedHooksAreBackAfterTheJoin' && !$this->bodyFinished) {
            throw new \RuntimeException('tearDown() ran before the body finished: the hooks were not re-suppressed after the join');
        }
    }

    public function testProducerWithThrowingTearDown(): string
    {
        $this->throwFromTearDown = true;
        sleep(1);
        self::assertTrue(true);

        return 'value';
    }

    /**
     * Must never run: its producer errored (in tearDown()), so PHPUnit skips it -- in both modes.
     */
    #[Depends('testProducerWithThrowingTearDown')]
    public function testDependentIsSkipped(string $value): void
    {
        self::fail('the dependent of an errored producer must be skipped, not run (received: ' . $value . ')');
    }

    public function testIndependentStillRuns(): void
    {
        self::assertTrue(true);
    }

    public function testRelocatedHooksAreBackAfterTheJoin(): void
    {
        sleep(1);
        self::assertTrue(true);
        $this->bodyFinished = true;
    }

    /**
     * A second join cycle in the same class: the suppress/restore toggle must survive repeated
     * flips (suppressed -> restored for the first producer -> re-suppressed -> restored again),
     * with the second producer's value delivered and its (well-behaved) native tearDown() clean.
     */
    public function testSecondProducerAfterTheToggle(): string
    {
        sleep(1);
        self::assertTrue(true);

        return 'second';
    }

    #[Depends('testSecondProducerAfterTheToggle')]
    public function testSecondDependentReceivesTheValue(string $value): void
    {
        self::assertSame('second', $value);
    }
}

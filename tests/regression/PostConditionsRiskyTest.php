<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Pins a deliberate behavior change of the post-condition join: a joined test receives no
 * assertion credit, so a test of a customizing class that performs no assertions is flagged risky
 * ("This test did not perform any assertions") exactly as under blocking PHPUnit -- where before
 * the fix, counit's credit made it report OK. The hook checks the body-finished timing without
 * performing an assertion (a throw here would surface as a native test error, so a clean run also
 * re-proves the join's timing). Run by the compatibility workflow, which asserts the exact
 * blocking-mode summary with and without Swoole.
 *
 * @internal
 * @coversNothing
 */
class PostConditionsRiskyTest extends TestCase
{
    private bool $bodyFinished = false;

    protected function assertPostConditions(): void
    {
        if (!$this->bodyFinished) {
            throw new \RuntimeException('assertPostConditions() ran before the test body finished');
        }
    }

    public function testPerformsNoAssertions(): void
    {
        sleep(1);

        $this->bodyFinished = true;
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for E_USER_ERROR after a test's first yield: PHPUnit's handler THROWS for it
 * ("E_USER_ERROR was triggered"), and counit's delegating handler lets that throw happen at the
 * trigger site inside the coroutine -- the body aborts into the deferred-failure machinery with
 * exit code 1. Before the fix the diagnostic reached PHP's default handler and KILLED the whole
 * run with exit code 255. Blocking mode errors the test natively (exit code 2) -- the 1-vs-2
 * divergence is the documented residual the compatibility workflow pins per mode; what it
 * forbids in both is the 255.
 *
 * @internal
 * @coversNothing
 */
class PostYieldUserErrorTest extends TestCase
{
    public function testUserErrorAfterYield(): void
    {
        usleep(200000);

        trigger_error('late user error', E_USER_ERROR);
    }
}

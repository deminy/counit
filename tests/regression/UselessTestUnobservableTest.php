<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Pins the documented residual of the deferred no-assertions pass: a test resumed at a point
 * counit cannot observe (here a fully-qualified \sleep(), which bypasses the namespace shim;
 * hooked network/DB IO behaves the same) has an untrustworthy per-test tally -- segment
 * accounting undercounts it by construction -- so the deferred pass stays silent rather than
 * risking a false accusation (counit's own CurlTest would be flagged otherwise). Blocking
 * PHPUnit flags this test risky; under Swoole it reports OK. The compatibility workflow asserts
 * exactly that divergence, per mode.
 *
 * @internal
 * @coversNothing
 */
class UselessTestUnobservableTest extends TestCase
{
    public function testNoAssertionsAfterUnobservableYield(): void
    {
        \sleep(1);
    }
}

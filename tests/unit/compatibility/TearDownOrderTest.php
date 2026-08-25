<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * A regression guard for the automatic approach's tearDown() ordering: runBare() -- including
 * setUp(), the test method, and tearDown() -- runs inside one coroutine, so tearDown() always
 * observes a finished test body, even when the body yields on a sleep/IO call. (This differs from
 * the 1.x architecture, where PHPUnit 10+ made runBare() final, forcing a narrower seam from which
 * the ordering has to be restored explicitly.)
 *
 * @internal
 * @coversNothing
 */
class TearDownOrderTest extends TestCase
{
    /**
     * @var string|null
     */
    private $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = 'ready';
    }

    protected function tearDown(): void
    {
        // Order-sensitive cleanup: it destroys state the test body still reads after its yield. If
        // tearDown() ever ran at the body's first yield instead of after the body, this test would
        // fail as a deferred failure and force a non-zero exit code.
        $this->fixture = null;
        parent::tearDown();
    }

    public function testBodyObservesFixtureAfterYield(): void
    {
        sleep(1);
        self::assertSame('ready', $this->fixture, 'tearDown() must not run before the test body finishes.');
    }
}

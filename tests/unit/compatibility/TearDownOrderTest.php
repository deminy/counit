<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * The headline test of the after-test hook takeover: an EXISTING, unmodified tearDown() must
 * observe a finished test body, exactly as under plain PHPUnit -- previously it ran as soon as the
 * body first yielded, destroying state the still-running body depended on.
 *
 * @internal
 * @coversNothing
 */
class TearDownOrderTest extends TestCase
{
    private ?string $fixture = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = 'ready';
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->fixture = null;
        parent::tearDown();
    }

    public function testBodyObservesFixtureAfterYield(): void
    {
        sleep(1);
        self::assertSame('ready', $this->fixture, 'tearDown() must not run before the test body finishes.');
    }
}

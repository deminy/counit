<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\After;

/**
 * Same guarantee as TearDownOrderTest, but through a #[After]-attributed method: the takeover
 * covers the whole after-test hook collection, not just the conventional tearDown().
 *
 * @internal
 * @coversNothing
 */
class AfterAttributeOrderTest extends TestCase
{
    private ?string $fixture = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = 'ready';
    }

    public function testBodyObservesFixtureAfterYield(): void
    {
        sleep(1);
        self::assertSame('ready', $this->fixture, 'A #[After] method must not run before the test body finishes.');
    }

    #[After]
    protected function closeFixture(): void
    {
        $this->fixture = null;
    }
}

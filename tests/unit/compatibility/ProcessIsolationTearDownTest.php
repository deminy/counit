<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The after-test hook takeover must leave process isolation untouched: the isolated test runs in a
 * plain, non-coroutine child process with its own fresh PHPUnit hook machinery, where tearDown()
 * already observes a finished body -- while the non-isolated sibling in the same class relies on
 * the takeover for the same guarantee.
 *
 * @internal
 * @coversNothing
 */
class ProcessIsolationTearDownTest extends TestCase
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

    /**
     * The child process runs in blocking mode, so PHPUnit's own hook timing is already correct.
     */
    #[RunInSeparateProcess]
    public function testIsolatedTestKeepsPlainSemantics(): void
    {
        self::assertSame('ready', $this->fixture, 'The fixture must exist while the body runs.');
    }

    public function testNonIsolatedSiblingObservesFixtureAfterYield(): void
    {
        sleep(1);
        self::assertSame('ready', $this->fixture, 'tearDown() must not run before the sibling test body finishes.');
    }
}

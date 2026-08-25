<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * A process-isolated test never reaches counit's coroutine machinery in the parent: PHPUnit runs it
 * in a plain, non-coroutine child process. tearDownCoroutine() still applies there, through the
 * blocking path of invokeTestMethod() -- right after the test body, before PHPUnit's own tearDown()
 * -- so the hook's ordering guarantee holds everywhere.
 *
 * @internal
 * @coversNothing
 */
class ProcessIsolationTearDownCoroutineTest extends TestCase
{
    private ?string $fixture = null;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = 'ready';
    }

    /**
     * The child process runs in blocking mode, so the hook fires synchronously after the body.
     */
    #[RunInSeparateProcess]
    public function testHookRunsAfterBodyInBlockingChild(): void
    {
        self::assertSame('ready', $this->fixture, 'The fixture must exist while the body runs.');
    }

    #[\Override]
    protected function tearDownCoroutine(): void
    {
        $this->fixture = null;
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * A tearDown() that asserts against state the test body only establishes after its yield: with the
 * hooks running mid-body this assertion failed; with the takeover it observes the finished body,
 * and its assertion is part of the run's total exactly as under plain PHPUnit.
 *
 * @internal
 * @coversNothing
 */
class TearDownAssertsTest extends TestCase
{
    private string $progress = 'pending';

    #[\Override]
    protected function tearDown(): void
    {
        self::assertSame('done', $this->progress, 'tearDown() must observe a finished test body.');
        parent::tearDown();
    }

    public function testBodyFinishesBeforeTearDown(): void
    {
        sleep(1);
        $this->progress = 'done';
    }
}

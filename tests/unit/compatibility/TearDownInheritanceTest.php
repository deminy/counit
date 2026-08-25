<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\After;

/**
 * Intermediate base class for TearDownInheritanceTest: its tearDown() must run as part of the
 * child's chained tearDown() call, in the same order as under plain PHPUnit.
 *
 * @internal
 * @coversNothing
 */
abstract class TearDownInheritanceBase extends TestCase
{
    /**
     * @var list<string>
     */
    public static array $log = [];

    #[\Override]
    protected function tearDown(): void
    {
        self::$log[] = 'base:tearDown';
        parent::tearDown();
    }
}

/**
 * The takeover must preserve PHPUnit's exact hook semantics for inheritance chains and
 * #[After]-attributed methods: a chained tearDown() runs child-then-base, followed by the
 * attributed hook, all strictly after the test body -- in both coroutine and blocking mode.
 *
 * The second test sleeps longer than the first, so by the time it asserts, the first test's whole
 * body-and-hooks cycle has completed in either mode.
 *
 * @internal
 * @coversNothing
 */
class TearDownInheritanceTest extends TearDownInheritanceBase
{
    #[\Override]
    protected function tearDown(): void
    {
        self::$log[] = 'child:tearDown';
        parent::tearDown();
    }

    public function testHooksObserveFinishedBody(): void
    {
        sleep(1);
        self::$log[] = 'body:end';
        self::assertTrue(true, 'The body finished; its hooks run next.');
    }

    public function testHooksRanInExpectedOrder(): void
    {
        sleep(2);
        self::assertSame(
            ['body:end', 'child:tearDown', 'base:tearDown', 'after'],
            \array_slice(self::$log, 0, 4),
            'The first test\'s hooks must have run after its body, child before base, tearDown before #[After].'
        );
    }

    #[After]
    protected function afterHook(): void
    {
        self::$log[] = 'after';
    }
}

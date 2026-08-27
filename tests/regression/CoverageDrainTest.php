<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

require_once __DIR__ . '/support/CoverageSubject.php';

/**
 * Regression guard for aggregate code coverage under the coroutine runner. Each test covers one
 * CoverageSubject method before its first yield and one after it; without the coverage window
 * counit opens around its end-of-run drain (see Deminy\Counit\Coverage), the post-yield method's
 * lines execute outside every per-test coverage window and silently vanish from the aggregate
 * report -- a coverage gate would be fed numbers less than half the truth. Run by the
 * compatibility workflow with the coverage filter pointed at the support/ directory, asserting
 * the exact same aggregate percentages with and without Swoole (neverCalled() keeps the expected
 * number below 100%, so an over-collecting regression is caught too).
 *
 * A real `@covers` annotation, deliberately: unlike PHPUnit 10+, this line honors coverage
 * annotations, and the fixer-enforced `@coversNothing` would discard every test's collected
 * data (the drain window's would survive, inverting the very numbers this guard pins).
 *
 * @internal
 * @covers \Deminy\Counit\Tests\CoverageSubject
 */
class CoverageDrainTest extends TestCase
{
    public function testOne(): void
    {
        self::assertSame(4, CoverageSubject::beforeYield(1));
        sleep(1);
        self::assertSame(15, CoverageSubject::afterYield(1));
    }

    public function testTwo(): void
    {
        self::assertSame(6, CoverageSubject::beforeYield(2));
        sleep(1);
        self::assertSame(19, CoverageSubject::afterYield(2));
    }

    public function testThree(): void
    {
        self::assertSame(8, CoverageSubject::beforeYield(3));
        sleep(1);
        self::assertSame(23, CoverageSubject::afterYield(3));
    }
}

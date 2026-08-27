<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Regression guard for failure/error verdicts reached only after the test's first yield. Before
 * the first yield (or on any join path) the verdict is fully native; after it, PHPUnit has
 * already reported the test as passed and the Throwable used to surface only in counit's
 * end-of-run STDERR block with a forced exit code 1 -- an "OK" summary over a failing run. The
 * verdicts are now replayed through PHPUnit's own Test\Failed/Test\Errored events once every
 * coroutine has drained (see LateFailures), so the FAILURES!/ERRORS! summary, the listings, the
 * exit code and the JUnit report (via JunitXmlCorrector) match blocking mode exactly. The
 * data-provider target pins the identity handling (deferred verdicts are stashed with the
 * test's event value object -- ID-string matching silently misses data-provider tests). Run by
 * the compatibility workflow, which asserts the exact blocking-mode summary -- identical with
 * and without Swoole -- and the absence of the (now fallback-only) deferred block.
 *
 * @internal
 * @coversNothing
 */
class LateFailureTest extends TestCase
{
    public function testPassesAfterYield(): void
    {
        self::assertTrue(true);
        sleep(1);
        self::assertTrue(true);
    }

    public function testFailsAfterYield(): void
    {
        self::assertTrue(true);
        sleep(1);
        self::fail('deliberate failure after the yield');
    }

    public function testErrorsAfterYield(): void
    {
        self::assertTrue(true);
        sleep(1);
        throw new \RuntimeException('deliberate error after the yield');
    }

    #[DataProvider('provideDataSets')]
    public function testDataSetFailsAfterYield(bool $shouldFail): void
    {
        self::assertTrue(true);
        sleep(1);
        if ($shouldFail) {
            self::fail('deliberate data-set failure after the yield');
        }
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function provideDataSets(): array
    {
        return [
            'passing set' => [false],
            'failing set' => [true],
        ];
    }
}

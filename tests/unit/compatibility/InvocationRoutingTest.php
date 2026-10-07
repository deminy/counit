<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Helper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * To test how the `counit` script routes an invocation: a run that executes tests must take the
 * concurrent (coroutine) path, while CLI commands and invalid usage must take the plain blocking
 * path, where PHPUnit's own exit() calls work natively.
 *
 * Misrouting a real test run is a silent failure: the run still passes with the exact same summary,
 * just at blocking speed. PHPUnit 13.4.1 triggered exactly that by giving the CLI arguments builder
 * a required constructor parameter -- the probe's own construction failure was mistaken for invalid
 * CLI usage, and every Swoole run fell back to blocking mode.
 *
 * @internal
 */
#[CoversClass(Helper::class)]
class InvocationRoutingTest extends TestCase
{
    public function testTestRunTakesConcurrentPath(): void
    {
        self::assertTrue(Helper::invocationRunsTests(['counit', '--testsuite', 'compatibility']));
    }

    public function testTestRunWithDeprecatedOptionTakesConcurrentPath(): void
    {
        // The builder reports deprecated options through its event emitter while parsing.
        self::assertTrue(Helper::invocationRunsTests(['counit', '--cache-result', '--testsuite', 'compatibility']));
    }

    public function testCliCommandTakesBlockingPath(): void
    {
        self::assertFalse(Helper::invocationRunsTests(['counit', '--version']));
    }

    public function testInvalidUsageTakesBlockingPath(): void
    {
        self::assertFalse(Helper::invocationRunsTests(['counit', '--no-such-option']));
    }
}

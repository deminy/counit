<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Helper;
use PHPUnit\Framework\TestCase;

/**
 * To test how counit degrades when PHPUnit's internals change.
 *
 * counit reads several PHPUnit internals through reflection. When one of them changes, counit must
 * fall back to its pre-existing behavior AND say so on STDERR: PHPUnit 13.4.0 broke three of them
 * on the 1.x line at once, and every run kept exiting 0 without a word, so nobody noticed.
 *
 * @internal
 * @covers \Deminy\Counit\Helper
 */
class FailSoftNoticeTest extends TestCase
{
    public function testNoticeIsPrintedOncePerReason(): void
    {
        [$stdout, $stderr] = self::runNoticeScript(false);

        self::assertSame('', $stdout);
        self::assertSame(
            "counit notice: first reason. Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.\n"
            . "counit notice: second reason. Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.\n",
            $stderr
        );
    }

    public function testNoticeCanBeSilenced(): void
    {
        [$stdout, $stderr] = self::runNoticeScript(true);

        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
    }

    /**
     * Runs Helper::notice() in a child process, so its STDERR output can be observed.
     *
     * @return array{0: string, 1: string} STDOUT and STDERR of the child process
     */
    private static function runNoticeScript(bool $silenced): array
    {
        $script = sprintf(
            'require %s; '
            . Helper::class . '::notice("first", "first reason."); '
            . Helper::class . '::notice("first", "first reason, repeated."); '
            . Helper::class . '::notice("second", "second reason.");',
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true)
        );

        $environment = getenv();
        unset($environment['COUNIT_SILENCE_TEARDOWN_NOTICE']);
        if ($silenced) {
            $environment['COUNIT_SILENCE_TEARDOWN_NOTICE'] = '1';
        }

        // The string form of proc_open(): the array form is PHP 7.4+, and this branch supports 7.2.
        $process = proc_open(
            sprintf('exec %s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg($script)),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $environment
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        return [$stdout, $stderr];
    }
}

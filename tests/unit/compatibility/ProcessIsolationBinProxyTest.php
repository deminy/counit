<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * To test and check compatibility with PHPUnit's process isolation when counit is invoked through a
 * Composer bin proxy (vendor/bin/counit), the way every downstream consumer runs it.
 *
 * When a test preserves global state -- the DEFAULT on PHPUnit 8/9, so every isolated test without
 * "@preserveGlobalState disabled" -- PHPUnit replays the parent's included files into the isolated
 * child process. The replay drops the entry script, and special-cases exactly one Composer bin
 * proxy: PHPUnit's own (a path ending in "/phpunit/phpunit/phpunit"). Run through vendor/bin/counit,
 * the proxy is the entry script and counit's real binary is the second included file -- so, without
 * the exclude-list registration in the counit script, the child re-executes the whole counit entry
 * script: a brand-new PHPUnit run inside the child, which re-discovers the isolated test class and
 * spawns children of its own, recursively -- the run hangs, spawning processes without bound.
 *
 * The repository's own suites always invoke ./counit directly (the real binary is the entry script,
 * which PHPUnit drops from the replay), so this test rebuilds the downstream shape explicitly: a
 * throwaway proxy script that includes counit's binary, run against a throwaway isolated test class.
 *
 * @internal
 * @coversNothing
 */
class ProcessIsolationBinProxyTest extends TestCase
{
    public function testIsolatedTestsPassWhenCounitRunsThroughComposerBinProxy(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'counit-bin-proxy-' . bin2hex(random_bytes(4));
        if (!mkdir($dir)) {
            throw new \RuntimeException("Unable to create temporary directory {$dir}.");
        }

        $probe = $dir . DIRECTORY_SEPARATOR . 'BinProxyProbeTest.php';
        $proxy = $dir . DIRECTORY_SEPARATOR . 'counit-proxy';

        try {
            // Global state is preserved by default on PHPUnit 8/9, which is exactly the
            // included-files-replay shape this test pins.
            file_put_contents(
                $probe,
                <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @internal
 * @coversNothing
 *
 * @runTestsInSeparateProcesses
 */
class BinProxyProbeTest extends \PHPUnit\Framework\TestCase
{
    public function testOne(): void
    {
        self::assertSame(2, 1 + 1);
    }

    public function testTwo(): void
    {
        self::assertSame(4, 2 + 2);
    }
}
PHP
            );

            // The same include shape Composer's generated bin proxy uses on PHP >= 8: the proxy is
            // the entry script, and counit's real binary is the second entry in get_included_files().
            file_put_contents(
                $proxy,
                sprintf("<?php\n\nreturn include %s;\n", var_export(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'counit', true))
            );

            [$exitCode, $output] = self::runCommandWithDeadline(
                // "exec" replaces the intermediate shell, so a deadline kill reaches the PHP
                // process itself. (PHP 7.4's array-form proc_open would avoid the shell, but this
                // branch supports PHP 7.2.)
                sprintf(
                    'exec %s %s --no-configuration %s',
                    escapeshellarg(PHP_BINARY),
                    escapeshellarg($proxy),
                    escapeshellarg($probe)
                ),
                $dir . DIRECTORY_SEPARATOR . 'output.log',
                60
            );

            self::assertStringContainsString('OK (2 tests, 2 assertions)', $output, 'The isolated probe tests must pass when counit is invoked through a Composer bin proxy.');
            self::assertSame(0, $exitCode, 'A bin-proxy counit run of isolated tests must exit 0.');
        } finally {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }

    /**
     * Runs a command, killing it once the deadline passes: before the fix this class pins, a
     * bin-proxy run of isolated tests spawned child processes without bound instead of finishing,
     * so the failure mode must be a test failure rather than a hung suite.
     *
     * @return array{0: int, 1: string} the exit code and the combined stdout/stderr output
     */
    private static function runCommandWithDeadline(string $command, string $outputFile, int $deadlineInSeconds): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['file', $outputFile, 'a'],
                2 => ['file', $outputFile, 'a'],
            ],
            $pipes
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Unable to launch the bin-proxy counit run.');
        }
        fclose($pipes[0]);

        $deadline = microtime(true) + $deadlineInSeconds;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];

                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                proc_close($process);
                self::fail(sprintf('The bin-proxy counit run did not finish within %d seconds (isolated children spawned without bound?).', $deadlineInSeconds));
            }
            usleep(50000);
        }
        proc_close($process);

        return [$exitCode, (string) file_get_contents($outputFile)];
    }
}

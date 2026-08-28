<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use PHPUnit\Framework\TestCase;

/**
 * To test and check compatibility with PHPUnit's process isolation when counit is invoked through a
 * Composer bin proxy (vendor/bin/counit), the way every downstream consumer runs it.
 *
 * When a test preserves global state, PHPUnit replays the parent's included files into the isolated
 * child process. The replay drops the entry script, and special-cases exactly one Composer bin proxy:
 * PHPUnit's own (a path ending in "/phpunit/phpunit/phpunit"). Run through vendor/bin/counit, the
 * proxy is the entry script and counit's real binary is the second included file -- so, without the
 * exclude-list registration in the counit script, the child would re-execute the whole counit entry
 * script (a brand-new PHPUnit run) instead of just running the one isolated test: every isolated
 * test errors out ("ended unexpectedly"), and under PHPUnit 8/9 the re-run even re-discovers the
 * isolated test class and spawns children without bound.
 *
 * The repository's own suites always invoke ./counit directly (the real binary is the entry script,
 * which PHPUnit drops from the replay), so this test rebuilds the downstream shape explicitly: a
 * throwaway proxy script that includes counit's binary, run against a throwaway isolated test class
 * that preserves global state.
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
            file_put_contents(
                $probe,
                <<<'PHP'
                    <?php

                    declare(strict_types=1);

                    use PHPUnit\Framework\Attributes\CoversNothing;
                    use PHPUnit\Framework\Attributes\PreserveGlobalState;
                    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
                    use PHPUnit\Framework\TestCase;

                    #[CoversNothing]
                    #[PreserveGlobalState(true)]
                    #[RunTestsInSeparateProcesses]
                    class BinProxyProbeTest extends TestCase
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
                [PHP_BINARY, $proxy, '--no-configuration', $probe],
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
     * bin-proxy run of isolated tests could spawn child processes without bound instead of
     * finishing, so the failure mode must be a test failure rather than a hung suite.
     *
     * @param non-empty-list<string> $command
     * @return array{0: int, 1: string} the exit code and the combined stdout/stderr output
     */
    private static function runCommandWithDeadline(array $command, string $outputFile, int $deadlineInSeconds): array
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
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to launch the bin-proxy counit run.');
        }
        fclose($pipes[0]);

        $deadline = hrtime(true) + $deadlineInSeconds * 1_000_000_000;
        while (true) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exitCode = $status['exitcode'];

                break;
            }
            if (hrtime(true) >= $deadline) {
                proc_terminate($process, 9);
                proc_close($process);
                self::fail(sprintf('The bin-proxy counit run did not finish within %d seconds (isolated children spawned without bound?).', $deadlineInSeconds));
            }
            usleep(50_000);
        }
        proc_close($process);

        return [$exitCode, (string) file_get_contents($outputFile)];
    }
}

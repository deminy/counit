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

            // The same include shapes Composer's generated bin proxy uses (a trimmed copy of the
            // real thing): on PHP >= 8 a plain include -- the proxy is the entry script, and
            // counit's real binary lands as the second entry in get_included_files(), the exact
            // shape whose replay this test pins. On PHP < 8 the plain include would be a fatal
            // error (PHP only strips the "#!" shebang line of the MAIN script there; included, it
            // becomes inline HTML ahead of the declare(strict_types=1) statement), so Composer
            // includes through its shebang-stripping phpvfscomposer:// stream wrapper instead --
            // whose entries PHPUnit's replay skips wholesale, which is why only the PHP >= 8
            // plain-path shape ever leaked the binary into the child.
            // (The closing marker is kept alone on its line, with the template in a variable: a
            // marker followed by other characters is PHP 7.3+ syntax, and this branch lints on 7.2.)
            $proxyTemplate = <<<'PHP'
<?php

namespace Composer;

$binPath = %s;

if (PHP_VERSION_ID < 80000) {
    if (!class_exists('Composer\BinProxyWrapper')) {
        /**
         * @internal
         */
        final class BinProxyWrapper
        {
            private $handle;
            private $position;
            private $realpath;

            public function stream_open($path, $mode, $options, &$opened_path)
            {
                // get rid of phpvfscomposer:// prefix for __FILE__ & __DIR__ resolution
                $opened_path = substr($path, 17);
                $this->realpath = realpath($opened_path) ?: $opened_path;
                $opened_path = 'phpvfscomposer://' . $this->realpath;
                $this->handle = fopen($this->realpath, $mode);
                $this->position = 0;

                return (bool) $this->handle;
            }

            public function stream_read($count)
            {
                $data = fread($this->handle, $count);

                if ($this->position === 0) {
                    $data = preg_replace('{^#!.*\r?\n}', '', $data);
                }
                $data = str_replace('__DIR__', var_export(dirname($this->realpath), true), $data);
                $data = str_replace('__FILE__', var_export($this->realpath, true), $data);

                $this->position += strlen($data);

                return $data;
            }

            public function stream_cast($castAs)
            {
                return $this->handle;
            }

            public function stream_close()
            {
                fclose($this->handle);
            }

            public function stream_lock($operation)
            {
                return $operation ? flock($this->handle, $operation) : true;
            }

            public function stream_seek($offset, $whence)
            {
                if (0 === fseek($this->handle, $offset, $whence)) {
                    $this->position = ftell($this->handle);

                    return true;
                }

                return false;
            }

            public function stream_tell()
            {
                return $this->position;
            }

            public function stream_eof()
            {
                return feof($this->handle);
            }

            public function stream_stat()
            {
                return array();
            }

            public function stream_set_option($option, $arg1, $arg2)
            {
                return true;
            }

            public function url_stat($path, $flags)
            {
                $path = substr($path, 17);
                if (file_exists($path)) {
                    return stat($path);
                }

                return false;
            }
        }
    }

    if (
        (function_exists('stream_get_wrappers') && in_array('phpvfscomposer', stream_get_wrappers(), true))
        || (function_exists('stream_wrapper_register') && stream_wrapper_register('phpvfscomposer', 'Composer\BinProxyWrapper'))
    ) {
        return include "phpvfscomposer://" . $binPath;
    }
}

return include $binPath;

PHP;
            file_put_contents(
                $proxy,
                sprintf($proxyTemplate, var_export(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'counit', true))
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

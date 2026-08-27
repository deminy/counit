<?php

declare(strict_types=1);

namespace Deminy\Counit;

use Swoole\Coroutine;

class Helper
{
    protected static string $prefix = '';

    protected static int $counter = 0;

    /**
     * Check to see if running unit tests using counit, with the Swoole extension enabled.
     */
    public static function isCoroutineFriendly(): bool
    {
        return extension_loaded('swoole') && (Coroutine::getCid() !== -1);
    }

    /**
     * The coroutine hook flags counit runs tests under. STDIO, file and process operations are
     * excluded from the hooks, because all three are used by PHPUnit's own machinery outside any
     * test's assertion-counting window: it writes to STDOUT (progress output) and to files (e.g.,
     * the result cache) between tests, and it spawns a child process -- reading its result through
     * pipes -- for every test marked #[RunInSeparateProcess] / #[RunTestsInSeparateProcesses] (or
     * when --process-isolation is used). If those calls yielded, pending test coroutines would
     * resume while no window is open, and the assertions they perform there are wiped by the next
     * test's counter reset, silently vanishing from the run's reported total (see CounitExtension
     * for how the total is kept exact). With the exclusions, tests doing real file IO or spawning
     * processes simply block for that operation's duration instead of yielding; network IO and
     * sleep() -- what this package exists to parallelize -- stay hooked.
     *
     * NOTE: Swoole only honors hook flags configured before the coroutine scheduler starts, so
     * this value must be applied via Coroutine::set() before Swoole\Coroutine\run() (as done in
     * the `counit` script); setting it from inside a running coroutine has no effect.
     *
     * Only call this method when the Swoole extension is loaded; the SWOOLE_HOOK_* constants do
     * not exist without it.
     */
    public static function coroutineHookFlags(): int
    {
        return SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE & ~SWOOLE_HOOK_PROC;
    }

    /**
     * Whether this invocation actually runs tests -- as opposed to a CLI command (--version,
     * --help, --list-*, --migrate-configuration, ...), an invalid invocation (unknown option,
     * missing test file or configuration file), or anything else PHPUnit finishes by calling
     * exit() directly instead of returning an exit code from Application::run().
     *
     * The distinction matters because the `counit` script runs Application::run() inside a
     * coroutine, and Swoole intercepts exit() there: it throws a Swoole\ExitException at the
     * exit() call site, which PHPUnit's own catch-all then mistakes for an internal error -- the
     * output gains a bogus "An error occurred inside PHPUnit" block and the exit code becomes
     * 255 (formerly with a raw fatal backtrace on top), where blocking mode prints a clean
     * message and exits 0 or 2. Invocations that run no tests need no coroutine scheduler at
     * all, so the script routes them through its plain blocking branch, where PHPUnit's direct
     * exits work natively -- output and exit code byte-identical to plain PHPUnit.
     *
     * The decision mirrors Application::run()'s own routing through PHPUnit's public CLI-parser
     * API (no option strings duplicated): every command accessor the three
     * executeCommandsThat*() methods consult, plus the pre-flight checks that end in a direct
     * exit before any test could run -- a named configuration that resolves to no file (through
     * PHPUnit's own XmlConfigurationFileFinder, which accepts a DIRECTORY and falls back to the
     * cwd candidates: `-c <dir>` is a valid, test-running shape and must stay concurrent), a
     * --test-files-file/--test-id-filter-file that does not exist, a path argument that does not
     * exist ("Test file ... not found"), and the empty invocation (no configuration resolvable,
     * no arguments, no test-files-file: PHPUnit prints its help with exit code 1). The probe is
     * deliberately one-sided: only a POSITIVE identification routes to blocking mode; on any
     * surprise (changed internals, an accessor gone) the concurrent path is kept, so a real test
     * run can never be silently downgraded to blocking speed.
     *
     * PHPUnit bail-outs the probe cannot see are caught by the script's Swoole\ExitException
     * handler instead: any exit discovered only after the CLI parse -- a malformed or unreadable
     * XML configuration, a missing bootstrap script, a test-suite <directory> that does not
     * exist on disk, and their kin. Those runs keep their clean PHPUnit message but gain
     * PHPUnit's crash block (reading "Message: swoole exit") and exit code 255 where blocking
     * exits 1 or 2 -- degraded but deterministic, never a raw fatal backtrace.
     *
     * @param list<string> $argv
     */
    public static function invocationRunsTests(array $argv): bool
    {
        try {
            // fromParameters() expects the full argv: its parser discards the first element (the
            // program name) itself, exactly as Application::run() hands it over.
            $cliConfiguration = (new \PHPUnit\TextUI\CliArguments\Builder())->fromParameters($argv);
        } catch (\Throwable) {
            // Invalid CLI usage: PHPUnit reports it and exits directly. That exit must not
            // happen inside a coroutine.
            return false;
        }

        try {
            $commands = [
                'generateConfiguration', 'migrateConfiguration', 'validateConfiguration',
                'hasAtLeastVersion', 'version', 'checkPhpConfiguration', 'checkVersion', 'help',
                'warmCoverageCache', 'listSuites', 'listGroups', 'listTestIds', 'listTests',
                'hasListTestsXml', 'listTestFiles',
            ];
            foreach ($commands as $command) {
                // validateConfiguration and listTestIds exist only as of PHPUnit 13.
                if (method_exists($cliConfiguration, $command) && $cliConfiguration->{$command}() === true) {
                    return false;
                }
            }

            // PHPUnit's own resolution: `-c <file>` returns the path as named (existing or
            // not), `-c <dir>` resolves the phpunit.xml/phpunit.dist.xml/phpunit.xml.dist
            // candidates inside it (false when none -- PHPUnit then proceeds on its DEFAULT
            // configuration and still runs the path arguments), and without -c the same
            // candidates in the cwd. Only a named-but-nonexistent file is a pre-flight exit.
            $configurationFile = (new \PHPUnit\TextUI\CliArguments\XmlConfigurationFileFinder())->find($cliConfiguration);
            if ($configurationFile !== false && !is_file($configurationFile)) {
                return false;
            }

            // Both filter files are validated by PHPUnit before any test runs; both accessor
            // pairs exist only as of PHPUnit 13.
            foreach ([['hasTestFilesFile', 'testFilesFile'], ['hasTestIdFile', 'testIdFile']] as [$has, $get]) {
                if (method_exists($cliConfiguration, $has) && $cliConfiguration->{$has}() === true
                    && method_exists($cliConfiguration, $get) && !is_file((string) $cliConfiguration->{$get}())) {
                    return false;
                }
            }

            foreach ($cliConfiguration->arguments() as $argument) {
                if (!file_exists($argument)) {
                    return false;
                }
            }

            // Nothing to run at all: no resolvable configuration, no path arguments, no test
            // list. PHPUnit prints its help and exits 1. (The method_exists() guard covers the
            // PHPUnit 12.5 line, which the analyzed vendor tree cannot see.)
            if ($configurationFile === false && $cliConfiguration->arguments() === []
                && !(method_exists($cliConfiguration, 'hasTestFilesFile') && $cliConfiguration->hasTestFilesFile() === true)) { // @phpstan-ignore function.alreadyNarrowedType
                return false;
            }
        } catch (\Throwable) {
            // Changed PHPUnit internals: keep the concurrent path rather than silently running
            // every suite at blocking speed.
            return true;
        }

        return true;
    }

    public static function getNewKey(): string
    {
        if (self::$prefix === '') {
            self::initPrefix();
        }
        return self::$prefix . (++self::$counter);
    }

    /**
     * @return string[]
     */
    public static function getNewKeys(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $keys[] = self::getNewKey();
        }

        return $keys;
    }

    protected static function initPrefix(string $prefix = ''): void
    {
        if ($prefix === '') {
            $prefix = uniqid('test-key-') . '-' . getmypid() . '-';
        }
        self::$prefix = $prefix;
    }
}

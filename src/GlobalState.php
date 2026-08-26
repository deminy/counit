<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Metadata\BackupGlobals;
use PHPUnit\Metadata\BackupStaticProperties;
use PHPUnit\Metadata\Metadata;
use PHPUnit\Metadata\MetadataCollection;
use PHPUnit\Metadata\Parser\Registry as MetadataRegistry;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Whether PHPUnit brackets a test with a global-state snapshot -- the input to counit's
 * barrier-and-join decision for #[BackupGlobals], #[BackupStaticProperties] and
 * #[WithEnvironmentVariable].
 *
 * PHPUnit takes the snapshot (and sets the environment variables) at the very top of runBare(),
 * BEFORE setUp() and before counit's invokeTestMethod() seam is ever reached, and restores at the
 * very bottom. Under counit runBare() used to return at the body's first yield, so the restore
 * fired mid-body: the test's own pre-yield mutations (an injected environment variable included)
 * were reverted while the body still needed them, and its post-yield mutations escaped the
 * restore and leaked for the rest of the run. On the join paths the window instead spanned the
 * test's whole duration while other coroutines ran inside it -- and the restore then reverted
 * THEIR global writes (Restorer unsets every key absent from the snapshot).
 *
 * The fix therefore needs two pieces, both keyed off this class:
 *  - a BARRIER: before the snapshot is taken, every in-flight test coroutine is drained. The one
 *    seam early enough is the Test\PreparationStarted event (emitted three lines before the
 *    snapshot); draining any later -- e.g. from invokeTestMethod() -- is after the snapshot, so
 *    tests finishing during the drain would mutate state inside the window and be reverted.
 *    CounitExtension's existing PreparationStarted subscriber performs the drain.
 *  - a JOIN: the test's coroutine runs to completion before runBare() returns (the #[Depends]
 *    producer mechanism), so PHPUnit's own snapshot/restore brackets the real body -- setUp(),
 *    body, and the natively-run after-test hooks -- with nothing else in flight.
 * Together they give the exclusive window blocking PHPUnit gets for free; --strict-global-state
 * becomes correct as a side effect (both of its snapshots now bracket that window). A test
 * backing up static properties additionally needs TestCase::repairAfterStaticRestore() -- see
 * there -- because counit's own static state is user-defined and not on PHPUnit's exclude list.
 *
 * The resolution below mirrors TestBuilder::backupSettings() exactly, quirk included: a
 * method-level attribute beats a class-level one, and only enabled() === true forces backup on --
 * #[BackupGlobals(false)] cannot override a configuration-level `true`, it merely declines to
 * force `true` itself (upstream behavior). When no attribute applies, the XML/CLI configuration
 * decides (both default to false). #[WithEnvironmentVariable] has no configuration switch and
 * class- and method-level attributes are cumulative, so any occurrence qualifies the test.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class GlobalState
{
    private static bool $configBackupGlobals = false;

    private static bool $configBackupStaticProperties = false;

    private static bool $noticeIssued = false;

    /**
     * Per Class::method: whether the test is bracketed by any snapshot at all ('any'), and
     * whether static properties are part of it ('statics', which gates the post-restore repair).
     *
     * @var array<string, array{any: bool, statics: bool}>
     */
    private static array $resolved = [];

    /**
     * Remembers the run's configuration-level backup defaults; called from
     * CounitExtension::bootstrap() with the same Configuration instance TestBuilder's fallback
     * reads. Before this runs, the resolution falls back to the shipped defaults (false), never
     * breaking a run whose extension did not bootstrap.
     */
    public static function initialize(Configuration $configuration): void
    {
        self::$configBackupGlobals          = $configuration->backupGlobals();
        self::$configBackupStaticProperties = $configuration->backupStaticProperties();
        self::$resolved                     = [];
    }

    /**
     * Whether PHPUnit brackets this test with a global-state snapshot and/or environment-variable
     * backup -- i.e. whether the barrier and the join apply.
     *
     * @param class-string $className
     * @param non-empty-string $methodName
     */
    public static function isBackedUp(string $className, string $methodName): bool
    {
        return self::resolve($className, $methodName)['any'];
    }

    /**
     * Whether static properties are part of this test's snapshot -- the case that additionally
     * rewinds counit's own static state and therefore needs the post-restore repair.
     *
     * @param class-string $className
     * @param non-empty-string $methodName
     */
    public static function backsUpStaticProperties(string $className, string $methodName): bool
    {
        return self::resolve($className, $methodName)['statics'];
    }

    /**
     * Whether the configuration alone (backupGlobals/backupStaticProperties XML attributes, or
     * --globals-backup/--static-backup) makes every test qualify -- in which case the whole run
     * serializes and announceSerializedRun() should be called once.
     */
    public static function configBacksUpEveryTest(): bool
    {
        return self::$configBackupGlobals || self::$configBackupStaticProperties;
    }

    /**
     * Announces -- once, to STDERR (excluded from the coroutine hooks, so it cannot yield) --
     * that the run is serialized because global-state backup is configured run-wide. Silenced by
     * COUNIT_SILENCE_TEARDOWN_NOTICE=1, like the other counit notices. Per-test barriers (a
     * single test carrying one of the attributes) are deliberately silent.
     */
    public static function announceSerializedRun(): void
    {
        if (self::$noticeIssued || getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') !== false) {
            self::$noticeIssued = true;

            return;
        }
        self::$noticeIssued = true;

        fwrite(STDERR, 'counit notice: global-state backup is configured for every test (backupGlobals/backupStaticProperties), so each test first waits for all in-flight tests and then runs joined -- the run is serialized (PHPUnit\'s isolation, PHPUnit\'s speed, no concurrency). Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.' . PHP_EOL);
    }

    /**
     * @param class-string $className
     * @param non-empty-string $methodName
     *
     * @return array{any: bool, statics: bool}
     */
    private static function resolve(string $className, string $methodName): array
    {
        $key = $className . '::' . $methodName;
        if (isset(self::$resolved[$key])) {
            return self::$resolved[$key];
        }

        $globals = self::$configBackupGlobals;
        $statics = self::$configBackupStaticProperties;
        $env     = false;

        try {
            $parser    = MetadataRegistry::parser();
            $forMethod = $parser->forMethod($className, $methodName);
            $forClass  = $parser->forClass($className);

            $metadata = self::methodOrClassLevel($forMethod->isBackupGlobals(), $forClass->isBackupGlobals());
            if ($metadata instanceof BackupGlobals && $metadata->enabled()) {
                $globals = true;
            }

            $metadata = self::methodOrClassLevel($forMethod->isBackupStaticProperties(), $forClass->isBackupStaticProperties());
            if ($metadata instanceof BackupStaticProperties && $metadata->enabled()) {
                $statics = true;
            }

            $env = $parser->forClassAndMethod($className, $methodName)->isWithEnvironmentVariable()->isNotEmpty();
        } catch (\Throwable) {
            // Changed PHPUnit internals; fall back to the configuration-level answer resolved
            // above. Under-detecting only costs this fix's guarantees, never a crash.
        }

        return self::$resolved[$key] = ['any' => $globals || $statics || $env, 'statics' => $statics];
    }

    /**
     * Same precedence rule as TestBuilder::methodOrClassLevelMetadata(): metadata on the test
     * method beats metadata on its class.
     */
    private static function methodOrClassLevel(MetadataCollection $forMethod, MetadataCollection $forClass): ?Metadata
    {
        if ($forMethod->isNotEmpty()) {
            return $forMethod->asArray()[0];
        }

        if ($forClass->isNotEmpty()) {
            return $forClass->asArray()[0];
        }

        return null;
    }
}

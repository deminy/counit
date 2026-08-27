<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The run-level join switch for PHPUnit's verdict-sequencing options: the --stop-on-* family
 * (defect/error/failure/warning/risky/deprecation/notice/skipped/incomplete, threshold forms
 * included) and, on PHPUnit 13, --repeat/--retry.
 *
 * All of these make PHPUnit decide something BETWEEN tests from the verdicts it has so far:
 * whether to start the next test at all (TestSuite::run() consults shouldStop() before each
 * one), whether to run another repetition (--repeat stops a test's repetitions at its first
 * failure), or whether to retry (--retry re-attempts until the first success). Under counit a
 * post-yield verdict only exists once the whole test loop has finished, so every one of these
 * silently degenerated: --stop-on-failure reacted to pre-yield failures only, --repeat ran
 * every repetition, and --retry never retried the flaky post-yield tests it exists for. There
 * is no partial fix -- by the time a deferred verdict exists there is nothing left to stop --
 * so while any of these options is active, EVERY test is joined at its first yield (the
 * TimeLimit/OutputExpectations switch shape): each verdict is native and final before the next
 * scheduling decision, giving the options their exact blocking semantics at the price of the
 * run's concurrency (one STDERR notice announces this).
 *
 * Deliberately per run: the flags say "sequence my verdicts", which is inherently serial. This
 * is NOT the rejected --fail-on-risky join (that flag only affects the exit code, correct
 * without joining, and is common in CI where a silent serialization would hurt) -- the
 * --stop-on-*, --repeat and --retry options are explicit requests for sequenced behavior that
 * is otherwise silently wrong.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class VerdictSequencing
{
    private static bool $active = false;

    private static bool $noticeIssued = false;

    /**
     * Remembers whether any verdict-sequencing option is active for this run; called from
     * CounitExtension::bootstrap() with the same Configuration instance PHPUnit's runner reads.
     * Before this runs, activeForRun() reports false -- degrading to counit's pre-existing
     * (non-joining) behavior, never breaking a run whose extension did not bootstrap.
     */
    public static function initialize(Configuration $configuration): void
    {
        try {
            $active = $configuration->stopOnDefect()
                || $configuration->stopOnError()
                || $configuration->stopOnFailure()
                || $configuration->stopOnWarning()
                || $configuration->stopOnRisky()
                || $configuration->stopOnDeprecation()
                || $configuration->stopOnNotice()
                || $configuration->stopOnSkipped()
                || $configuration->stopOnIncomplete();

            // --repeat/--retry exist only as of PHPUnit 13 (both default to 1); the
            // method_exists() guards cover the 12.5 line, which the analyzed vendor tree
            // cannot see.
            if (!$active && method_exists($configuration, 'repeat') && (int) $configuration->repeat() > 1) { // @phpstan-ignore function.alreadyNarrowedType
                $active = true;
            }
            if (!$active && method_exists($configuration, 'retry') && (int) $configuration->retry() > 1) { // @phpstan-ignore function.alreadyNarrowedType
                $active = true;
            }

            self::$active = $active;
        } catch (\Throwable) {
            self::$active = false;
        }
    }

    /**
     * Whether tests must be joined at their first yield because PHPUnit sequences verdicts in
     * this run.
     */
    public static function activeForRun(): bool
    {
        return self::$active;
    }

    /**
     * Announces -- once, to STDERR (excluded from the coroutine hooks, so it cannot yield) --
     * that the run is serialized; called from CounitExtension::bootstrap() when running under
     * Swoole with a verdict-sequencing option active. Silenced by
     * COUNIT_SILENCE_TEARDOWN_NOTICE=1, like the other counit notices.
     */
    public static function announceSerializedRun(): void
    {
        if (self::$noticeIssued || getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') !== false) {
            self::$noticeIssued = true;

            return;
        }
        self::$noticeIssued = true;

        fwrite(STDERR, 'counit notice: a verdict-sequencing option (--stop-on-*, --repeat or --retry) is active, so every test is joined at its first yield to make each verdict final before PHPUnit\'s next scheduling decision -- the run is serialized (exact blocking semantics, no concurrency). Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.' . PHP_EOL);
    }
}

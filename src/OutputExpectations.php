<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The run-level join switch for --disallow-test-output (beStrictAboutOutputDuringTests).
 *
 * With output capture and replay in place (see OutputCapture), a test with a registered output
 * expectation is joined per test -- detected through the public TestCase::expectsOutput(), no
 * reflection needed -- and verified natively. Two output surfaces remain that only a joined body
 * can serve: the "test printed unexpected output" risky check (TestRunner reads the test's
 * captured output right after runBare() -- under counit, before a non-joined body's post-yield
 * output could ever be replayed), and getActualOutputForAssertion()/getActualOutput() first
 * called after a yield (the retrieval itself is the only join trigger, and it happens after the
 * decision point). Both are exact only when every test is joined -- so while
 * --disallow-test-output is active, counit joins every test, mirroring TimeLimit: PHPUnit's
 * strictness at PHPUnit's speed, the run serializes for the duration (STDERR notice). Without
 * the option, a non-expecting test's post-yield output goes to the terminal in one batch instead
 * of into PHPUnit's buffer -- visible, never silently swallowed.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class OutputExpectations
{
    private static bool $disallowTestOutput = false;

    private static bool $noticeIssued = false;

    /**
     * Remembers whether the run disallows test output; called from CounitExtension::bootstrap()
     * with the same Configuration instance PHPUnit's own TestRunner reads. Before this runs, the
     * resolution falls back to the shipped default (false), never breaking a run whose extension
     * did not bootstrap.
     */
    public static function initialize(Configuration $configuration): void
    {
        self::$disallowTestOutput = $configuration->disallowTestOutput();
    }

    /**
     * Whether every test must be joined because the run disallows test output.
     */
    public static function disallowedForRun(): bool
    {
        return self::$disallowTestOutput;
    }

    /**
     * Announces -- once, to STDERR (excluded from the coroutine hooks, so it cannot yield) --
     * that the run is serialized because --disallow-test-output is active. Silenced by
     * COUNIT_SILENCE_TEARDOWN_NOTICE=1, like the other counit notices. Per-test output-expectation
     * joins are deliberately silent.
     */
    public static function announceSerializedRun(): void
    {
        if (self::$noticeIssued || getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') !== false) {
            self::$noticeIssued = true;

            return;
        }
        self::$noticeIssued = true;

        fwrite(STDERR, 'counit notice: --disallow-test-output is active, so every test is joined at its first yield to give PHPUnit the complete output to check -- the run is serialized (PHPUnit\'s strictness, PHPUnit\'s speed, no concurrency). Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.' . PHP_EOL);
    }
}

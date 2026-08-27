<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * The deferred half of PHPUnit's "This test did not perform any assertions" risky check (and of
 * its mirror, "This test is not expected to perform assertions but performed N assertions").
 *
 * PHPUnit decides both verdicts in TestRunner::run(), from the count it reads the moment
 * runBare() returns -- under counit, the test body's first yield. Whether the still-running body
 * will assert later is unknowable at that instant, so counit credits one assertion up front to
 * avoid FALSE risky verdicts, at the price of never producing a true one (Counit::create()
 * declines the credit once the body is known finished, which already restores the native verdict
 * for every test that never yields, and every join path declines it too). This class restores
 * the remaining true verdicts at the end of the run: once every coroutine has drained, the
 * assertions each test actually performed are known, and PHPUnit's own Test\ConsideredRisky
 * event can still be emitted -- the result collector, the risky listing, the summary's Risky
 * count and the --fail-on-risky exit code all honor it, because the collector is only read after
 * the extension's ExecutionFinished subscriber returns (the same property the run-total
 * correction relies on).
 *
 * A test is reported only when counit can PROVE its count: its coroutine must never have been
 * resumed at a point counit cannot observe (see Attribution::unattributedFor() -- hooked
 * network/DB IO, a fully-qualified \sleep(), a test class in the global namespace), because such
 * a test's own tally is an undercount by construction and reporting it would be a false
 * accusation -- counit's own CurlTest would be flagged. Everything unprovable stays silent,
 * exactly as before. Also exempted, mirroring TestRunner's own gates: tests PHPUnit already
 * flagged itself (joined or never-yielding tests -- never report twice), tests that declared
 * they perform no assertions (those get the mirror check instead), and tests that errored,
 * skipped or went incomplete -- natively or only after their report (the deferred
 * failures/skips), since blocking PHPUnit exempts aborted tests from the check.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class UselessTests
{
    public const MESSAGE = 'This test did not perform any assertions';

    private const UNEXPECTED_MESSAGE_PREFIX = 'This test is not expected to perform assertions but performed ';

    private static bool $reportUselessTests = true;

    /**
     * @var array<string, Test>
     */
    private static array $tests = [];

    /**
     * @var array<string, true>
     */
    private static array $alreadyFlagged = [];

    /**
     * @var array<string, true>
     */
    private static array $aborted = [];

    /**
     * Remembers whether the run reports useless tests (beStrictAboutTestsThatDoNotTestAnything,
     * on by default; disabled by --do-not-report-useless-tests); called from
     * CounitExtension::bootstrap() with the same Configuration instance TestRunner reads.
     */
    public static function initialize(Configuration $configuration): void
    {
        self::$reportUselessTests = $configuration->reportUselessTests();
    }

    /**
     * Remembers a finished test's event-facade Test object; the deferred pass needs it to emit
     * the verdict. Called from the Test\Finished subscriber.
     */
    public static function record(Test $test): void
    {
        self::$tests[$test->id()] = $test;
    }

    /**
     * A risky verdict PHPUnit already reached itself (a joined or never-yielding test): the
     * deferred pass must not repeat it. Called from the Test\ConsideredRisky subscriber; only
     * the two messages this class owns count, so an unrelated risky verdict (e.g. a time limit)
     * does not shadow a missing no-assertions one.
     */
    public static function markFlagged(string $testId, string $message): void
    {
        if ($message === self::MESSAGE || str_starts_with($message, self::UNEXPECTED_MESSAGE_PREFIX)) {
            self::$alreadyFlagged[$testId] = true;
        }
    }

    /**
     * A test that errored, skipped or went incomplete -- natively (the subscribers in
     * CounitExtension) or only after its report (the deferred failure/skip paths in
     * Counit::create()). Blocking PHPUnit exempts aborted tests from the check.
     */
    public static function markAborted(string $testId): void
    {
        self::$aborted[$testId] = true;
    }

    /**
     * Emits the verdicts PHPUnit could not reach on its own. Called from the ExecutionFinished
     * subscriber, after every coroutine has drained and before the result collector is read.
     */
    public static function emitDeferred(): void
    {
        // Without segment accounting (Swoole's preemptive scheduler) no per-test tally exists,
        // so nothing can be proved and nothing is reported. The reportUselessTests setting is
        // NOT checked here: PHPUnit gates only the "did not perform any assertions" verdict on
        // it (TestRunner::run()), while the mirror verdict for a declared-none test that did
        // assert fires unconditionally -- so this pass has to make the same distinction, below.
        if (!Attribution::$enabled) {
            return;
        }

        foreach (self::$tests as $testId => $test) {
            if (isset(self::$alreadyFlagged[$testId]) || isset(self::$aborted[$testId])) {
                continue;
            }

            // A test whose coroutine ran unattributed code has an untrustworthy tally;
            // reporting it either way would be a guess.
            if (Attribution::unattributedFor($testId)) {
                continue;
            }

            $count = Counit::correctedAssertionCountFor($testId);
            if ($count === null) {
                continue;
            }

            // Read now, not at record time: the Test\Finished event fires at the body's first
            // yield, before an expectNotToPerformAssertions() call further down the body has
            // happened. By the time this runs the coroutine has drained and the declaration is
            // final -- the same reason creditCaller() resolves it only after create() returns.
            if (Counit::declaresNoAssertionsFor($testId)) {
                if ($count > 0) {
                    EventFacade::emitter()->testConsideredRisky($test, sprintf(
                        '%s%d assertion%s',
                        self::UNEXPECTED_MESSAGE_PREFIX,
                        $count,
                        $count > 1 ? 's' : ''
                    ));
                }

                continue;
            }

            if ($count !== 0 || !self::$reportUselessTests) {
                continue;
            }

            EventFacade::emitter()->testConsideredRisky($test, self::MESSAGE);
        }
    }
}

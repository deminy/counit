<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\RiskyTestError;

/**
 * The deferred half of PHPUnit's "This test did not perform any assertions" risky check (and of
 * its mirror, the "annotated with @doesNotPerformAssertions but performed N assertions" check).
 *
 * PHPUnit 8/9 decide both verdicts in TestResult::run(), from the count read the moment
 * runBare() returns -- under counit, the test body's first yield. Whether the still-running body
 * will assert later is unknowable at that instant, so counit credits one assertion up front to
 * avoid FALSE risky verdicts, at the price of never producing a true one (Counit::create() and
 * TestCase::runBare() now decline the credit once the body is known finished, which already
 * restores the native verdict for every test that never yields; the join paths always declined
 * it). This class restores the remaining true verdicts at the end of the run: once every
 * coroutine has drained, the assertions each test actually performed are known, and a
 * RiskyTestError can still be handed to the public TestResult::addFailure() from
 * CounitExtension::executeAfterLastTest() -- it lands in the risky list with the right location
 * (the message carries the test method's file and line, built the same way TestResult builds
 * it), counts into the summary's `Risky:` number, and the `counit` script's --fail-on-risky exit
 * alignment turns it into exit code 1, exactly as in blocking mode. The result printer writes
 * its footer only after the hook returns; its progress symbol for a late verdict lands after the
 * progress line, a cosmetic artifact.
 *
 * A test is reported only when counit can PROVE its count: its coroutine must never have been
 * resumed at a point counit cannot observe (see Attribution::unattributedFor() -- hooked
 * network/DB IO, a fully-qualified \sleep(), a test class in the global namespace), because such
 * a test's own tally is an undercount by construction and reporting it would be a false
 * accusation -- counit's own CurlTest would be flagged. Everything unprovable stays silent,
 * exactly as before. Also exempted, mirroring TestResult's own strict elseif verdict chain:
 * tests PHPUnit already flagged for either check (never report twice), tests that declared they
 * perform no assertions (those get the mirror check instead), and every non-passing test --
 * errored, failed, warned, skipped or incomplete, natively or only after its report (the
 * deferred failures/skips).
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class UselessTests
{
    public const MESSAGE = 'This test did not perform any assertions';

    private const UNEXPECTED_MESSAGE_PREFIX = 'This test is annotated with "@doesNotPerformAssertions" but performed ';

    /**
     * @var array<int, true>
     */
    private static $alreadyFlagged = [];

    /**
     * @var array<int, true>
     */
    private static $aborted = [];

    /**
     * A risky verdict PHPUnit already reached itself: the deferred pass must not repeat it. Only
     * the two messages this class owns count, so an unrelated risky verdict (e.g. a time limit)
     * does not shadow a missing no-assertions one. Called from AssertionCountListener.
     */
    public static function markFlagged(int $key, string $message): void
    {
        if ($message === self::MESSAGE
            || strpos($message, self::MESSAGE . "\n") === 0
            || strpos($message, self::UNEXPECTED_MESSAGE_PREFIX) === 0) {
            self::$alreadyFlagged[$key] = true;
        }
    }

    /**
     * A test that did not pass -- errored, failed, warned, skipped or incomplete, natively
     * (AssertionCountListener's verdict methods) or only after its report (the deferred
     * failure/skip paths in Counit::create()). PHPUnit 8/9's verdict chain exempts every
     * non-passing test from the check.
     */
    public static function markAborted(int $key): void
    {
        self::$aborted[$key] = true;
    }

    /**
     * Emits the verdicts PHPUnit could not reach on its own. Called from
     * CounitExtension::executeAfterLastTest(), after every coroutine has drained and before the
     * result printer writes its footer.
     */
    public static function emitDeferred(): void
    {
        $testResult = Counit::$testResult;

        // Without segment accounting (Swoole's preemptive scheduler) no per-test tally exists,
        // so nothing can be proved and nothing is reported. The strictness switch is read
        // lazily from the run's TestResult -- PHPUnit 8/9 expose no configuration seam, but the
        // flag is public there (the TimeLimit pattern).
        if ($testResult === null
            || !Attribution::$enabled
            || !$testResult->isStrictAboutTestsThatDoNotTestAnything()) {
            return;
        }

        foreach (AssertionCountListener::recordedTests() as $key => $test) {
            if (isset(self::$alreadyFlagged[$key]) || isset(self::$aborted[$key])) {
                continue;
            }

            // A test whose coroutine ran unattributed code has an untrustworthy tally;
            // reporting it either way would be a guess.
            if (Attribution::unattributedFor($key)) {
                continue;
            }

            $count = Counit::correctedAssertionCountFor($key);
            if ($count === null) {
                continue;
            }

            // Read now, not at record time: an expectNotToPerformAssertions() call anywhere in
            // the body has happened by the time the coroutines drained, so the declaration is
            // final -- the same reason the credit resolves it only after create() returns.
            if ($test->doesNotPerformAssertions()) {
                if ($count > 0) {
                    $testResult->addFailure($test, new RiskyTestError(sprintf(
                        '%s%d assertions',
                        self::UNEXPECTED_MESSAGE_PREFIX,
                        $count
                    )), 0.0);
                }

                continue;
            }

            if ($count !== 0) {
                continue;
            }

            // The message carries the test method's location, built the same way
            // TestResult::run() builds it for the native verdict -- otherwise the risky listing
            // would point at counit's own code.
            try {
                $reflected = new \ReflectionClass($test);
                $name      = $test->getName(false);
                if ($name !== '' && $reflected->hasMethod($name)) {
                    $reflected = $reflected->getMethod($name);
                }
                $location = sprintf("\n\n%s:%d", (string) $reflected->getFileName(), (int) $reflected->getStartLine());
            } catch (\Throwable $t) {
                $location = '';
            }

            $testResult->addFailure($test, new RiskyTestError(self::MESSAGE . $location), 0.0);
        }
    }
}

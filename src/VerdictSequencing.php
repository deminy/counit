<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestResult;

/**
 * The run-level join switch for PHPUnit's verdict-sequencing options: the --stop-on-* family
 * (defect/error/failure/warning/risky/incomplete/skipped, and their stopOn* XML configuration
 * attributes).
 *
 * All of these make PHPUnit decide BETWEEN tests from the verdicts it has so far:
 * TestResult::shouldStop() is consulted before each test starts. Under counit a post-yield
 * verdict only exists once the whole test loop has finished, so the run never stopped --
 * --stop-on-failure reacted to pre-yield failures only, with everything else running to
 * completion. There is no partial fix (by the time a deferred verdict exists there is nothing
 * left to stop), so while any of these options is active, EVERY test is joined at its first
 * yield (the TimeLimit switch shape): each verdict is native and final before the next
 * scheduling decision, giving the options their exact blocking semantics at the price of the
 * run's concurrency (one STDERR notice announces this). Sequencing verdicts is inherently
 * serial. (--repeat is handled separately by the `counit` script, which runs repeated passes in
 * blocking mode outright; --retry does not exist on PHPUnit 8/9.)
 *
 * PHPUnit 8/9 have no extension seam that hands over the run's configuration, and TestResult
 * exposes only setters for these flags, so the state is read lazily from the first test object
 * seen -- via reflection of TestResult's private stopOn* properties -- and cached for the rest
 * of the run; on any reflection surprise the answer is false, degrading to counit's
 * pre-existing (non-joining) behavior, never breaking a run.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class VerdictSequencing
{
    /**
     * Whether a verdict-sequencing option is active in this run; null until first resolved from
     * a test's TestResult.
     *
     * @var bool|null
     */
    private static $active;

    /**
     * @var bool
     */
    private static $noticeIssued = false;

    /**
     * Whether tests must be joined at their first yield because PHPUnit sequences verdicts in
     * this run. Pass the test at hand when one is available; a call without one uses the cached
     * answer, and reports false until any test resolved it.
     */
    public static function activeForRun(?TestCase $test = null): bool
    {
        if (self::$active !== null) {
            return self::$active;
        }

        if (!$test instanceof TestCase) {
            return false;
        }

        $testResult = $test->getTestResultObject();
        if ($testResult === null) {
            return false;
        }

        $active = false;

        try {
            $flags = ['stopOnDefect', 'stopOnError', 'stopOnFailure', 'stopOnWarning', 'stopOnRisky', 'stopOnIncomplete', 'stopOnSkipped'];
            foreach ($flags as $name) {
                $property = new \ReflectionProperty(TestResult::class, $name);
                if (PHP_VERSION_ID < 80100) {
                    // A no-op since PHP 8.1 (and deprecated since 8.5), but required on the PHP
                    // 7.2 through 8.0 part of this branch's supported range.
                    $property->setAccessible(true);
                }

                if ($property->getValue($testResult) === true) {
                    $active = true;

                    break;
                }
            }
        } catch (\ReflectionException $e) {
            $active = false;
        }

        self::$active = $active;

        if ($active) {
            self::announceSerializedRun();
        }

        return $active;
    }

    /**
     * Announces -- once, to STDERR (excluded from the coroutine hooks, so it cannot yield) --
     * that the run is serialized. Silenced by COUNIT_SILENCE_TEARDOWN_NOTICE=1, like the other
     * counit notices.
     */
    private static function announceSerializedRun(): void
    {
        if (self::$noticeIssued || getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') !== false) {
            self::$noticeIssued = true;

            return;
        }
        self::$noticeIssued = true;

        fwrite(STDERR, 'counit notice: a verdict-sequencing option (--stop-on-*) is active, so every test is joined at its first yield to make each verdict final before PHPUnit\'s next scheduling decision -- the run is serialized (exact blocking semantics, no concurrency). Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.' . PHP_EOL);
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Whether a test has registered a mock object PHPUnit will actually verify -- the input to
 * counit's join decision for mock expectations in the manual approach.
 *
 * PHPUnit verifies every registered mock from runBare(), right after the test method returns.
 * The automatic approach is unaffected by counit: its whole runBare() -- verification included --
 * runs inside the test's coroutine, so the verification always covers the truly finished body
 * (post-yield violations surface through the deferred end-of-run block, like any other post-yield
 * failure on this branch). The manual approach's runBare() is PHPUnit's own, running on the main
 * coroutine, so its verification used to fire at the callable's first yield -- and it is not
 * read-only: a mock whose expectation was already satisfied at that instant passed verification
 * and then had its invocation mocker STRIPPED (__phpunit_verify() unsets it), so a later
 * over-invocation, or a never() violation, was silently allowed and the run still exited 0; a
 * mock not yet satisfied failed right there, a false "was not expected to be called" /
 * "actually called 0 times" failure for a test that would have satisfied it two lines later.
 *
 * Joining the test's coroutine (the same mechanism as for an exception or output expectation)
 * puts the test method's return back after the real body, so PHPUnit's own verification sees the
 * finished mock and classifies the verdict natively, on the main coroutine, in both directions.
 *
 * EVERY registered test double qualifies, deliberately wider than the 1.x branch's
 * invocation-count-rule gate: PHPUnit 8/9's verifyMockObjects() calls
 * __phpunit_verify($shouldReset) on every registered mock -- the __phpunit_hasMatchers() gate
 * there only controls the per-mock assertion count, not the verify-and-reset itself (verified on
 * 8.0.0 and 9.6; PHPUnit 10+ is where matcher-less mocks became skipped entirely). So on this
 * branch even a matcher-less createMock()/createStub() used as a plain stub was STRIPPED at the
 * manual test's first yield, its willReturn() configuration silently gone for the rest of the
 * body. The registry is a private, flat list on PHPUnit\Framework\TestCase (same name and shape
 * on 8.0 through 9.6) -- the one reflection point; if a future release changes it, the probe
 * reports "nothing to verify", which degrades to counit's pre-existing behavior rather than
 * breaking a run.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class MockExpectations
{
    /**
     * @var bool|null
     */
    private static $available;

    /**
     * @var bool
     */
    private static $noticeIssued = false;

    /**
     * @var \ReflectionProperty|null
     */
    private static $property;

    public static function isVerifiableFor(BaseTestCase $test): bool
    {
        if (self::$available === null) {
            self::$available = self::resolve();

            // Announced loudly (once, to STDERR -- excluded from the coroutine hooks) rather than
            // degrading silently: without the probe, a mock expectation satisfied only after the
            // callable's first yield falls back to a premature failure, and a violation after
            // that point is not reported at all. Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to mute,
            // like the other counit notices.
            if (!self::$available && !self::$noticeIssued && getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') === false) {
                self::$noticeIssued = true;
                fwrite(STDERR, 'counit notice: could not inspect PHPUnit\'s mock-object registry; a manual-approach mock expectation satisfied or violated after the test\'s first yield degrades to PHPUnit\'s premature verification.' . PHP_EOL);
            }
        }

        if (self::$available !== true || self::$property === null) {
            return false;
        }

        try {
            $registered = self::$property->getValue($test);

            if (!is_array($registered)) {
                return false;
            }

            foreach ($registered as $mock) {
                // Mirrors verifyMockObjects()'s reach on PHPUnit 8/9: every registered mock is
                // verified -- and, for an ordinary test, reset -- matcher or not, so every
                // registered double needs the join.
                if (is_object($mock)) {
                    return true;
                }
            }
        } catch (\Throwable $t) {
            return false;
        }

        return false;
    }

    private static function resolve(): bool
    {
        try {
            // Reflect the declaring class explicitly: a private property is invisible when
            // reflecting a subclass, which would silently defeat the whole check.
            if (property_exists(BaseTestCase::class, 'mockObjects')) {
                $property = new \ReflectionProperty(BaseTestCase::class, 'mockObjects');
                $property->setAccessible(true);

                self::$property = $property;

                return true;
            }
        } catch (\Throwable $t) {
            // Fall through: the probe reports "nothing to verify".
        }

        return false;
    }
}

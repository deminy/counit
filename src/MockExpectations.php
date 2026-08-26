<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Whether a test has registered a mock object PHPUnit will actually verify -- the input to
 * counit's join decision for mock expectations.
 *
 * PHPUnit verifies every registered mock from runBare(), immediately after runTest() returns --
 * under counit, the test body's first yield. That is not merely "too early": a mock whose
 * expectation is already satisfied at that instant passes verification and is then STRIPPED
 * (__phpunit_verify() unsets its invocation mocker), so for the rest of the body it is no longer
 * a mock at all -- its expectations, its willReturn()/willThrowException() configuration and its
 * counting are gone. A later over-invocation, or a never() violation, was silently allowed and
 * the run still exited 0; a mock whose expectation was not yet satisfied failed verification
 * right there, producing a false "was never invoked" failure for a test that would have
 * satisfied it two lines later.
 *
 * Joining the test's coroutine (the #[Depends]-producer mechanism) puts runTest()'s return back
 * after the real body, so PHPUnit's own verification sees the finished mock and classifies the
 * verdict natively -- which is also why every existing join path (a #[Depends] producer, a
 * post-condition-customizing class, --enforce-time-limit, ...) already got mocks right by
 * accident. Relocating the verification into the coroutine instead is impossible, not merely
 * undesirable: besides the verdict-derivation objection recorded for the post-condition phase
 * (PHPUnit derives the verdict from whether the verification threw, so a relocated failure could
 * only ever be deferred), runBare() clears the mock registry right after the after-test hooks --
 * by the coroutine's end there is nothing left to verify.
 *
 * Only mocks carrying an invocation-count rule qualify: PHPUnit skips (and, crucially, does not
 * strip) everything else, so plain stubs and mocks configured with only a parameters rule keep
 * working exactly as they do today, without paying for a join.
 *
 * PHPUnit keeps the registry in a private property of the same name and shape on both supported
 * lines (12.5 and 13); if it ever changes, the probe reports "nothing to verify", which degrades
 * to counit's pre-existing behavior rather than breaking a run.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class MockExpectations
{
    private static ?bool $available = null;

    private static bool $noticeIssued = false;

    private static ?\ReflectionProperty $property = null;

    public static function isVerifiableFor(BaseTestCase $test): bool
    {
        if (self::$available === null) {
            self::resolve();

            // Announced loudly (once, to STDERR -- excluded from the coroutine hooks) rather than
            // degrading silently: without the probe, a mock expectation satisfied only after the
            // test's first yield falls back to a premature "was never invoked" failure, and a
            // violation after that point is not reported at all. Set
            // COUNIT_SILENCE_TEARDOWN_NOTICE=1 to mute, as for the other internals-dependent
            // degradations.
            if (self::$available === false && !self::$noticeIssued && getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') === false) {
                self::$noticeIssued = true;
                fwrite(STDERR, 'counit notice: could not inspect PHPUnit\'s mock-object registry; a mock expectation satisfied or violated after the test\'s first yield degrades to PHPUnit\'s premature verification.' . PHP_EOL);
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

            foreach ($registered as $entry) {
                $mock = is_array($entry) ? ($entry['mockObject'] ?? null) : $entry;

                // Mirrors verifyMockObjects()'s own gate: everything without an invocation-count
                // rule is skipped there -- never verified, never stripped -- so it needs no join.
                // __phpunit_hasInvocationCountRule() lives on PHPUnit's internal mock interface,
                // not on the public MockObject one; probe for it rather than depending on an
                // @internal type.
                if (!is_object($mock) || !method_exists($mock, '__phpunit_hasInvocationCountRule')) {
                    continue;
                }

                if ($mock->__phpunit_hasInvocationCountRule()) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private static function resolve(): void
    {
        self::$available = false;

        try {
            // Reflect the declaring class explicitly: a private property is invisible when
            // reflecting a subclass, which would silently defeat the whole check.
            if (property_exists(BaseTestCase::class, 'mockObjects')) {
                self::$property  = new \ReflectionProperty(BaseTestCase::class, 'mockObjects');
                self::$available = true;
            }
        } catch (\Throwable) {
            self::$available = false;
        }
    }
}

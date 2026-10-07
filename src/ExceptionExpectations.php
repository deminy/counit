<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Whether a test has an exception expectation registered (expectException() and friends --
 * including PHPUnit 9's expectWarning()/expectError()/expectNotice()/expectDeprecation()
 * family, which funnels into the same expectedException state).
 *
 * Unlike on the 1.x branch, no reflection is needed here: PHPUnit 8/9 expose the expectation
 * state through public (if @internal) getters, stable across the whole supported range. The
 * method_exists() guards make a missing getter degrade to "no expectation" -- counit's
 * pre-existing behavior -- rather than break a run.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class ExceptionExpectations
{
    /**
     * The public getters over PHPUnit 8/9's four expectation properties.
     */
    private const GETTERS = [
        'getExpectedException',
        'getExpectedExceptionMessage',
        'getExpectedExceptionMessageRegExp',
        'getExpectedExceptionCode',
    ];

    public static function isRegisteredFor(BaseTestCase $test): bool
    {
        try {
            // Every supported release has getExpectedException(); the other getters vary by
            // version (getExpectedExceptionMessageRegExp() is gone in PHPUnit 9), so only this
            // one missing means the internals have changed.
            if (!method_exists($test, 'getExpectedException')) { // @phpstan-ignore function.alreadyNarrowedType
                self::announceUnreadableExpectations();
            }

            foreach (self::GETTERS as $getter) {
                if (method_exists($test, $getter) && $test->{$getter}() !== null) {
                    return true;
                }
            }
        } catch (\Throwable $t) {
            self::announceUnreadableExpectations();

            return false;
        }

        return false;
    }

    private static function announceUnreadableExpectations(): void
    {
        Helper::notice('exception-expectations', 'could not read a test\'s exception expectation (PHPUnit\'s internals have changed); an exception expected after the test\'s first yield may be reported as an error.');
    }
}

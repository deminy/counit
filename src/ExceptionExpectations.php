<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Whether a test has an exception expectation registered (expectException() and friends).
 *
 * PHPUnit keeps it private on TestCase, in two different shapes across the versions counit
 * supports: a single ExceptionExpectation object (PHPUnit 13) or four scalar properties
 * (PHPUnit 12.5). Both are resolved once per process; if neither is found the probe reports
 * "no expectation", which degrades to counit's pre-existing behavior rather than breaking a run.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class ExceptionExpectations
{
    /**
     * PHPUnit 12.5's four private expectation properties, in declaration order.
     */
    private const SCALAR_PROPERTIES = [
        'expectedException',
        'expectedExceptionMessage',
        'expectedExceptionMessageRegExp',
        'expectedExceptionCode',
    ];

    private static ?string $mode = null;

    private static bool $noticeIssued = false;

    private static ?\ReflectionProperty $objectProperty = null;

    /**
     * @var list<\ReflectionProperty>
     */
    private static array $scalarProperties = [];

    public static function isRegisteredFor(BaseTestCase $test): bool
    {
        if (self::$mode === null) {
            self::resolve();

            // Announced loudly (once, to STDERR -- excluded from the coroutine hooks) rather than
            // degrading silently: without the probe, a test whose expected exception is thrown
            // only after a sleep/IO yield falls back to failing with "exception not thrown" plus
            // a deferred duplicate. Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to mute, as for the
            // other internals-dependent degradations.
            if (self::$mode === 'unavailable' && !self::$noticeIssued && getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') === false) {
                self::$noticeIssued = true;
                fwrite(STDERR, 'counit notice: could not inspect PHPUnit\'s exception-expectation state; expectException() with a throw after the test\'s first yield degrades to a premature "exception not thrown" failure plus a deferred report.' . PHP_EOL);
            }
        }

        try {
            if (self::$mode === 'object' && self::$objectProperty !== null) {
                $expectation = self::$objectProperty->getValue($test);

                // shouldBeVerifiedFor() is public and answers exactly the question asked here for
                // a throwable that is not one of PHPUnit's own: is any expectation registered?
                return is_object($expectation)
                    && method_exists($expectation, 'shouldBeVerifiedFor')
                    && $expectation->shouldBeVerifiedFor(new \RuntimeException());
            }

            if (self::$mode === 'scalars') {
                foreach (self::$scalarProperties as $property) {
                    if ($property->getValue($test) !== null) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            return false;
        }

        return false;
    }

    private static function resolve(): void
    {
        self::$mode = 'unavailable';

        try {
            if (property_exists(BaseTestCase::class, 'exceptionExpectation')) {
                self::$objectProperty = new \ReflectionProperty(BaseTestCase::class, 'exceptionExpectation');
                self::$mode           = 'object';

                return;
            }

            if (property_exists(BaseTestCase::class, self::SCALAR_PROPERTIES[0])) {
                foreach (self::SCALAR_PROPERTIES as $name) {
                    if (property_exists(BaseTestCase::class, $name)) {
                        self::$scalarProperties[] = new \ReflectionProperty(BaseTestCase::class, $name);
                    }
                }
                self::$mode = self::$scalarProperties === [] ? 'unavailable' : 'scalars';
            }
        } catch (\Throwable) {
            self::$mode = 'unavailable';
        }
    }
}

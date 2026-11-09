<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\TestCase;

/**
 * Regression guard for exceptions of a type registered with registerFailureType(), which PHPUnit
 * reports as a test FAILURE rather than an error. counit used to report one thrown after a yield
 * as an error, and as of PHPUnit 12.5.43 / 13.4.6 -- which no longer flag a failed test as risky
 * for performing no assertions -- still flagged such a test as risky. Run by the compatibility
 * workflow with mode-identical assertions (the risky count depends on the PHPUnit version).
 *
 * @internal
 * @coversNothing
 */
class RegisteredFailureTypeTest extends TestCase
{
    protected function setUp(): void
    {
        $this->registerFailureType(RegisteredDomainFailure::class);
    }

    public function testThrowsAfterYieldWithAssertion(): void
    {
        self::assertTrue(true);
        sleep(1);

        throw new RegisteredDomainFailure('deliberate registered failure after a yield');
    }

    public function testThrowsWithoutYieldOrAssertion(): void
    {
        throw new RegisteredDomainFailure('deliberate registered failure before any yield');
    }

    public function testThrowsAfterYieldWithoutAssertion(): void
    {
        sleep(1);

        throw new RegisteredDomainFailure('deliberate registered failure after a yield, no assertion');
    }
}

final class RegisteredDomainFailure extends \RuntimeException
{
}

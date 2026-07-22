<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Swoole\Constant;
use Swoole\Coroutine;

/**
 * @internal this class is not covered by the backward compatibility promise for counit
 * @coversNothing
 */
class TestCase extends BaseTestCase
{
    /**
     * @var array<string, mixed>
     */
    protected static $coroutineOptions = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (Helper::isCoroutineFriendly()) {
            static::$coroutineOptions = Coroutine::getOptions() ?? [];
            // Swoole only honors hook flags configured before the coroutine scheduler starts (the
            // `counit` script sets the authoritative value; see Helper::coroutineHookFlags()), so
            // this call is a no-op on current Swoole versions -- it is kept so the intended flags
            // are stated wherever coroutine options are touched, should that behavior change.
            Coroutine::set([Constant::OPTION_HOOK_FLAGS => Helper::coroutineHookFlags()]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (Helper::isCoroutineFriendly() && !empty(static::$coroutineOptions)) {
            Coroutine::set(static::$coroutineOptions);
        }
        parent::tearDownAfterClass();
    }

    /**
     * {@inheritDoc}
     *
     * PHPUnit 10+ made TestCase::runBare() final, so the per-test coroutine wrapping is now
     * applied through invokeTestMethod() (added in PHPUnit 13), the sanctioned hook for
     * customizing test method invocation. Note this wraps only the test method itself;
     * setUp()/tearDown() run outside the coroutine. See Counit::create() for how a Throwable
     * thrown by the wrapped test method -- whether synchronously or after a sleep()/IO yield -- is
     * handled without crashing the process or letting a real failure silently pass as "OK".
     *
     * @param array<mixed> $testArguments
     */
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        if (Helper::isCoroutineFriendly()) {
            // The second argument credits this test with one assertion up front. That suppresses
            // the "This test did not perform any assertions" warning (the test's real assertions
            // usually run after PHPUnit has already read its count; see Counit::create()) without
            // using expectNotToPerformAssertions(), which would instead flag the test as risky
            // whenever one of its assertions happens to run early. The credit is subtracted again
            // from the run's total by CounitExtension's end-of-run correction.
            Counit::create(function () use ($methodName, $testArguments): void {
                parent::invokeTestMethod($methodName, $testArguments);
            }, 1);

            return null;
        }

        return parent::invokeTestMethod($methodName, $testArguments);
    }
}

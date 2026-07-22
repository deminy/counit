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
            Coroutine::set([Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL]);
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
     * setUp()/tearDown() run outside the coroutine.
     *
     * Swoole does not propagate an uncaught Throwable from a Coroutine::create() callback back to
     * the caller; it becomes a fatal error that kills the whole process instead. Because of that,
     * the test method is invoked inside a try/catch block that runs inside the coroutine, and any
     * Throwable it catches is re-thrown afterward from this (non-coroutine) context, where
     * PHPUnit's own exception handling (e.g. for expectException()) can see it.
     *
     * @param array<mixed> $testArguments
     */
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        if (Helper::isCoroutineFriendly()) {
            $this->expectNotToPerformAssertions(); // To suppress warning message "This test did not perform any assertions".

            $caught = null;
            Counit::create(function () use ($methodName, $testArguments, &$caught): void {
                try {
                    parent::invokeTestMethod($methodName, $testArguments);
                } catch (\Throwable $e) {
                    $caught = $e;
                }
            });

            if ($caught !== null) {
                throw $caught;
            }

            return null;
        }

        return parent::invokeTestMethod($methodName, $testArguments);
    }
}

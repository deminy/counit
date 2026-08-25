<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\Attributes\After;
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
    protected static array $coroutineOptions = [];

    /**
     * Names of classes the after-test-hooks notice was already issued (or checked) for.
     *
     * @var array<class-string, true>
     */
    private static array $afterHookNoticeIssued = [];

    #[\Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (Helper::isCoroutineFriendly()) {
            self::warnAboutEarlyAfterTestHooks();
            static::$coroutineOptions = Coroutine::getOptions() ?? [];
            // Swoole only honors hook flags configured before the coroutine scheduler starts (the
            // `counit` script sets the authoritative value; see Helper::coroutineHookFlags()), so
            // this call is a no-op on current Swoole versions -- it is kept so the intended flags
            // are stated wherever coroutine options are touched, should that behavior change.
            Coroutine::set([Constant::OPTION_HOOK_FLAGS => Helper::coroutineHookFlags()]);
        }
    }

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        if (Helper::isCoroutineFriendly() && static::$coroutineOptions !== []) {
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
    #[\Override]
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        if (Helper::isCoroutineFriendly()) {
            // The second argument requests one assertion credit for this test. That suppresses
            // the "This test did not perform any assertions" warning (the test's real assertions
            // usually run after PHPUnit has already read its count; see Counit::create()) without
            // using expectNotToPerformAssertions(), which would instead flag the test as risky
            // whenever one of its assertions happens to run early. Counit::create() declines the
            // credit for a test that declares -- through #[DoesNotPerformAssertions] or
            // expectNotToPerformAssertions() -- that it performs no assertions, so such tests are
            // not falsely reported as risky. Applied credits are subtracted again from the run's
            // total by CounitExtension's end-of-run correction.
            Counit::create(function () use ($methodName, $testArguments): void {
                try {
                    parent::invokeTestMethod($methodName, $testArguments);
                } finally {
                    // Runs inside the coroutine, strictly after the test body -- pass or fail --
                    // unlike tearDown(), which PHPUnit's runBare() (final) invokes on the main
                    // coroutine as soon as this callable first yields. A Throwable thrown here
                    // before the first yield errors the test synchronously; after a yield it is
                    // queued as a deferred failure and forces exit code 1.
                    $this->tearDownCoroutine();
                }
            }, 1);

            return null;
        }

        try {
            return parent::invokeTestMethod($methodName, $testArguments);
        } finally {
            // Same ordering in blocking mode -- right after the test body, before PHPUnit's own
            // tearDown() -- so the hook behaves identically in both modes, including in the
            // non-coroutine child process of a process-isolated test.
            $this->tearDownCoroutine();
        }
    }

    /**
     * Per-test cleanup hook that is guaranteed to observe a finished test body. Override this
     * instead of tearDown() for cleanup that touches state the test body still needs after a
     * sleep()/IO yield (e.g. closing a database connection, or truncating tables the body still
     * reads): under counit, PHPUnit invokes tearDown() as soon as the test body first yields --
     * possibly while the body is still running -- while this method runs inside the test's
     * coroutine, strictly after the body, in both coroutine and blocking mode. See also
     * Counit::defer() for the manual approach's equivalent.
     */
    protected function tearDownCoroutine(): void
    {
    }

    /**
     * Under counit, PHPUnit invokes tearDown() and #[After] methods as soon as the test body first
     * yields -- possibly while the body is still running. There is no seam to change that
     * (runBare() is final and invokes them inline), so the best counit can do is make the hazard
     * visible: warn once per class when such hooks are declared, pointing at tearDownCoroutine().
     * Set the environment variable COUNIT_SILENCE_TEARDOWN_NOTICE=1 to mute the notice. Written to
     * STDERR, which is excluded from the coroutine hooks and therefore cannot yield.
     */
    private static function warnAboutEarlyAfterTestHooks(): void
    {
        if (isset(self::$afterHookNoticeIssued[static::class]) || getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') !== false) {
            self::$afterHookNoticeIssued[static::class] = true;

            return;
        }
        self::$afterHookNoticeIssued[static::class] = true;

        $hooks     = [];
        $reflector = new \ReflectionClass(static::class);

        $tearDown = $reflector->getMethod('tearDown');
        if ($tearDown->getDeclaringClass()->getName() !== BaseTestCase::class) {
            $hooks[] = sprintf('%s::tearDown()', $tearDown->getDeclaringClass()->getName());
        }

        foreach ($reflector->getMethods() as $method) {
            if ($method->getAttributes(After::class) !== []) {
                $hooks[] = sprintf('%s::%s() (#[After])', $method->getDeclaringClass()->getName(), $method->getName());
            }
        }

        if ($hooks !== []) {
            fwrite(STDERR, sprintf(
                'counit notice: %s declares after-test hooks (%s) that PHPUnit runs as soon as a test body first yields -- possibly while the body is still running. Move order-sensitive per-test cleanup into tearDownCoroutine() or Counit::defer(). Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.%s',
                static::class,
                implode(', ', $hooks),
                PHP_EOL
            ));
        }
    }
}

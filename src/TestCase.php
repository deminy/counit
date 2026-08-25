<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\ClassMethod;
use PHPUnit\Event\Code\ThrowableBuilder;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SkippedTest;
use PHPUnit\Framework\TestCase as BaseTestCase;
use PHPUnit\Metadata\Api\HookMethods;
use PHPUnit\Runner\HookMethod;
use PHPUnit\Runner\HookMethodCollection;
use Swoole\Constant;
use Swoole\Coroutine;

/**
 * @internal this class is not covered by the backward compatibility promise for counit
 * @coversNothing
 */
class TestCase extends BaseTestCase
{
    /**
     * A method name no test class declares. Pointing a class's "after" hook collection at it makes
     * PHPUnit's own hook invoker skip the whole after-test phase (its skip rule drops any name the
     * class does not declare), which is what allows the relocated hooks to run inside the test's
     * coroutine instead. See takeOverAfterTestHooks().
     */
    private const AFTER_HOOKS_SUPPRESSED = '__counitAfterTestHooksRunInsideTheCoroutine';

    /**
     * @var array<string, mixed>
     */
    protected static array $coroutineOptions = [];

    /**
     * Per concrete test class: the after-test hook methods (tearDown() and #[After] methods, in
     * PHPUnit's invocation order) that counit took over from PHPUnit and runs inside the test's
     * coroutine instead. An empty list means there was nothing to take over -- or the takeover
     * failed, in which case PHPUnit's own (too early) invocation was left fully intact.
     *
     * @var array<class-string, list<non-empty-string>>
     */
    private static array $relocatedAfterHooks = [];

    /**
     * Names of classes the takeover-failure notice was already issued for.
     *
     * @var array<class-string, true>
     */
    private static array $afterHookNoticeIssued = [];

    #[\Override]
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
     * customizing test method invocation. This wraps the test method itself; setUp() runs outside
     * the coroutine, while the after-test hooks -- tearDown() and #[After] methods -- are taken
     * over from PHPUnit and run inside the coroutine, right after the test body (see
     * takeOverAfterTestHooks()). See Counit::create() for how a Throwable thrown by the wrapped
     * test method -- whether synchronously or after a sleep()/IO yield -- is handled without
     * crashing the process or letting a real failure silently pass as "OK".
     *
     * @param array<mixed> $testArguments
     */
    #[\Override]
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        if (Helper::isCoroutineFriendly()) {
            // Deliberately lazy: runBare() (final) captured the per-class hook *collection
            // objects* before the test started, so mutating a collection here still affects the
            // ones it holds -- and doing it here, rather than in setUpBeforeClass(), keeps the
            // takeover independent of consumers remembering to call parent::setUpBeforeClass().
            self::takeOverAfterTestHooks();

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
                    try {
                        // Runs inside the coroutine, strictly after the test body -- pass or fail
                        // -- unlike PHPUnit's own hook invocation, which runBare() (final) issues
                        // on the main coroutine as soon as this callable first yields. A Throwable
                        // thrown here before the first yield errors the test synchronously; after
                        // a yield it is queued as a deferred failure and forces exit code 1.
                        $this->tearDownCoroutine();
                    } finally {
                        // The relocated tearDown()/#[After] hooks run last, even when the body or
                        // tearDownCoroutine() threw -- the same guarantee PHPUnit itself gives.
                        $this->invokeRelocatedAfterTestHooks();
                    }
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
     * Takes the class's after-test hooks -- tearDown() and #[After] methods -- over from PHPUnit,
     * so they can run inside the test's coroutine right after the test body, instead of at the
     * body's first yield (runBare() is final and invokes them inline; there is no sanctioned seam
     * for the after-test phase, so this reaches into PHPUnit internals -- see the class constant).
     *
     * PHPUnit caches per-class hook *collection objects* (HookMethods keeps a static cache) and
     * runBare() captures those objects before the test runs, so replacing a collection's private
     * method list here still affects the collection runBare() already holds. The list is pointed
     * at a method name nothing declares, which PHPUnit's own skip rule then drops -- turning its
     * after-test phase into a no-op -- while the real hook names, resolved with the same skip rule
     * PHPUnit applies, are remembered for invokeRelocatedAfterTestHooks(). On any failure (e.g. a
     * future PHPUnit rename of these internals), everything is left untouched and the degradation
     * is announced loudly instead of silently reverting to mid-test hook execution.
     */
    private static function takeOverAfterTestHooks(): void
    {
        if (isset(self::$relocatedAfterHooks[static::class])) {
            return;
        }
        self::$relocatedAfterHooks[static::class] = [];

        try {
            $collection = (new HookMethods())->hookMethods(static::class)['after'];
            $reflector  = new \ReflectionClass(static::class);
            $names      = [];

            foreach ($collection->methodNamesSortedByPriority() as $name) {
                // Same skip rule as PHPUnit's own hook invoker.
                if (!$reflector->hasMethod($name) || $reflector->getMethod($name)->getDeclaringClass()->getName() === BaseTestCase::class) {
                    continue;
                }
                $names[] = $name;
            }

            if ($names === []) {
                return; // Nothing to relocate: PHPUnit's machinery stays completely untouched.
            }

            $property = new \ReflectionProperty(HookMethodCollection::class, 'hookMethods');
            $original = $property->getValue($collection);
            $property->setValue($collection, [new HookMethod(self::AFTER_HOOKS_SUPPRESSED, 0)]);

            // Self-check that the suppression actually took effect; restore and degrade loudly
            // otherwise, so the hooks are never invoked twice nor dropped entirely.
            if ($collection->methodNamesSortedByPriority() !== [self::AFTER_HOOKS_SUPPRESSED]) {
                $property->setValue($collection, $original);
                self::warnAfterTestHookTakeoverFailed($names);

                return;
            }

            self::$relocatedAfterHooks[static::class] = $names;
        } catch (\Throwable) {
            self::warnAfterTestHookTakeoverFailed([]);
        }
    }

    /**
     * Invokes the after-test hooks taken over from PHPUnit, mirroring its own invocation exactly:
     * same order, same events (afterTestMethodCalled/Failed/Errored/Finished), same
     * stop-at-first-failing-hook rule, same failure classification. The one deliberate difference:
     * a Throwable from a hook is never rethrown into the test-body path -- PHPUnit would match it
     * against the test's expectException() expectation there (letting a test falsely pass on an
     * exception its tearDown() threw), so it is queued as a deferred failure instead, reported
     * after the summary with exit code 1.
     */
    private function invokeRelocatedAfterTestHooks(): void
    {
        $methods = self::$relocatedAfterHooks[static::class] ?? [];
        if ($methods === []) {
            return;
        }

        $emitter        = EventFacade::emitter();
        $eventTest      = $this->valueObjectForEvents();
        $reflector      = new \ReflectionClass(static::class);
        $methodsInvoked = [];
        $thrown         = null;

        foreach ($methods as $methodName) {
            $methodInvoked = new ClassMethod(static::class, $methodName);
            $hookMethod    = $reflector->getMethod($methodName);
            $failure       = null;

            try {
                if ($hookMethod->isStatic()) {
                    $hookMethod->invoke(null);
                } else {
                    $hookMethod->invoke($this);
                }
            } catch (\Throwable $t) {
                $failure = $t;
            }

            $emitter->afterTestMethodCalled($eventTest, $methodInvoked);
            $methodsInvoked[] = $methodInvoked;

            if ($failure instanceof SkippedTest) {
                // PHPUnit would mark the test as skipped, but its verdict was already emitted at
                // the body's first yield; a skip signal from cleanup is dropped rather than
                // reported as a failure of the run.
                continue;
            }

            if ($failure !== null) {
                if ($failure instanceof AssertionFailedError) {
                    $emitter->afterTestMethodFailed($eventTest, $methodInvoked, ThrowableBuilder::from($failure));
                } else {
                    $emitter->afterTestMethodErrored($eventTest, $methodInvoked, ThrowableBuilder::from($failure));
                }
                $thrown = $failure;

                break; // PHPUnit stops at the first failing after-test hook.
            }
        }

        // Unlike PHPUnit's own loop, this one appends to $methodsInvoked on every iteration (the
        // skip rule was already applied at takeover time), so the list is never empty here.
        $emitter->afterTestMethodFinished($eventTest, ...$methodsInvoked);

        if ($thrown !== null) {
            Counit::$deferredFailures[sprintf('%s::%s (after-test hooks)', static::class, $this->nameWithDataSet())] = $thrown;
        }
    }

    /**
     * Announces -- once per class, to STDERR (excluded from the coroutine hooks, so it cannot
     * yield) -- that the after-test hooks could not be taken over from PHPUnit, meaning tearDown()
     * and #[After] methods will run as soon as a test body first yields, possibly while the body
     * is still running. Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to mute the notice.
     *
     * @param list<non-empty-string> $hooks
     */
    private static function warnAfterTestHookTakeoverFailed(array $hooks): void
    {
        if (isset(self::$afterHookNoticeIssued[static::class]) || getenv('COUNIT_SILENCE_TEARDOWN_NOTICE') !== false) {
            self::$afterHookNoticeIssued[static::class] = true;

            return;
        }
        self::$afterHookNoticeIssued[static::class] = true;

        fwrite(STDERR, sprintf(
            'counit notice: could not take over the after-test hooks of %s%s; PHPUnit will run them as soon as a test body first yields -- possibly while the body is still running. Move order-sensitive per-test cleanup into tearDownCoroutine() or Counit::defer(). Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.%s',
            static::class,
            $hooks === [] ? '' : sprintf(' (%s)', implode(', ', $hooks)),
            PHP_EOL
        ));
    }
}

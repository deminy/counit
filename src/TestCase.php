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
     * Per concrete test class whose after-test hooks were successfully taken over: the cached
     * hook collection object, its original private method list, and whether PHPUnit's own
     * invocation is currently suppressed. Kept so the suppression can be flipped per test: a
     * joined #[Depends] producer hands the hooks back to PHPUnit for its own run (see
     * setAfterTestHookSuppression()), and the next test of the class re-suppresses them.
     *
     * @var array<class-string, array{collection: HookMethodCollection, original: mixed, suppressed: bool}>
     */
    private static array $takenOverAfterHookState = [];

    /**
     * Names of classes the takeover-failure notice was already issued for.
     *
     * @var array<class-string, true>
     */
    private static array $afterHookNoticeIssued = [];

    /**
     * Whether the most recently prepared test reached invokeTestMethod(). Cleared at each test's
     * PreparationStarted event (see CounitExtension), set again when the body is invoked; a test
     * still false when its verdict is emitted was aborted during preparation -- setUp() or another
     * before-test hook threw or skipped -- and needs its after-test hooks handed back to PHPUnit
     * (see handleAbortedTestPreparation()). Tests run sequentially through runBare() on the main
     * coroutine, so one flag suffices; true initially and after every handling, so verdict events
     * that never had a preparation (e.g. a test skipped over a failed dependency) are ignored.
     */
    private static bool $currentTestBodyInvoked = true;

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
     * Clears the body-invoked marker at the start of each test's preparation phase; see
     * $currentTestBodyInvoked.
     *
     * @internal called by CounitExtension at the Test\PreparationStarted event; not part of
     *           counit's public API
     */
    public static function markTestPreparationStarted(): void
    {
        self::$currentTestBodyInvoked = false;
    }

    /**
     * Reacts to a test's verdict (errored/failed/skipped/incomplete) being emitted while its body
     * never reached invokeTestMethod(): the test was aborted during preparation -- setUp() or
     * another before-test hook threw or skipped. Such a test never started a coroutine, so the
     * relocated replay of its taken-over after-test hooks would never run; instead, the class's
     * original hook list is restored here. runBare() emits the verdict BEFORE its native
     * after-test hook phase, so PHPUnit then runs the real hooks itself -- synchronously, with
     * its exact blocking semantics: tearDown() still runs, a Throwable it raises is swallowed
     * when the test already errored, and turns a test its setUp() merely skipped into an error.
     * The class's next test re-suppresses the hooks through the lazy takeover path.
     *
     * @internal called by CounitExtension at the Test\Errored/Failed/Skipped/MarkedIncomplete
     *           events; not part of counit's public API
     *
     * @param class-string $className
     */
    public static function handleAbortedTestPreparation(string $className): void
    {
        if (self::$currentTestBodyInvoked || !isset(self::$takenOverAfterHookState[$className])) {
            return;
        }

        // The same aborted test can emit a second verdict event (a restored tearDown() throwing
        // after a setUp() skip re-errors the test); handle only the first.
        self::$currentTestBodyInvoked = true;

        // Cannot realistically fail (see setAfterTestHookSuppression()); if it ever does, this
        // one test loses its after-test hooks -- degrading to the pre-restore behavior, never
        // running anything twice.
        self::setAfterTestHookSuppression(false, $className);
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
        // Reaching this method means the test survived its whole preparation phase (setUp() and
        // the other before-test hooks); see handleAbortedTestPreparation() for the tests that
        // do not.
        self::$currentTestBodyInvoked = true;

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
            // A test something #[Depends] on cannot be allowed to merely start here: PHPUnit
            // records its return value and its verdict into PassedTests at the end of runBare()
            // -- which, with the body still in flight, would be null and "passed" -- and resolves
            // the dependent's input from there in TestCase::run(), before any counit seam. So a
            // producer's coroutine is joined before returning: its dependents see the real value,
            // and a producer that only fails after a yield skips them exactly as in blocking mode.
            // It costs that one test its own concurrency; every other test still overlaps with it,
            // including while it waits.
            if (DependencyMap::isProducer(static::class, $this->name())) {
                // The body will have fully run before this method returns, so PHPUnit's own
                // after-test hook timing is correct again for this one test: hand the hooks back
                // to the native invocation, which also restores its exact error semantics -- a
                // throwing tearDown() errors the test, instead of landing in the deferred
                // end-of-run block the relocated replay must use. The next test of the class
                // re-suppresses the hooks through the lazy takeover path above; should the
                // restore itself ever fail, the replay is used, exactly as for non-joined tests.
                $restored = self::setAfterTestHookSuppression(false, static::class);

                return Counit::createAndJoin(function () use ($methodName, $testArguments, $restored): mixed {
                    try {
                        return parent::invokeTestMethod($methodName, $testArguments);
                    } finally {
                        try {
                            $this->tearDownCoroutine();
                        } finally {
                            if (!$restored) {
                                $this->invokeRelocatedAfterTestHooks();
                            }
                        }
                    }
                });
            }

            // Set by the join callback below when this test turns out to carry an exception
            // expectation: its body then runs to completion inside invokeTestMethod(), so
            // PHPUnit's own after-test hook timing is correct again and the hooks are handed back
            // to the native invocation -- exactly as for a joined #[Depends] producer.
            $useNativeAfterHooks = false;

            Counit::create(function () use ($methodName, $testArguments, &$useNativeAfterHooks): void {
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
                        // Skipped when the hooks were handed back to PHPUnit for this test (see
                        // $useNativeAfterHooks), so they can never run twice.
                        // Flipped by reference from the join callback below, before this
                        // coroutine can resume -- invisible to PHPStan.
                        if (!$useNativeAfterHooks) { // @phpstan-ignore booleanNot.alwaysTrue
                            $this->invokeRelocatedAfterTestHooks();
                        }
                    }
                }
            }, 1, function () use (&$useNativeAfterHooks): void {
                $useNativeAfterHooks = self::setAfterTestHookSuppression(false, static::class);
            });

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
            // A joined #[Depends] producer hands the hooks back to PHPUnit for its own run;
            // re-suppress them for this test (a no-op when they are already suppressed). Should
            // the re-suppression ever fail, the class degrades to the failed-takeover mode --
            // hooks run natively (early), exactly once, with the loud notice -- rather than
            // risking PHPUnit's native invocation AND the relocated replay both running.
            if (!self::setAfterTestHookSuppression(true, static::class)) {
                $hooks                                    = self::$relocatedAfterHooks[static::class];
                self::$relocatedAfterHooks[static::class] = [];
                unset(self::$takenOverAfterHookState[static::class]);
                self::warnAfterTestHookTakeoverFailed($hooks);
            }

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

            self::$relocatedAfterHooks[static::class]      = $names;
            self::$takenOverAfterHookState[static::class]  = [
                'collection' => $collection,
                'original'   => $original,
                'suppressed' => true,
            ];
        } catch (\Throwable) {
            self::warnAfterTestHookTakeoverFailed([]);
        }
    }

    /**
     * Suppresses or restores PHPUnit's own invocation of the class's taken-over after-test hooks,
     * by pointing the cached hook collection back at its original method list (or at the poison
     * name again). A joined #[Depends] producer restores the native invocation for its own run:
     * its body is fully finished inside invokeTestMethod(), so PHPUnit's hook timing is correct
     * again there, along with its exact error handling -- a throwing tearDown() errors the test,
     * just as in blocking mode. Returns whether the collection is now in the requested state
     * (true also when there is nothing to flip, because no hooks were taken over for the class).
     * The class is an explicit parameter because handleAbortedTestPreparation() flips the state
     * of a class it only knows by name, from outside any late-static-binding context.
     *
     * @param class-string $className
     */
    private static function setAfterTestHookSuppression(bool $suppressed, string $className): bool
    {
        $state = self::$takenOverAfterHookState[$className] ?? null;
        if ($state === null || $state['suppressed'] === $suppressed) {
            return true;
        }

        try {
            // No post-write self-check here, unlike the takeover itself: the takeover already
            // verified that suppressing THIS collection through THIS property works, and a plain
            // property write on a verified collection cannot half-succeed.
            (new \ReflectionProperty(HookMethodCollection::class, 'hookMethods'))->setValue(
                $state['collection'],
                $suppressed ? [new HookMethod(self::AFTER_HOOKS_SUPPRESSED, 0)] : $state['original']
            );
            self::$takenOverAfterHookState[$className]['suppressed'] = $suppressed;

            return true;
        } catch (\Throwable) {
            // The takeover self-check passed for this collection, so this cannot realistically
            // fail; if it ever does, the caller falls back to the relocated replay.
            return false;
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
                // Blocking PHPUnit treats a skip signalled from an after-test hook as a test
                // FAILURE (SkippedWithMessageException is an AssertionFailedError caught by
                // runBare()'s tearDown-phase handling) -- it never becomes a skip. This test's
                // verdict was already emitted at the body's first yield, so the closest match is
                // the deferred-failure path: reported after the summary, with exit code 1.
                // Mirroring PHPUnit's own loop, the skip stops neither hook invocation nor emits
                // a failed-hook event, and a later hook's Throwable takes precedence over it.
                // (A joined #[Depends] producer does not take this path: its hooks run natively,
                // with PHPUnit's own semantics.)
                $thrown = $failure;

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

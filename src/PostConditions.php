<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;
use PHPUnit\Metadata\Api\HookMethods;

/**
 * The join switch for test classes that customize PHPUnit's post-condition phase -- an overridden
 * assertPostConditions(), or any method carrying #[PostCondition].
 *
 * PHPUnit runs that phase from runBare(), immediately after runTest() returns -- under counit,
 * the test body's first yield -- so a custom post-condition hook used to inspect the test while
 * its body was still in flight: it saw pre-body state (failing loudly, or passing vacuously and
 * silently), and it ran even when the body failed only after a yield, where blocking PHPUnit
 * skips the phase entirely. Joining the test's coroutine (the #[Depends]-producer mechanism, via
 * the predicates in TestCase::invokeTestMethod() and Counit::create()) restores exact blocking
 * semantics: the phase follows the truly finished body, is skipped when the body failed, and a
 * throwing hook fails/errors/skips the test natively -- runBare()'s own catch ladder classifies
 * it, because the whole phase is PHPUnit's untouched code once the body no longer outlives
 * invokeTestMethod().
 *
 * The hooks cannot instead be relocated into the coroutine the way tearDown()/#[After] are
 * (although they live in the same HookMethodCollection machinery the takeover poisons): PHPUnit
 * derives the test's verdict from whether they threw, so a relocated failure could only ever be
 * deferred to the end of the run instead of failing the test -- an acceptable compromise for a
 * cleanup hook, not for a hook whose entire purpose is to fail the test. No drain barrier is
 * needed either, unlike GlobalState: the phase only touches the test's own object, never another
 * test's state.
 *
 * Detection mirrors PHPUnit's own invocation rule: HookMethodInvoker skips any hook method whose
 * declaring class is PHPUnit\Framework\TestCase itself, so the default, empty
 * assertPostConditions() is never even called and only a class that customizes the phase pays the
 * join. The check is per class (hooks are a class-level property, unlike GlobalState's per-method
 * resolution): an assertPostConditions() declared below PHPUnit's TestCase -- inherited overrides
 * included, counit's own TestCase excluded so declaring the method there some day would not
 * silently serialize every counit test -- or any #[PostCondition] method, which PHPUnit appends
 * to the same hook collection next to the always-present default entry.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class PostConditions
{
    /**
     * @var array<class-string, bool>
     */
    private static array $resolved = [];

    /**
     * Whether the given test class customizes the post-condition phase -- i.e. whether its tests
     * must be joined at their first yield.
     *
     * @param class-string $className
     */
    public static function isCustomizedFor(string $className): bool
    {
        if (isset(self::$resolved[$className])) {
            return self::$resolved[$className];
        }

        try {
            $result = self::resolve($className);
        } catch (\Throwable) {
            // Changed PHPUnit internals: keep the old (too early) timing rather than crashing
            // the run. Under-detecting only costs this fix's guarantees, never a crash.
            $result = false;
        }

        return self::$resolved[$className] = $result;
    }

    /**
     * @param class-string $className
     */
    private static function resolve(string $className): bool
    {
        $reflector = new \ReflectionClass($className);

        if ($reflector->hasMethod('assertPostConditions')) {
            $declaringClass = $reflector->getMethod('assertPostConditions')->getDeclaringClass()->getName();

            if (($declaringClass !== BaseTestCase::class) && ($declaringClass !== TestCase::class)) {
                return true;
            }
        }

        if (!is_subclass_of($className, BaseTestCase::class)) {
            return false;
        }

        // #[PostCondition] methods are appended to the same hook collection, next to the (always
        // present) assertPostConditions default entry -- so anything beyond that one entry is a
        // real hook. Reading the collection is free here: runBare() built and statically cached
        // it before the test method was invoked.
        return count((new HookMethods())->hookMethods($className)['postCondition']->methodNamesSortedByPriority()) > 1;
    }
}

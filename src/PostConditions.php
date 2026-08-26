<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;
use PHPUnit\Util\Test as TestUtil;

/**
 * The join switch for MANUAL-approach test classes that customize PHPUnit's post-condition phase
 * -- an overridden assertPostConditions(), or any method carrying the postCondition annotation
 * (which exists as of PHPUnit 9.1).
 *
 * PHPUnit 8/9 run that phase from runBare(), right after runTest()/verifyMockObjects() return.
 * For the automatic approach this branch was never broken: the WHOLE parent::runBare() runs
 * inside the test's coroutine, so the phase always follows the truly finished body -- with full
 * concurrency kept. The manual approach had the 1.x mistiming: its runBare() is PHPUnit's own,
 * running on the main coroutine, so the phase fired at the body's first yield -- the hooks
 * inspected the test while its body was still in flight (failing loudly, or passing vacuously
 * against pre-body state), and they ran even for a body that failed only after a yield, where
 * blocking PHPUnit skips the phase entirely (the body's Throwable jumps straight to runBare()'s
 * catch ladder). Joining the coroutine in Counit::create() -- the same mechanism as for
 * --enforce-time-limit and global-state backup, with the up-front assertion credit skipped --
 * restores exact blocking semantics: PHPUnit's own untouched code runs the phase after the real
 * body, skips it when the body failed, and classifies a throwing hook natively.
 *
 * Detection is per test class (hooks are a class-level property): an assertPostConditions()
 * whose declaring class is below PHPUnit's TestCase -- inherited overrides included, counit's own
 * TestCase excluded so declaring the method there some day would not silently serialize every
 * test routed through here -- or a postCondition hook list with entries beyond the always-present
 * assertPostConditions default (PHPUnit appends every annotated method to that same list). The
 * caller must scope this to manual-approach tests: the automatic approach's own
 * Counit::create(parent::runBare()) call needs no join and must keep its concurrency.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class PostConditions
{
    /**
     * @var array<string, bool> keyed by class name
     */
    private static $resolved = [];

    /**
     * Whether the given test class customizes the post-condition phase -- i.e. whether its
     * manual-approach tests must be joined at their first yield.
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
        } catch (\Throwable $t) {
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

        // Annotated hook methods are appended to the same per-class list as the (always present)
        // assertPostConditions default -- so anything beyond that one entry is a real hook.
        // PHPUnit caches the list statically, and runBare() reads the same cache. Before
        // PHPUnit 9.1 no such list exists at all (runBare() invokes assertPostConditions()
        // directly; the annotation does not exist upstream), hence the guard: only the override
        // check above applies there, exactly matching what PHPUnit itself invokes.
        $hookMethods = TestUtil::getHookMethods($className);

        return isset($hookMethods['postCondition']) && (count($hookMethods['postCondition']) > 1);
    }
}

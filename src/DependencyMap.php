<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\TestMethod;
use PHPUnit\Event\TestSuite\TestSuite;
use PHPUnit\Metadata\Api\Dependencies;

/**
 * Which test methods other tests depend on -- the reverse of PHPUnit's #[Depends] graph.
 *
 * counit needs this before a producer runs, not after: PHPUnit resolves a dependent's input in
 * TestCase::run() (final, and ahead of every seam counit has), reading the producer's recorded
 * return value and pass/fail verdict out of PassedTests. Under the coroutine runner a producer is
 * recorded there at its first yield -- with whatever invokeTestMethod() returned by then (null)
 * and a verdict that has not been decided yet -- so a dependent gets null and runs even when its
 * producer later fails. A producer therefore has to be *finished*, not merely started, before
 * PHPUnit moves on to the next test; TestCase::invokeTestMethod() joins its coroutine when this
 * map says something depends on it (see the "join" path there).
 *
 * The map is built once, from the TestSuite\Loaded event, whose test collection is already
 * flattened to leaf tests. #[DependsOnClass] marks the whole target class: the class counts as
 * passed only once its TestSuite\Finished fires, which must not happen while tests of that class
 * are still in flight. The event fires before --filter is applied, so a producer whose dependents
 * were all filtered out of the run is still joined -- a harmless loss of that one test's
 * concurrency, never a correctness issue.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class DependencyMap
{
    /**
     * "Class::method" of every test method something depends on.
     *
     * @var array<string, true>
     */
    private static array $producerMethods = [];

    /**
     * Classes something depends on as a whole (#[DependsOnClass]). Keyed by the class name as
     * PHPUnit's dependency metadata spells it (a plain string as far as the type system knows).
     *
     * @var array<string, true>
     */
    private static array $producerClasses = [];

    public static function build(TestSuite $testSuite): void
    {
        foreach ($testSuite->tests() as $test) {
            if (!$test instanceof TestMethod) {
                continue;
            }

            foreach (Dependencies::dependencies($test->className(), $test->methodName()) as $dependency) {
                if ($dependency->targetIsClass()) {
                    self::$producerClasses[$dependency->getTargetClassName()] = true;

                    continue;
                }

                self::$producerMethods[$dependency->getTarget()] = true;
            }
        }
    }

    /**
     * Does anything in the run depend on this test method (directly, or through its class)?
     *
     * @param class-string $className
     * @param non-empty-string $methodName
     */
    public static function isProducer(string $className, string $methodName): bool
    {
        if (isset(self::$producerClasses[$className])) {
            return true;
        }

        if (isset(self::$producerMethods[$className . '::' . $methodName])) {
            return true;
        }

        // A dependency may name a method inherited from a parent class, or be declared against a
        // parent while the run executes a subclass; match those too.
        foreach (self::$producerClasses as $producerClass => $unused) {
            if (is_subclass_of($className, $producerClass)) {
                return true;
            }
        }

        foreach (class_parents($className) ?: [] as $parent) {
            if (isset(self::$producerMethods[$parent . '::' . $methodName])) {
                return true;
            }
        }

        return false;
    }
}

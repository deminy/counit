<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\ExecutionOrderDependency;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Which test methods other tests depend on -- the reverse of PHPUnit's @depends graph.
 *
 * counit needs this before a producer runs, not after: PHPUnit resolves a dependent's input in
 * TestCase::run() (ahead of every seam counit has on the dependent's side), reading the
 * producer's recorded return value out of TestResult::passed() -- which endTest() fills with
 * whatever getResult() holds when the producer's runBare() returns. Under the coroutine runner
 * that is the producer's first yield: null for a producer that computes its value only after a
 * sleep/IO call, and a "passed" verdict even when the producer fails later. A producer therefore
 * has to be *finished*, not merely started, before PHPUnit moves on; TestCase::runBare() (and,
 * for the manual approach, Counit::create()) joins its coroutine when this map says something
 * depends on it.
 *
 * Built lazily, on the first isProducer() call of the run: PHPUnit 8/9 construct the whole
 * TestSuite tree -- loading every test class and instantiating every test -- before the first
 * test executes, so a get_declared_classes() sweep at that point sees every class of the run,
 * cross-class @depends targets included. Classes that are loaded but filtered out of the run may
 * still mark producers -- a harmless loss of that one test's concurrency, never a correctness
 * issue.
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
    private static $producerMethods = [];

    /**
     * Classes something depends on as a whole (a dependency targeting "ClassName::class").
     * Only PHPUnit >= 9.3 (where ExecutionOrderDependency exists) supports class-level targets;
     * older versions' handleDependencies() warns that "ClassName::class" does not exist -- in
     * blocking mode too -- and this list simply stays empty.
     *
     * @var array<string, true>
     */
    private static $producerClasses = [];

    /**
     * @var bool
     */
    private static $built = false;

    /**
     * Does anything in the run depend on this test method (directly, or through its class)?
     */
    public static function isProducer(string $className, string $methodName): bool
    {
        self::build();

        if (isset(self::$producerClasses[$className])
            || isset(self::$producerMethods[$className . '::' . $methodName])) {
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

    private static function build(): void
    {
        if (self::$built) {
            return;
        }
        self::$built = true;

        foreach (get_declared_classes() as $class) {
            if (!is_subclass_of($class, BaseTestCase::class)) {
                continue;
            }

            try {
                $reflector = new \ReflectionClass($class);
                if ($reflector->isAbstract()) {
                    continue;
                }

                foreach ($reflector->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                    // Consider every public method the test hierarchy itself declares -- not just
                    // "test"-prefixed ones, since a @test-annotated method can declare @depends
                    // too -- while skipping everything inherited from PHPUnit's own TestCase (or
                    // above), which never carries the annotation.
                    if ($method->isStatic() || !is_subclass_of($method->getDeclaringClass()->getName(), BaseTestCase::class)) {
                        continue;
                    }

                    /** @var array<int, mixed> $dependencies PHPUnit 9 declares ExecutionOrderDependency[]; PHPUnit 8 returns raw annotation strings */
                    $dependencies = \PHPUnit\Util\Test::getDependencies($class, $method->getName());
                    foreach ($dependencies as $dependency) {
                        self::register($class, $dependency);
                    }
                }
            } catch (\Throwable $t) {
                // A class that cannot be reflected or parsed cannot declare usable dependencies
                // either; skip it rather than break the run.
                continue;
            }
        }
    }

    /**
     * Records one dependency target. PHPUnit 9 hands over ExecutionOrderDependency objects,
     * PHPUnit 8 the raw @depends annotation strings (possibly carrying a clone-option prefix);
     * both spell cross-class targets as "Class::method". The instanceof check is safe on
     * PHPUnit 8 -- instanceof does not autoload, an unknown class simply never matches.
     *
     * @param mixed $dependency
     */
    private static function register(string $className, $dependency): void
    {
        if ($dependency instanceof ExecutionOrderDependency) { // PHPUnit 9
            if (!$dependency->isValid()) {
                return;
            }
            if ($dependency->targetIsClass()) {
                self::$producerClasses[$dependency->getTargetClassName()] = true;

                return;
            }
            self::$producerMethods[$dependency->getTarget()] = true;

            return;
        }
        if (!is_string($dependency)) {
            return;
        }

        // PHPUnit 8: strip the clone-option prefixes exactly as its handleDependencies() does --
        // first the clone/!clone family, then (independently) the shallowClone/!shallowClone one.
        $target = trim($dependency);
        foreach ([['clone ', '!clone '], ['shallowClone ', '!shallowClone ']] as $family) {
            foreach ($family as $prefix) {
                if (strpos($target, $prefix) === 0) {
                    $target = substr($target, strlen($prefix));

                    break;
                }
            }
        }

        if ($target === '') {
            return;
        }

        $separator = strpos($target, '::');
        if ($separator === false) {
            self::$producerMethods[$className . '::' . $target] = true;

            return;
        }
        self::$producerMethods[substr($target, 0, $separator) . '::' . substr($target, $separator + 2)] = true;
    }
}

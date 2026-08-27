<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestResult;
use PHPUnit\Runner\TestListenerAdapter;

/**
 * Replaces the result cache's recorded per-test times with the real wall-clock durations.
 *
 * PHPUnit 8/9's ResultCacheExtension records each test's time from the endTest() boundary --
 * under counit, the test's first yield: 0.001s for a 1s test -- feeding
 * --order-by=duration and duration-based CI sharding noise. The DEFECT half of the cache is
 * already exact on this branch: the deferred-verdict replays (see CounitExtension) go through
 * the public TestResult::addFailure()/addError(), which notify the cache extension's listener
 * adapter like any native verdict, and the cache only persists from its own
 * executeAfterLastTest() hook -- after counit's, as the recorded defects prove. All that is
 * left is to overwrite the times with Counit's measured durations before that persist happens.
 * (Blocking PHPUnit 8/9 record skipped tests' times too, so no verdict is excluded.)
 *
 * The cache object is reached through the run's TestResult: PHPUnit wraps its hook extensions
 * in a TestListenerAdapter registered as a listener, and the ResultCacheExtension among them
 * holds the DefaultTestResultCache (public setTime()). Everything is fail-soft: no cache
 * configured, or changed PHPUnit internals, simply leave the file as PHPUnit wrote it.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class HistoryCorrector
{
    /**
     * Overwrites the cache's times with the measured durations; call from
     * executeAfterLastTest(), after the drain and the deferred-verdict replays.
     */
    public static function correct(): void
    {
        try {
            $durations = Counit::measuredDurations();
            if ($durations === [] || !Counit::$testResult instanceof TestResult) {
                return;
            }

            $cache = self::resultCache();
            if ($cache === null) {
                return;
            }

            $setTime = [$cache, 'setTime'];
            if (!is_callable($setTime)) {
                return;
            }

            foreach ($durations as $duration) {
                $test = $duration['test'];

                $setTime(
                    sprintf('%s::%s', get_class($test), $test->getName(true)),
                    round($duration['seconds'], 3)
                );
            }
        } catch (\Throwable $t) {
            // PHPUnit's internals have changed; leave the cache as PHPUnit wrote it.
        }
    }

    /**
     * The run's result cache, or null when none is configured. Reached through the TestResult's
     * listeners: the TestListenerAdapter wraps the hook extensions, one of which (PHPUnit's own
     * ResultCacheExtension) holds the cache in a private `cache` property with a public
     * setTime().
     *
     * @return object|null
     */
    private static function resultCache()
    {
        $listeners = new \ReflectionProperty(TestResult::class, 'listeners');
        if (PHP_VERSION_ID < 80100) {
            // A no-op since PHP 8.1 (and deprecated since 8.5), but required on the PHP 7.2
            // through 8.0 part of this branch's supported range.
            $listeners->setAccessible(true);
        }
        $value = $listeners->getValue(Counit::$testResult);

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $listener) {
            if (!$listener instanceof TestListenerAdapter) {
                continue;
            }

            $hooks = new \ReflectionProperty(TestListenerAdapter::class, 'hooks');
            if (PHP_VERSION_ID < 80100) {
                $hooks->setAccessible(true);
            }
            $hookList = $hooks->getValue($listener);

            if (!is_array($hookList)) {
                continue;
            }

            foreach ($hookList as $hook) {
                if (!is_object($hook)) {
                    continue;
                }

                $reflection = new \ReflectionObject($hook);
                if (!$reflection->hasProperty('cache')) {
                    continue;
                }

                $property = $reflection->getProperty('cache');
                if (PHP_VERSION_ID < 80100) {
                    $property->setAccessible(true);
                }
                $cache = $property->getValue($hook);

                if (is_object($cache) && method_exists($cache, 'setTime')) {
                    return $cache;
                }
            }
        }

        return null;
    }
}

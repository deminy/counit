<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Framework\Assert;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;
use PHPUnit\TextUI\Configuration\Configuration;
use Swoole\Coroutine;

/**
 * @internal this class is not covered by the backward compatibility promise for counit
 *
 * PHPUnit 10 removed the test-hook interfaces (including AfterLastTestHook). The end-of-run
 * behavior is now implemented as an event extension that subscribes to the runner's
 * ExecutionFinished event.
 */
final class CounitExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class() implements ExecutionFinishedSubscriber {
            public function notify(ExecutionFinished $event): void
            {
                if (Helper::isCoroutineFriendly()) {
                    // Everything in PHPUnit's static assertion counter at this point was already
                    // harvested into the last test: the counter is read at the end of each test but
                    // only reset at the start of the next one, and no test coroutine can have run
                    // in between -- PHPUnit's own bookkeeping between tests (progress output,
                    // result cache) only performs STDIO/file calls, which are deliberately excluded
                    // from the coroutine hooks (see Helper::coroutineHookFlags()), so control never
                    // leaves the main coroutine there. Reset the counter so that, after the drain
                    // below, it holds exactly the assertions that ran too late for PHPUnit to see.
                    Assert::resetCount();

                    // When the only coroutine left is the one created in script /counit, it means all the tests are
                    // finally done, and it's time to hand it over to PHPUnit to take care of the rest part.
                    while (Coroutine::stats()['coroutine_num'] > 1) { // @phpstan-ignore offsetAccess.nonOffsetAccessible
                        Coroutine::sleep(0.2);
                    }

                    // Correct the run's reported assertion total. PHPUnit attributes assertions to
                    // tests through a per-test window over a static counter, so under counit every
                    // real assertion ends up in exactly one of two places: harvested into whatever
                    // test's window happened to be open when it ran (already part of the reported
                    // total, possibly double-counting an up-front credit), or -- having run after
                    // the last window closed -- in the counter residue drained above. Therefore:
                    //     true total = reported total - up-front credits + residue
                    // which holds for both the "global" and the "case-by-case" style. The summary
                    // is printed from the collector only after this event completes, so adjusting
                    // the collector here makes the reported total match a blocking (non-Swoole)
                    // run exactly. The collector has no public mutator (it is fed by events that
                    // have already been dispatched), hence the reflection; if PHPUnit's internals
                    // change, the correction is skipped rather than breaking the run.
                    $delta = Assert::getCount() - Counit::$creditedAssertionCount;
                    if ($delta !== 0) {
                        try {
                            $collector = (new \ReflectionProperty(TestResultFacade::class, 'collector'))->getValue();
                            if (is_object($collector)) {
                                $property = new \ReflectionProperty($collector, 'numberOfAssertions');
                                $total    = $property->getValue($collector);
                                if (is_int($total)) {
                                    $property->setValue($collector, max(0, $total + $delta));
                                }
                            }
                        } catch (\ReflectionException) {
                            // PHPUnit's internals have changed; leave the (approximate) total as is.
                        }
                    }
                }
            }
        });
    }
}

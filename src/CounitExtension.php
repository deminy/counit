<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Test\Errored as TestErrored;
use PHPUnit\Event\Test\ErroredSubscriber as TestErroredSubscriber;
use PHPUnit\Event\Test\Failed as TestFailed;
use PHPUnit\Event\Test\FailedSubscriber as TestFailedSubscriber;
use PHPUnit\Event\Test\Finished as TestFinished;
use PHPUnit\Event\Test\FinishedSubscriber as TestFinishedSubscriber;
use PHPUnit\Event\Test\MarkedIncomplete as TestMarkedIncomplete;
use PHPUnit\Event\Test\MarkedIncompleteSubscriber as TestMarkedIncompleteSubscriber;
use PHPUnit\Event\Test\PreparationStarted as TestPreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber as TestPreparationStartedSubscriber;
use PHPUnit\Event\Test\Skipped as TestSkipped;
use PHPUnit\Event\Test\SkippedSubscriber as TestSkippedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Event\TestSuite\Loaded as TestSuiteLoaded;
use PHPUnit\Event\TestSuite\LoadedSubscriber as TestSuiteLoadedSubscriber;
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
    /**
     * Shared body of the four verdict subscribers registered in bootstrap(): forwards the test's
     * class to TestCase::handleAbortedTestPreparation(), which no-ops unless the test was aborted
     * during preparation and its class has taken-over after-test hooks.
     */
    public static function handleTestVerdict(Test $test): void
    {
        if ($test->isTestMethod()) {
            TestCase::handleAbortedTestPreparation($test->className());
        }
    }

    #[\Override]
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        // This is the same Configuration instance TestRunner reads its time-limit settings from,
        // handed over through the sanctioned extension seam -- no reflection into PHPUnit's
        // @internal configuration Registry needed.
        TimeLimit::initialize($configuration);

        if (Helper::isCoroutineFriendly()) {
            if (TimeLimit::enforcedForRun()) {
                TimeLimit::announceSerializedRun();
            }

            // Segment accounting (see Attribution) relies on cooperative scheduling: a coroutine
            // only ever switches at a yield. Swoole's preemptive scheduler breaks that premise,
            // so attribution stays off under it and the JUnit per-testcase correction falls back
            // to the credit/late arithmetic in Counit::correctedAssertionCountFor().
            Attribution::$enabled = !filter_var((string) ini_get('swoole.enable_preemptive_scheduler'), FILTER_VALIDATE_BOOL);

            // The reverse #[Depends] graph has to be known before the first producer runs, and
            // this event carries the whole (already flattened) suite. See DependencyMap.
            $facade->registerSubscriber(new class implements TestSuiteLoadedSubscriber {
                #[\Override]
                public function notify(TestSuiteLoaded $event): void
                {
                    DependencyMap::build($event->testSuite());
                }
            });

            // Fires before setUp()/#[Before] hooks run, so their assertions are attributed to the
            // test too. Also the earliest point the test's class name is known, which is when the
            // sleep()/usleep() shims for its namespace must be in place -- before any test code
            // of that namespace runs.
            $facade->registerSubscriber(new class implements TestPreparationStartedSubscriber {
                #[\Override]
                public function notify(TestPreparationStarted $event): void
                {
                    $test = $event->test();

                    if ($test->isTestMethod()) {
                        Attribution::installShims($test->className());
                    }

                    Attribution::testStarting($test->id());
                    TestCase::markTestPreparationStarted();
                }
            });

            // A test whose verdict is emitted although its body never reached invokeTestMethod()
            // was aborted during preparation: setUp() or another before-test hook threw or
            // skipped. Its taken-over after-test hooks must be handed back to PHPUnit, whose own
            // (native, synchronous) hook invocation is still ahead of these events -- otherwise
            // tearDown()/#[After] would be lost for that test, while blocking PHPUnit runs them.
            // All four verdicts runBare() can emit for an aborted preparation are covered; see
            // TestCase::handleAbortedTestPreparation() for the details.
            $facade->registerSubscriber(new class implements TestErroredSubscriber {
                #[\Override]
                public function notify(TestErrored $event): void
                {
                    CounitExtension::handleTestVerdict($event->test());
                }
            });
            $facade->registerSubscriber(new class implements TestFailedSubscriber {
                #[\Override]
                public function notify(TestFailed $event): void
                {
                    CounitExtension::handleTestVerdict($event->test());
                }
            });
            $facade->registerSubscriber(new class implements TestSkippedSubscriber {
                #[\Override]
                public function notify(TestSkipped $event): void
                {
                    CounitExtension::handleTestVerdict($event->test());
                }
            });
            $facade->registerSubscriber(new class implements TestMarkedIncompleteSubscriber {
                #[\Override]
                public function notify(TestMarkedIncomplete $event): void
                {
                    CounitExtension::handleTestVerdict($event->test());
                }
            });

            // Remember the assertion count PHPUnit reports for each test -- the number that enters
            // the run's total. Compared with the count read inside the test's coroutine once it has
            // fully finished, this exposes assertions counted directly on the test object *after*
            // the test was reported (they bypass the static counter, so the residue correction
            // below cannot see them). Only registered under the coroutine runner: in blocking mode
            // nothing runs after a test is reported.
            $facade->registerSubscriber(new class implements TestFinishedSubscriber {
                #[\Override]
                public function notify(TestFinished $event): void
                {
                    $test = $event->test();

                    Counit::recordEmittedAssertionCount($test->id(), $event->numberOfAssertionsPerformed());
                    Attribution::testFinished($test->id());

                    if ($test->isTestMethod()) {
                        JunitXmlCorrector::recordTest($test->className(), $test->name(), $test->id());
                    }
                }
            });
        }

        $facade->registerSubscriber(new class implements ExecutionFinishedSubscriber {
            #[\Override]
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
                    Attribution::counterReset();

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
                    //     true total = reported total - up-front credits + residue + late instance counts
                    // which holds for both the automatic and the manual approach. The last term
                    // covers assertions counted directly on a test object -- bypassing the static
                    // counter -- after PHPUnit had already reported that test, e.g. an
                    // addToAssertionCount() call made after a sleep/IO yield from the body's tail
                    // or from a relocated tearDown(); see Counit::lateAssertionCount(). The summary
                    // is printed from the collector only after this event completes, so adjusting
                    // the collector here makes the reported total match a blocking (non-Swoole)
                    // run exactly. The collector has no public mutator (it is fed by events that
                    // have already been dispatched), hence the reflection; if PHPUnit's internals
                    // change, the correction is skipped rather than breaking the run.
                    // The JUnit XML report needs its own correction: its per-testcase `assertions`
                    // attributes were captured from the Test\Finished events, so they carry the
                    // up-front credits (inflating every automatic-approach test's count, even in
                    // fully synchronous suites) and miss the late instance counts. The logger only
                    // writes the report when its own ExecutionFinished subscriber runs -- after
                    // this one, since extensions bootstrap before log writers register.
                    JunitXmlCorrector::correct();

                    $delta = Assert::getCount() - Counit::$creditedAssertionCount + Counit::lateAssertionCount();
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

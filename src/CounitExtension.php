<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\Code\Test;
use PHPUnit\Event\Test\ConsideredRisky as TestConsideredRisky;
use PHPUnit\Event\Test\ConsideredRiskySubscriber as TestConsideredRiskySubscriber;
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
 * Register this extension in your phpunit.xml / phpunit.xml.dist:
 *
 *     <extensions>
 *         <bootstrap class="Deminy\Counit\CounitExtension"/>
 *     </extensions>
 *
 * It is what waits for every coroutine to drain before PHPUnit's summary is taken, so it is what
 * makes the run's reported time, its assertion totals, and the replay of late failure/skip/risky
 * verdicts correct. Unregistered, PHPUnit prints its summary while tests are still sleeping: the
 * reported time can understate the truth by orders of magnitude, and assertion totals are neither
 * correct nor consistent between a full run and the same tests run in isolation. See
 * docs/compatibility.md, whose every guarantee assumes this extension is registered.
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
        // This is the same Configuration instance TestRunner reads its time-limit settings from
        // (and TestBuilder its backup defaults), handed over through the sanctioned extension
        // seam -- no reflection into PHPUnit's @internal configuration Registry needed.
        TimeLimit::initialize($configuration);
        GlobalState::initialize($configuration);
        OutputExpectations::initialize($configuration);
        UselessTests::initialize($configuration);
        VerdictSequencing::initialize($configuration);

        if (Helper::isCoroutineFriendly()) {
            if (TimeLimit::enforcedForRun()) {
                TimeLimit::announceSerializedRun();
            }

            if (VerdictSequencing::activeForRun()) {
                VerdictSequencing::announceSerializedRun();
            }

            if (GlobalState::configBacksUpEveryTest()) {
                GlobalState::announceSerializedRun();
            }

            if (OutputExpectations::disallowedForRun()) {
                OutputExpectations::announceSerializedRun();
            }

            // Segment accounting (see Attribution) relies on cooperative scheduling: a coroutine
            // only ever switches at a yield. Swoole's preemptive scheduler breaks that premise,
            // so attribution stays off under it and the JUnit per-testcase correction falls back
            // to the credit/late arithmetic in Counit::correctedAssertionCountFor().
            Attribution::$enabled = !filter_var((string) ini_get('swoole.enable_preemptive_scheduler'), FILTER_VALIDATE_BOOL);
            // The handler isolation piggybacks on Attribution's observation points and trusts
            // only slices Attribution can vouch for, so it degrades to a no-op alongside it.
            HandlerIsolation::$enabled = true;

            // Convert diagnostics (deprecations/warnings/notices) a test triggers after its first
            // yield, which PHPUnit's own converting handler no longer sees; see Diagnostics.
            Diagnostics::initialize();

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
                        // Wall-clock stamp for the test's real duration (PHPUnit's own telemetry
                        // only sees time-to-first-yield for a non-joined test); see
                        // Counit::recordTestStarting()/HistoryCorrector.
                        Counit::recordTestStarting($test->id());

                        // The barrier half of the #[BackupGlobals]/#[BackupStaticProperties]/
                        // #[WithEnvironmentVariable] support (see GlobalState): drain every
                        // in-flight test coroutine BEFORE PHPUnit takes this test's global-state
                        // snapshot. This event is emitted inside runBare(), three lines before
                        // the snapshot -- the last seam early enough: draining any later would
                        // let tests finish (and mutate globals) inside the snapshot window,
                        // where the restore reverts their writes. Ordering matters twice more:
                        // the drain runs BEFORE Attribution::testStarting() below, so the
                        // draining coroutines' assertions stay attributed to their own tests --
                        // and the assertion counter was already reset for this test (in
                        // TestRunner::run(), before runBare()), so those assertions land in an
                        // open window: mis-attributed to this test in PHPUnit's own bookkeeping
                        // but never wiped, exactly the bucket the end-of-run total correction
                        // already handles.
                        if (GlobalState::isBackedUp($test->className(), $test->methodName())) {
                            Attribution::suspended();
                            while (Coroutine::stats()['coroutine_num'] > 1) { // @phpstan-ignore offsetAccess.nonOffsetAccessible
                                Coroutine::sleep(0.01);
                            }
                            Attribution::resumed();
                        }
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
            // The subscribers also feed UselessTests' exemption bookkeeping: a risky verdict
            // PHPUnit already reached itself must not be repeated by the deferred pass, and an
            // errored/skipped/incomplete test is exempt from the no-assertions check upstream.
            $facade->registerSubscriber(new class implements TestConsideredRiskySubscriber {
                #[\Override]
                public function notify(TestConsideredRisky $event): void
                {
                    UselessTests::markFlagged($event->test()->id(), $event->message());
                    HandlerIsolation::markFlagged($event->test()->id(), $event->message());
                }
            });
            $facade->registerSubscriber(new class implements TestErroredSubscriber {
                #[\Override]
                public function notify(TestErrored $event): void
                {
                    UselessTests::markAborted($event->test()->id());
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
                    UselessTests::markAborted($event->test()->id());
                    CounitExtension::handleTestVerdict($event->test());
                }
            });
            $facade->registerSubscriber(new class implements TestMarkedIncompleteSubscriber {
                #[\Override]
                public function notify(TestMarkedIncomplete $event): void
                {
                    UselessTests::markAborted($event->test()->id());
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
                    UselessTests::record($test);
                    HandlerIsolation::record($test);

                    if ($test->isTestMethod()) {
                        JunitXmlCorrector::recordTest($test->className(), $test->name(), $test->id());

                        // A test that backed up static properties has just had counit's own
                        // static state rewound and cloned by PHPUnit's restore (counit's classes
                        // are user-defined and not on PHPUnit's exclude list). This event fires
                        // after that restore; put the after-test hook takeover back on real
                        // objects before the next test consults it. See the method's docblock.
                        if (GlobalState::backsUpStaticProperties($test->className(), $test->methodName())) {
                            TestCase::repairAfterStaticRestore();
                        }
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
                    // The drain is the main coroutine's longest yield of the run and the one
                    // most post-yield test code runs in; it does not go through Attribution's
                    // observation points, so counit's converting handler is put on the stack for
                    // it here. See Diagnostics. For the same reason the drain runs outside every
                    // per-test code-coverage window, so a coverage window of its own is opened
                    // around it -- without one, every post-yield line silently vanishes from the
                    // aggregate report. See Coverage.
                    Diagnostics::suspended();
                    Coverage::startDrainWindow();
                    while (Coroutine::stats()['coroutine_num'] > 1) { // @phpstan-ignore offsetAccess.nonOffsetAccessible
                        Coroutine::sleep(0.2);
                    }
                    Coverage::stopDrainWindow();
                    Diagnostics::resumed();

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
                    // Emit the "did not perform any assertions" verdicts (and their mirror)
                    // PHPUnit could not reach on its own, now that every coroutine has drained
                    // and the per-test tallies are final. The result collector, the risky
                    // listing, the summary's Risky count and the --fail-on-risky exit code all
                    // still honor a Test\ConsideredRisky event emitted here, because the
                    // collector is only read after this subscriber returns. Must run before the
                    // correction below, which consumes the counter residue.
                    // LateSkips first (a skipped test's verdict beats any failure bookkeeping),
                    // then LateFailures; both emit real PHPUnit events that also reach the
                    // extension's own subscribers, marking the tests aborted before the
                    // useless-test pass reads the exemptions. See LateSkips/LateFailures.
                    LateSkips::emitDeferred();
                    LateFailures::emitDeferred();
                    UselessTests::emitDeferred();
                    HandlerIsolation::emitDeferred();

                    JunitXmlCorrector::correct();

                    // After every late emit: the replayed Failed/Errored/Skipped/ConsideredRisky
                    // events have updated PHPUnit's in-memory test-run history (its handler rides
                    // the same dispatcher), but the file was already persisted at the root
                    // TestSuite\Finished event -- before any of this. Re-persist it with the late
                    // verdicts and the real durations. See HistoryCorrector.
                    HistoryCorrector::correct();

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

<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestResult;
use PHPUnit\Runner\AfterLastTestHook;
use Swoole\Coroutine;

/**
 * @internal this class is not covered by the backward compatibility promise for counit
 */
class CounitExtension implements AfterLastTestHook
{
    /**
     * {@inheritDoc}
     */
    public function executeAfterLastTest(): void
    {
        if (Helper::isCoroutineFriendly()) {
            // Everything in PHPUnit's static assertion counter at this point was already harvested
            // into the last test: the counter is read at the end of each test but only reset at the
            // start of the next one, and no test coroutine can have run in between -- PHPUnit's own
            // bookkeeping between tests (progress output, result cache) only performs STDIO/file
            // calls, which are deliberately excluded from the coroutine hooks (see
            // Helper::coroutineHookFlags()), so control never leaves the main coroutine there.
            // Reset the counter so that, after the drain below, it holds exactly the assertions
            // that ran too late for PHPUnit to see.
            Assert::resetCount();

            // When the only coroutine left is the one created in script /counit, it means all the tests are finally
            // done, and it's time to hand it over to PHPUnit to take care of the rest part.
            while (Coroutine::stats()['coroutine_num'] > 1) { // @phpstan-ignore offsetAccess.nonOffsetAccessible
                Coroutine::sleep(0.2);
            }

            // Correct the run's reported assertion total. PHPUnit attributes assertions to tests
            // through a per-test window over a static counter, so under counit every real assertion
            // ends up in exactly one of two places: harvested into whatever test's window happened
            // to be open when it ran (already part of the reported total, possibly double-counting
            // an up-front credit), or -- having run after the last window closed -- in the counter
            // residue drained above. Therefore:
            //     true total = reported total - up-front credits + residue + late instance counts
            // which holds for both the automatic and the manual approach. The last term covers the
            // assertions PHPUnit counts on the test object instead of through the static counter --
            // mock/prophecy verification and the exception-expectation checks -- which run inside
            // the test's coroutine, after PHPUnit read that test's count; see
            // AssertionCountListener.
            $this->correctAssertionCount(
                Assert::getCount()
                - Counit::$creditedAssertionCount
                + AssertionCountListener::lostAssertionCount()
            );
        }
    }

    /**
     * Add $delta to the assertion total PHPUnit is about to report.
     *
     * This hook runs before the result printer prints its footer, so adjusting the printer here
     * makes the reported total match a blocking (non-Swoole) run exactly. Unlike the per-test
     * counts, that total lives in the printer alone -- TestResult does not track it -- and the
     * printer keeps it in a property with no public mutator, hence the reflection. The printer is
     * reached through the TestResult it was registered on as a listener, since this hook is handed
     * nothing of its own; the property is looked up by name rather than by printer class so that
     * both PHPUnit 8's ResultPrinter and PHPUnit 9's DefaultResultPrinter (and subclasses of it,
     * such as CliTestDoxPrinter) are covered. If PHPUnit's internals change, the correction is
     * skipped rather than breaking the run.
     */
    private function correctAssertionCount(int $delta): void
    {
        if (($delta === 0) || !(Counit::$testResult instanceof TestResult)) {
            return;
        }

        try {
            $listeners = new \ReflectionProperty(TestResult::class, 'listeners');
            $listeners->setAccessible(true);
            $value = $listeners->getValue(Counit::$testResult);

            if (!is_array($value)) {
                return;
            }

            foreach ($value as $listener) {
                if (!is_object($listener)) {
                    continue;
                }

                $reflection = new \ReflectionObject($listener);
                if (!$reflection->hasProperty('numAssertions')) {
                    continue;
                }

                $property = $reflection->getProperty('numAssertions');
                $property->setAccessible(true);
                $total = $property->getValue($listener);

                if (is_int($total)) {
                    $property->setValue($listener, max(0, $total + $delta));
                }
            }
        } catch (\ReflectionException $e) {
            // PHPUnit's internals have changed; leave the (approximate) total as is.
        }
    }
}

<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Swoole\Constant;
use Swoole\Coroutine;

/**
 * @internal this class is not covered by the backward compatibility promise for counit
 * @coversNothing
 */
class TestCase extends BaseTestCase
{
    /**
     * @var array<string, mixed>
     */
    protected static $coroutineOptions = [];

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

    public static function tearDownAfterClass(): void
    {
        if (Helper::isCoroutineFriendly() && !empty(static::$coroutineOptions)) {
            Coroutine::set(static::$coroutineOptions);
        }
        parent::tearDownAfterClass();
    }

    /**
     * {@inheritDoc}
     *
     * See Counit::create() for how a Throwable thrown by the wrapped test -- whether synchronously
     * or after a sleep()/IO yield -- is handled without crashing the process or letting a real
     * failure silently pass as "OK".
     */
    public function runBare(): void
    {
        if (Helper::isCoroutineFriendly()) {
            // A test something @depends on cannot be allowed to merely start here: PHPUnit
            // records its return value and verdict when runBare() returns -- under counit, the
            // body's first yield -- and resolves each dependent's input from there, before any
            // counit seam (see DependencyMap). So a producer's coroutine is joined:
            // parent::runBare() runs to true completion inside the coroutine -- its own error
            // handling, tearDown() included, all with native semantics -- and a Throwable it
            // rethrows reaches TestResult::run() synchronously, so a producer that only fails
            // after a yield skips its dependents exactly as in blocking mode. No assertion
            // credit is applied (see below): PHPUnit reads the real, final count, and crediting
            // would stop a producer that performs no assertions from being flagged risky --
            // which is what makes PHPUnit skip its dependents in blocking mode. It costs that
            // one test its own concurrency; every other test still overlaps with it, including
            // while it waits.
            if (DependencyMap::isProducer(static::class, $this->getName(false))) {
                Counit::createAndJoin(function (): void {
                    parent::runBare();
                });

                return;
            }

            Counit::create(function () {
                parent::runBare();
            });

            // Credit this test with one assertion, which suppresses the "This test did not perform
            // any assertions" warning (its real assertions usually run only after PHPUnit has read
            // the count) and is subtracted again from the run's total by CounitExtension. This
            // replaces expectNotToPerformAssertions(), which instead flagged a test as risky
            // whenever one of its assertions happened to run early. creditAssertionCount() declines
            // the credit for a test that declares -- through the annotation
            // @doesNotPerformAssertions or its own expectNotToPerformAssertions() call -- that it
            // performs no assertions, so such tests are not falsely reported as risky.
            //
            // The credit has to be applied *after* create() returns rather than before it, because
            // PHPUnit's own runBare() -- running inside the coroutine -- starts by zeroing the
            // test's assertion count, and would wipe a credit added ahead of it. By the time
            // create() returns, the coroutine has already run up to its first yield (or to
            // completion), so that reset is behind us. No credit when create() joined the
            // coroutine because this test carries an exception expectation: the body has fully
            // finished, so the expectation verification's own assertions are counted natively,
            // before PHPUnit reads the count -- crediting on top would inflate it.
            if (!Counit::lastCreateJoined()) {
                Counit::creditAssertionCount($this, 1);
            }
        } else {
            parent::runBare();
        }
    }
}

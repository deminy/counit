<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Swoole\Constant;
use Swoole\Coroutine;

/**
 * The automatic approach's base class: extend it instead of PHPUnit\Framework\TestCase and your
 * time/IO-bound tests run concurrently under the counit runner, with no other code changes.
 *
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
            // A run with --enforce-time-limit active takes the same join, for EVERY test: PHPUnit
            // times a limited test by wrapping this very runBare() in a pcntl_alarm() guard
            // (TestResult::run() via the php-invoker package), so parent::runBare() must have
            // truly finished before this method returns for the measured window -- and the
            // alarm's SIGALRM, which is delivered to whichever coroutine resumes first -- to be
            // correct. The run is serialized for the duration; see TimeLimit. No assertion
            // credit applies on the joined path, so an aborted test that reached no assertion is
            // flagged "did not perform any assertions" exactly as in blocking mode.
            // A test PHPUnit brackets with a global-state snapshot (@backupGlobals /
            // @backupStaticAttributes, or the matching configuration) is joined too: the
            // snapshot and restore run inside parent::runBare() -- inside the coroutine -- so
            // the test's own isolation was already correct, but the window used to span its
            // whole concurrent lifetime, and the restore reverted every overlapping test's
            // global writes. The join (together with the pre-snapshot drain barrier in
            // AssertionCountListener::startTest()) makes the window exclusive; see GlobalState.
            if (DependencyMap::isProducer(static::class, $this->getName(false))
                || TimeLimit::enforcedForRun($this)
                || GlobalState::isBackedUp($this)) {
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
            // before PHPUnit reads the count -- crediting on top would inflate it. The same goes
            // for a body that ran to completion without ever yielding: its count is already
            // final, so PHPUnit reaches the "did not perform any assertions" verdict natively,
            // at the right moment, exactly as in blocking mode (see UselessTests for the
            // deferred half covering yielding tests).
            if (!Counit::lastCreateJoined() && !Counit::lastCreateFinished()) {
                Counit::creditAssertionCount($this, 1);
            }
        } else {
            parent::runBare();
        }
    }
}

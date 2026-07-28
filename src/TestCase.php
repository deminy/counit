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
            Counit::create(function () {
                parent::runBare();
            });

            // Credit this test with one assertion, which suppresses the "This test did not perform
            // any assertions" warning (its real assertions usually run only after PHPUnit has read
            // the count) and is subtracted again from the run's total by CounitExtension. This
            // replaces expectNotToPerformAssertions(), which instead flagged a test as risky
            // whenever one of its assertions happened to run early.
            //
            // The credit has to be applied *after* create() returns rather than before it, because
            // PHPUnit's own runBare() -- running inside the coroutine -- starts by zeroing the
            // test's assertion count, and would wipe a credit added ahead of it. By the time
            // create() returns, the coroutine has already run up to its first yield (or to
            // completion), so that reset is behind us.
            Counit::creditAssertionCount($this, 1);
        } else {
            parent::runBare();
        }
    }
}

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
     */
    public function runBare(): void
    {
        if (Helper::isCoroutineFriendly()) {
            $this->expectNotToPerformAssertions(); // To suppress warning message "This test did not perform any assertions".
            Counit::create(function () {
                parent::runBare();
            });
        } else {
            parent::runBare();
        }
    }
}

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
    protected static $coroutineOptions;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (Helper::isCoroutineFriendly()) {
            static::$coroutineOptions = Coroutine::getOptions();
            Coroutine::set([Constant::OPTION_HOOK_FLAGS => SWOOLE_HOOK_ALL]);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (Helper::isCoroutineFriendly()) {
            Coroutine::set(static::$coroutineOptions);
        }
        parent::setUpBeforeClass();
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

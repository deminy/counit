<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Event\TestRunner\ExecutionFinished;
use PHPUnit\Event\TestRunner\ExecutionFinishedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
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
                    // When the only coroutine left is the one created in script /counit, it means all the tests are
                    // finally done, and it's time to hand it over to PHPUnit to take care of the rest part.
                    while (Coroutine::stats()['coroutine_num'] > 1) { // @phpstan-ignore offsetAccess.nonOffsetAccessible
                        Coroutine::sleep(0.2);
                    }
                }
            }
        });
    }
}

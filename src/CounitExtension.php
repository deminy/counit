<?php

declare(strict_types=1);

namespace Deminy\Counit;

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
            // When the only coroutine left is the one created in script /counit, it means all the tests are finally
            // done, and it's time to hand it over to PHPUnit to take care of the rest part.
            while (Coroutine::stats()['coroutine_num'] > 1) {
                Coroutine::sleep(0.2);
            }
        }
    }
}

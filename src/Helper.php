<?php

declare(strict_types=1);

namespace Deminy\Counit;

use Swoole\Coroutine;

class Helper
{
    /**
     * Check to see if running unit tests using counit, with the Swoole extension enabled.
     */
    public static function isCoroutineFriendly(): bool
    {
        return extension_loaded('swoole') && (Coroutine::getCid() !== -1);
    }
}

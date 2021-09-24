<?php

declare(strict_types=1);

namespace Deminy\Counit;

use Swoole\Coroutine;

class Helper
{
    /**
     * @var string
     */
    protected static $prefix;

    /**
     * @var int
     */
    protected static $counter = 0;

    /**
     * Check to see if running unit tests using counit, with the Swoole extension enabled.
     */
    public static function isCoroutineFriendly(): bool
    {
        return extension_loaded('swoole') && (Coroutine::getCid() !== -1);
    }

    public static function getNewKey(): string
    {
        if (!isset(self::$prefix)) {
            self::initPrefix();
        }
        return self::$prefix . (++self::$counter);
    }

    /**
     * @return string[]
     */
    public static function getNewKeys(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $keys[] = self::getNewKey();
        }

        return $keys;
    }

    protected static function initPrefix(string $prefix = null): void
    {
        if (!isset($prefix)) {
            $prefix = uniqid('test-key-') . '-' . getmypid() . '-';
        }
        self::$prefix = $prefix;
    }
}

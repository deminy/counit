<?php

declare(strict_types=1);

namespace Deminy\Counit;

class TestKey
{
    /**
     * @var string
     */
    protected static $prefix;

    /**
     * @var int
     */
    protected static $counter = 0;

    public static function nextKey(): string
    {
        if (!isset(self::$prefix)) {
            self::initPrefix();
        }
        return self::$prefix . (++self::$counter);
    }

    public static function initPrefix(string $prefix = null): void
    {
        if (!isset($prefix)) {
            $prefix = uniqid('test-key-') . '-' . getmypid() . '-';
        }
        self::$prefix = $prefix;
    }
}

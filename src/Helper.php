<?php

declare(strict_types=1);

namespace Deminy\Counit;

use Swoole\Coroutine;

class Helper
{
    /**
     * @var string
     */
    protected static $prefix = '';

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

    /**
     * The coroutine hook flags counit runs tests under. STDIO and file operations are excluded
     * from the hooks: PHPUnit itself writes to STDOUT (progress output) and to files (e.g., the
     * result cache) between tests, and if those calls yielded, pending test coroutines would
     * resume in the gap between one test's assertion-count harvest and the next test's counter
     * reset -- any assertions performed there are wiped by that reset and silently vanish from
     * the run's reported total. With the exclusion, tests doing real file IO simply block for its
     * (local, fast) duration instead of yielding; network IO and sleep() -- what this package
     * exists to parallelize -- stay hooked.
     *
     * NOTE: Swoole only honors hook flags configured before the coroutine scheduler starts, so
     * this value must be applied via Coroutine::set() before Swoole\Coroutine\run() (as done in
     * the `counit` script); setting it from inside a running coroutine has no effect.
     *
     * Only call this method when the Swoole extension is loaded; the SWOOLE_HOOK_* constants do
     * not exist without it.
     */
    public static function coroutineHookFlags(): int
    {
        return SWOOLE_HOOK_ALL & ~SWOOLE_HOOK_STDIO & ~SWOOLE_HOOK_FILE;
    }

    public static function getNewKey(): string
    {
        if (empty(self::$prefix)) {
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

    protected static function initPrefix(string $prefix = ''): void
    {
        if (empty($prefix)) {
            $prefix = uniqid('test-key-') . '-' . getmypid() . '-';
        }
        self::$prefix = $prefix;
    }
}

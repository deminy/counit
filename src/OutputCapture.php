<?php

declare(strict_types=1);

namespace Deminy\Counit;

/**
 * Per-coroutine capture of a MANUAL-approach test body's output, plus the buffer-level
 * bookkeeping PHPUnit performs when it stops its own buffer.
 *
 * Swoole saves and restores PHP's output-buffer stack per coroutine (PHPCoroutine::save_og() /
 * restore_og(), unconditional, independent of hook flags): a coroutine starts at
 * ob_get_level() === 0 no matter what its creator had open, and buffers it opens are invisible
 * everywhere else. For the automatic approach this branch was never broken: the WHOLE
 * parent::runBare() -- PHPUnit's own ob_start() and output verification included -- runs inside
 * the test's coroutine, whose private buffer survives its yields, so expectations verify against
 * the real output with full concurrency kept. The manual approach had the 1.x defect: its
 * runBare() is PHPUnit's own, running on the main coroutine, so the buffer opened there never saw
 * a byte the callable echoed from inside the coroutine Counit::create() spawns -- expectations
 * compared against '' unconditionally (a yield was not even needed) and the output leaked raw to
 * the terminal. Capturing here and replaying on the calling coroutine (see Counit::create() /
 * createAndJoin()) puts the output back where PHPUnit looks for it.
 *
 * The capture buffer is a plain ob_start(), the same shape PHPUnit 8/9 use for their own, so an
 * explicit ob_flush() inside a body behaves exactly as in blocking mode. Captures are keyed by an
 * opaque handle, not by stack position: several coroutines (and nested create() calls within one)
 * hold captures at the same time and finish in any order.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class OutputCapture
{
    /**
     * The stack level each open capture expects at stop() time, per handle.
     *
     * @var array<int, int>
     */
    private static $levels = [];

    /**
     * @var int
     */
    private static $nextHandle = 0;

    /**
     * Opens a capture buffer on the current coroutine.
     */
    public static function start(): int
    {
        $handle = self::$nextHandle++;

        ob_start();
        self::$levels[$handle] = ob_get_level();

        return $handle;
    }

    /**
     * Closes the buffer start() opened.
     *
     * @return array{0: string, 1: int} the captured output, and how far the body left this
     *                                  coroutine's buffer stack off its expected level (positive:
     *                                  buffers it opened and never closed; negative: buffers it
     *                                  closed that were not its own). On a non-zero delta the
     *                                  output cannot be ordered and is dropped -- what PHPUnit
     *                                  itself does -- and the caller reproduces the mismatch via
     *                                  replayLevelMismatch().
     */
    public static function stop(int $handle): array
    {
        $level = isset(self::$levels[$handle]) ? self::$levels[$handle] : ob_get_level();
        unset(self::$levels[$handle]);

        $delta = ob_get_level() - $level;

        if ($delta !== 0) {
            while (ob_get_level() >= $level) {
                if (!ob_end_clean()) {
                    break;
                }
            }

            return ['', $delta];
        }

        return [(string) ob_get_clean(), 0];
    }

    /**
     * Reproduces, on the coroutine PHPUnit's own output buffer lives on, a buffer-level mismatch
     * the body caused inside its coroutine, so PHPUnit detects it in its own
     * stopOutputBuffering() and reports the native "did not (only) close its own output buffers"
     * risky verdict itself.
     */
    public static function replayLevelMismatch(int $delta): void
    {
        for ($i = 0; $i < $delta; $i++) {
            ob_start();
        }
    }
}

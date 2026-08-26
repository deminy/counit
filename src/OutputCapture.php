<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Framework\TestCase\OutputBuffer;

/**
 * Per-coroutine capture of a test body's output, plus the buffer-level bookkeeping PHPUnit
 * performs when it stops its own buffer.
 *
 * Swoole saves and restores PHP's output-buffer stack per coroutine (PHPCoroutine::save_og() /
 * restore_og(), unconditional, independent of hook flags): a coroutine starts at
 * ob_get_level() === 0 no matter what its creator had open, and buffers it opens are invisible
 * everywhere else. PHPUnit opens the test's output buffer at the top of runBare(), on the runner
 * coroutine -- so nothing a test body echoes from inside its own coroutine ever reaches that
 * buffer: expectOutputString()/expectOutputRegex() compared against '' unconditionally (even for
 * a body that never yields), the output leaked raw to the terminal, and the
 * unexpected-output/--disallow-test-output machinery never saw a byte. Capturing here and
 * replaying on the calling coroutine (see Counit::create()/createAndJoin()) puts the output back
 * where PHPUnit looks for it.
 *
 * The buffer is opened in whichever shape the PHPUnit in use opens its own, so an explicit
 * ob_flush()/flush() inside a test body has the same effect it has in blocking mode: PHPUnit 13
 * installs a callback that retains flushed content, PHPUnit 12.5 uses a plain ob_start() that
 * lets it escape.
 *
 * Captures are keyed by an opaque handle, not by stack position: several coroutines (and nested
 * create() calls within one) hold captures at the same time and finish in any order.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class OutputCapture
{
    /**
     * @var array<int, array{sink: string, level: int}>
     */
    private static array $captures = [];

    private static int $nextHandle = 0;

    private static ?bool $retainsFlushedOutput = null;

    /**
     * Opens a capture buffer on the current coroutine.
     */
    public static function start(): int
    {
        if (self::$retainsFlushedOutput === null) {
            self::$retainsFlushedOutput = class_exists(OutputBuffer::class);
        }

        $handle                  = self::$nextHandle++;
        self::$captures[$handle] = ['sink' => '', 'level' => 0];

        if (self::$retainsFlushedOutput) {
            ob_start(static function (string $buffer, int $phase) use ($handle): string {
                $isClean = ($phase & PHP_OUTPUT_HANDLER_CLEAN) !== 0;
                $isFinal = ($phase & PHP_OUTPUT_HANDLER_FINAL) !== 0;

                if ((!$isClean || $isFinal) && isset(self::$captures[$handle])) {
                    self::$captures[$handle]['sink'] .= $buffer;
                }

                return '';
            });
        } else {
            ob_start();
        }

        self::$captures[$handle]['level'] = ob_get_level();

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
        $level = self::$captures[$handle]['level'] ?? ob_get_level();
        $delta = ob_get_level() - $level;

        if ($delta !== 0) {
            while (ob_get_level() >= $level) {
                if (!ob_end_clean()) {
                    break;
                }
            }

            unset(self::$captures[$handle]);

            return ['', $delta];
        }

        if (!self::$retainsFlushedOutput) {
            unset(self::$captures[$handle]);

            return [(string) ob_get_clean(), 0];
        }

        ob_end_clean();

        $captured = self::$captures[$handle]['sink'] ?? '';
        unset(self::$captures[$handle]);

        return [$captured, 0];
    }

    /**
     * Reproduces, on the coroutine PHPUnit's own output buffer lives on, a buffer-level mismatch
     * the body caused inside its coroutine, so PHPUnit detects it in its own stop() and reports
     * the native "did not close its own output buffers" verdict itself.
     */
    public static function replayLevelMismatch(int $delta): void
    {
        for ($i = 0; $i < $delta; $i++) {
            ob_start();
        }
    }
}

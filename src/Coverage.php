<?php

declare(strict_types=1);

namespace Deminy\Counit;

use PHPUnit\Runner\CodeCoverage as CodeCoverageFacade;
use Swoole\Coroutine;

/**
 * The aggregate-coverage fix: one extra code-coverage window around the end-of-run drain.
 *
 * PHPUnit brackets each test with CodeCoverage::start()/stop() and the driver collects nothing
 * in between. Under counit the main coroutine never blocks during the test loop (each body hands
 * off to a coroutine at its first yield), so essentially ALL post-yield test code executes during
 * CounitExtension's drain -- after the last test's window closed -- and was never collected at
 * all: aggregate line/method coverage silently under-reported exactly the post-sleep/IO code this
 * package exists for (measured: 70% of lines under blocking PHPUnit vs 30% under counit, for a
 * fixture whose second half runs after a sleep).
 *
 * Opening one window (a synthetic "counit-drain" test id) around the drain closes the gap: the
 * aggregate report is a union over all windows, so post-yield lines are collected either in
 * whatever real test's window happened to be open when their coroutine resumed (mid-run joins,
 * the GlobalState barrier) or in this drain window -- making the aggregate match a blocking run
 * exactly, with full concurrency kept. Per-test ATTRIBUTION stays wrong by construction (drain
 * lines land under the synthetic id; lines run inside a joined test's window are attributed to
 * that test) -- the documented residual.
 *
 * Everything is fail-soft: no coverage configured, an inactive driver, or changed PHPUnit
 * internals simply leave the report as PHPUnit produced it.
 *
 * @internal this class is not covered by the backward compatibility promise for counit
 */
final class Coverage
{
    private static bool $drainWindowOpen = false;

    /**
     * Opens the drain coverage window. Call right before the end-of-run drain loop, on the main
     * coroutine; no-op unless coverage is active and at least one test coroutine is still
     * pending (a fully synchronous suite gets no synthetic entry in its per-test data).
     */
    public static function startDrainWindow(): void
    {
        try {
            if (!CodeCoverageFacade::instance()->isActive()) {
                return;
            }

            if (Coroutine::stats()['coroutine_num'] <= 1) { // @phpstan-ignore offsetAccess.nonOffsetAccessible
                return;
            }

            CodeCoverageFacade::instance()->codeCoverage()->start('counit-drain');
            self::$drainWindowOpen = true;
        } catch (\Throwable) {
            // Fail soft: an uncovered drain only means the old under-reported aggregate.
        }
    }

    /**
     * Closes the drain coverage window (appending what it collected), if one was opened.
     */
    public static function stopDrainWindow(): void
    {
        if (!self::$drainWindowOpen) {
            return;
        }

        self::$drainWindowOpen = false;

        try {
            CodeCoverageFacade::instance()->codeCoverage()->stop();
        } catch (\Throwable) {
            // Fail soft; see startDrainWindow().
        }
    }
}

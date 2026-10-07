<?php

declare(strict_types=1);

namespace Deminy\Counit\Tests;

use Deminy\Counit\Helper;
use Deminy\Counit\JunitXmlCorrector;
use PHPUnit\Event\DeferringDispatcher;
use PHPUnit\Event\DirectDispatcher;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * To test how counit degrades when PHPUnit's internals change.
 *
 * counit reads several PHPUnit internals through reflection. When one of them changes, counit must
 * fall back to its pre-existing behavior AND say so on STDERR: PHPUnit 13.4.0 broke three of them
 * at once, and every run kept exiting 0 without a word, so nobody noticed.
 *
 * @internal
 */
#[CoversClass(Helper::class)]
#[CoversClass(JunitXmlCorrector::class)]
class FailSoftNoticeTest extends TestCase
{
    /**
     * PHPUnit's parallel-execution work (planned for 13.5) wraps every entry of the event
     * dispatcher's subscriber list in an array. The JUnit and test-run-history corrections find
     * their subscribers through that list, so they must accept both shapes.
     */
    public function testSubscriberWalkAcceptsWrappedSubscriberEntries(): void
    {
        $expected = JunitXmlCorrector::registeredSubscribers();

        $facade     = EventFacade::instance();
        $deferring  = (new \ReflectionProperty($facade, 'deferringDispatcher'))->getValue($facade);
        $dispatcher = $deferring instanceof DeferringDispatcher ? (new \ReflectionProperty($deferring, 'dispatcher'))->getValue($deferring) : null;
        self::assertInstanceOf(DirectDispatcher::class, $dispatcher);

        $property = new \ReflectionProperty($dispatcher, 'subscribers');
        $original = $property->getValue($dispatcher);
        self::assertIsArray($original);

        // Entries that are already wrapped (a PHPUnit that ships the new shape) are kept as is.
        $wrapped = [];
        foreach ($original as $type => $subscribersOfType) {
            foreach ((array) $subscribersOfType as $entry) {
                $wrapped[$type][] = is_array($entry) ? $entry : ['subscriber' => $entry, 'eventsOfThisProcessOnly' => false];
            }
        }

        // No event may be dispatched while the list is in the foreign shape, so nothing but the
        // walk itself runs between the two writes.
        $property->setValue($dispatcher, $wrapped);
        try {
            $actual = JunitXmlCorrector::registeredSubscribers();
        } finally {
            $property->setValue($dispatcher, $original);
        }

        self::assertNotSame([], $expected, 'PHPUnit always registers subscribers of its own.');
        self::assertSame($expected, $actual);
    }

    public function testNoticeIsPrintedOncePerReason(): void
    {
        [$stdout, $stderr] = self::runNoticeScript(false);

        self::assertSame('', $stdout);
        self::assertSame(
            "counit notice: first reason. Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.\n"
            . "counit notice: second reason. Set COUNIT_SILENCE_TEARDOWN_NOTICE=1 to silence this notice.\n",
            $stderr
        );
    }

    public function testNoticeCanBeSilenced(): void
    {
        [$stdout, $stderr] = self::runNoticeScript(true);

        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
    }

    /**
     * Runs Helper::notice() in a child process, so its STDERR output can be observed.
     *
     * @return array{string, string} STDOUT and STDERR of the child process
     */
    private static function runNoticeScript(bool $silenced): array
    {
        $script = sprintf(
            'require %s; '
            . '\Deminy\Counit\Helper::notice("first", "first reason."); '
            . '\Deminy\Counit\Helper::notice("first", "first reason, repeated."); '
            . '\Deminy\Counit\Helper::notice("second", "second reason.");',
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true)
        );

        $environment = getenv();
        unset($environment['COUNIT_SILENCE_TEARDOWN_NOTICE']);
        if ($silenced) {
            $environment['COUNIT_SILENCE_TEARDOWN_NOTICE'] = '1';
        }

        $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        return [$stdout, $stderr];
    }
}

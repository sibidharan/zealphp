<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\TestCase;
use ZealPHP\LogSinkRegistry;

use function ZealPHP\async_logging_enabled;
use function ZealPHP\log_sink_for;
use function ZealPHP\log_sink_wake;

/**
 * The async log writer drains its queue and exits. A writer that waited on an
 * empty queue for the worker's whole life was still asleep when the worker
 * stopped, and OpenSwoole printed "all coroutines are asleep - deadlock!" on
 * every shutdown.
 */
final class AsyncLogWriterTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/zealphp-writer-' . bin2hex(random_bytes(4)) . '/test.log';
        LogSinkRegistry::reset();
    }

    protected function tearDown(): void
    {
        LogSinkRegistry::reset();
        @unlink($this->path);
        @rmdir(dirname($this->path));
    }

    /** Run $fn in a scheduler and return how many coroutines were alive at the end of it. */
    private function inScheduler(callable $fn): int
    {
        $alive = -1;
        Coroutine::run(static function () use ($fn, &$alive): void {
            $fn();
            $alive = Coroutine::stats()['coroutine_num'];
        });
        return $alive;
    }

    private function waitForWriter(): void
    {
        for ($i = 0; $i < 200 && isset(LogSinkRegistry::state()->writing[$this->path]); $i++) {
            Coroutine::usleep(5000);
        }
    }

    public function testWriterWritesTheQueueInOrderAndExits(): void
    {
        $alive = $this->inScheduler(function (): void {
            $queue = new Channel(16);
            $queue->push("one\n");
            $queue->push("two\n");
            $queue->push("three\n");
            log_sink_wake($this->path, $queue);
            $this->waitForWriter();
        });

        $this->assertSame("one\ntwo\nthree\n", (string) file_get_contents($this->path));
        $this->assertArrayNotHasKey($this->path, LogSinkRegistry::state()->writing);
        $this->assertSame(1, $alive, 'only the test coroutine should be left once the queue is empty');
    }

    public function testLineQueuedWhileTheWriterRunsIsWrittenByIt(): void
    {
        $this->inScheduler(function (): void {
            $queue = new Channel(16);
            $queue->push("first\n");
            log_sink_wake($this->path, $queue);
            $this->assertArrayHasKey($this->path, LogSinkRegistry::state()->writing);
            $queue->push("second\n");
            log_sink_wake($this->path, $queue); // already running: no second writer
            $this->waitForWriter();
        });

        $this->assertSame("first\nsecond\n", (string) file_get_contents($this->path));
    }

    public function testNextLineAfterTheWriterExitedStartsANewOne(): void
    {
        $this->inScheduler(function (): void {
            $queue = new Channel(16);
            $queue->push("a\n");
            log_sink_wake($this->path, $queue);
            $this->waitForWriter();
            $queue->push("b\n");
            log_sink_wake($this->path, $queue);
            $this->waitForWriter();
        });

        $this->assertSame("a\nb\n", (string) file_get_contents($this->path));
        $this->assertCount(1, LogSinkRegistry::state()->handles, 'the file is opened once and kept open');
    }

    public function testEmptyAndNonScalarItemsAreSkipped(): void
    {
        $this->inScheduler(function (): void {
            $queue = new Channel(16);
            $queue->push('');
            $queue->push(['not', 'a', 'line']);
            $queue->push("kept\n");
            log_sink_wake($this->path, $queue);
            $this->waitForWriter();
        });

        $this->assertSame("kept\n", (string) file_get_contents($this->path));
    }

    public function testUnopenableFileDoesNotLeaveTheWriterRunning(): void
    {
        $this->path = '/proc/zealphp-no-such-dir/test.log';
        // fopen()'s warning is @-suppressed, but PHPUnit's own handler still
        // runs and can't find the test from inside the writer coroutine.
        \set_error_handler(static fn (): bool => true);
        try {
            $this->inScheduler(function (): void {
                $queue = new Channel(4);
                $queue->push("lost to stderr\n");
                log_sink_wake($this->path, $queue);
                $this->waitForWriter();
            });
        } finally {
            \restore_error_handler();
        }

        $this->assertArrayNotHasKey($this->path, LogSinkRegistry::state()->writing);
        $this->assertSame([], LogSinkRegistry::state()->handles);
    }

    public function testResetClosesHandlesAndStartsFresh(): void
    {
        $this->inScheduler(function (): void {
            $queue = new Channel(4);
            $queue->push("x\n");
            log_sink_wake($this->path, $queue);
            $this->waitForWriter();
        });
        $handle = LogSinkRegistry::state()->handles[$this->path];
        $before = LogSinkRegistry::state();

        LogSinkRegistry::reset();

        $this->assertFalse(is_resource($handle), 'reset() closes open handles');
        $this->assertNotSame($before, LogSinkRegistry::state());
        $this->assertSame([], LogSinkRegistry::state()->queues);
    }

    public function testLogSinkForCreatesOneQueuePerFileInsideAScheduler(): void
    {
        if (!async_logging_enabled()) {
            $this->markTestSkipped('ZEALPHP_LOG_ASYNC is off');
        }
        $this->assertNull(log_sink_for($this->path), 'no scheduler: callers write synchronously');

        $queues = [];
        $this->inScheduler(function () use (&$queues): void {
            $queues[] = log_sink_for($this->path);
            $queues[] = log_sink_for($this->path);
        });

        $this->assertInstanceOf(Channel::class, $queues[0]);
        $this->assertSame($queues[0], $queues[1]);
        $this->assertSame($queues[0], LogSinkRegistry::state()->queues[$this->path]);
    }
}

<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use PHPUnit\Framework\TestCase;
use ZealPHP\LogSinkRegistry;

use function ZealPHP\async_logging_enabled;
use function ZealPHP\log_sink_append;
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

    public function testWhenNoCoroutineCanStartTheCallerWritesTheQueue(): void
    {
        $result = [];
        // go() fails at max_coroutine; its warning would reach PHPUnit's
        // handler from inside a coroutine, where it can't find the test.
        \set_error_handler(static fn (): bool => true);
        Coroutine::set(['max_coroutine' => 3]);
        try {
            Coroutine::run(function () use (&$result): void {
                $hold = new Channel(1);
                Coroutine::create(static fn () => $hold->pop(0.2)); // main + 2 = the limit
                Coroutine::create(static fn () => $hold->pop(0.2));
                $queue = new Channel(16);
                $queue->push("at the limit\n");
                log_sink_wake($this->path, $queue);
                $result['writing'] = isset(LogSinkRegistry::state()->writing[$this->path]);
                $result['left'] = $queue->length();
            });
        } finally {
            // OpenSwoole's default; the OPENSWOOLE_DEFAULT_MAX_CORO_NUM constant
            // isn't defined in every build.
            Coroutine::set(['max_coroutine' => 100000]);
            \restore_error_handler();
        }

        $this->assertFalse($result['writing'], 'the running flag must not be left set');
        $this->assertSame(0, $result['left']);
        $this->assertSame("at the limit\n", (string) file_get_contents($this->path));
    }

    public function testFileThatCantBeOpenedIsTriedAgainOnTheNextLine(): void
    {
        // A regular file where the log directory should be: mkdir and fopen fail.
        $blocker = dirname($this->path);
        @mkdir(dirname($blocker), 0700, true);
        file_put_contents($blocker, 'not a directory');
        $state = LogSinkRegistry::state();

        // An empty line, so the stderr fallback prints nothing.
        log_sink_append($state, $this->path, '');
        $this->assertSame([], $state->handles, 'a failed open is not cached');

        unlink($blocker);
        log_sink_append($state, $this->path, "now it opens\n");
        $this->assertArrayHasKey($this->path, $state->handles);
        $this->assertSame("now it opens\n", (string) file_get_contents($this->path));
    }
}

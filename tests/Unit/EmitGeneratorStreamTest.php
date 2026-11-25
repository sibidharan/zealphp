<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse;

/**
 * #354 — Generator/SSR streaming must NOT crash the worker when running
 * outside a coroutine (mixed / any enable_coroutine=false mode).
 *
 * emitGeneratorStream() used to call Coroutine::sleep(0) after every chunk to
 * yield to the scheduler. Outside a coroutine (PHPUnit, or mixed mode) that
 * throws "API must be called in the coroutine", which propagated uncaught and
 * killed the worker with status=255 after writing only the first chunk. The
 * fix guards the yield with Coroutine::getCid() > 0. PHPUnit itself runs
 * outside a coroutine, so reaching the end of this stream without an exception
 * IS the regression guard.
 */
final class EmitGeneratorStreamTest extends TestCase
{
    private FakeStreamResponse $fake;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        $g = RequestContext::instance();
        $this->fake = new FakeStreamResponse();
        $g->status = 200;
        $g->raw_status_code = null;
        $g->openswoole_response = $this->fake;
        $g->zealphp_response = new \ZealPHP\HTTP\Response($this->fake);
    }

    public function testMultiYieldStreamCompletesOutsideCoroutine(): void
    {
        // Sanity: we ARE outside a coroutine in PHPUnit.
        $this->assertLessThanOrEqual(0, \OpenSwoole\Coroutine::getCid());

        $gen = (function () {
            yield 'chunk-1';
            yield 'chunk-2';
        })();

        // Before the fix this threw OpenSwoole\Error mid-iteration.
        App::emitGeneratorStream($gen, 'GET');

        $writes = array_values(array_filter(
            $this->fake->log,
            fn ($e) => $e[0] === 'write'
        ));
        $this->assertSame('chunk-1', $writes[0][1]);
        $this->assertSame('chunk-2', $writes[1][1]);
        $this->assertCount(2, $writes, 'both chunks must be written, not just the first');

        $ends = array_filter($this->fake->log, fn ($e) => $e[0] === 'end');
        $this->assertCount(1, $ends, 'stream must be terminated with a clean end()');
    }

    /**
     * A client that disconnects mid-stream: write() returns false but
     * isWritable() stays true (OpenSwoole does this). The stream must stop
     * pulling the generator instead of running it to the end — or forever,
     * for an endless feed — so the generator's finally block can run.
     */
    public function testStopsPullingGeneratorWhenWriteFails(): void
    {
        $this->fake->writesBeforeDisconnect = 1;
        $pulled = 0;
        $finallyRan = false;
        $gen = (function () use (&$pulled, &$finallyRan) {
            try {
                while ($pulled < 1000) {
                    $pulled++;
                    yield "chunk-$pulled";
                }
            } finally {
                $finallyRan = true;
            }
        })();

        App::emitGeneratorStream($gen, 'GET');

        $this->assertSame(2, $pulled, 'the generator must not be pulled after the failed write');
        unset($gen);
        $this->assertTrue($finallyRan, "the generator's finally must run once it is released");
    }

    /**
     * The same when the failed write is echo output buffered between yields.
     */
    public function testStopsWhenBufferedEchoWriteFails(): void
    {
        $this->fake->writesBeforeDisconnect = 1;
        $pulled = 0;
        $gen = (function () use (&$pulled) {
            while ($pulled < 1000) {
                $pulled++;
                echo "echo-$pulled";
                yield '';
            }
        })();

        App::emitGeneratorStream($gen, 'GET');

        $this->assertSame(2, $pulled, 'the generator must not be pulled after the failed write');
    }

    /**
     * An empty yield is not a disconnect: write('') returns false on a live
     * OpenSwoole connection too, so empty chunks must be skipped rather than
     * end the stream.
     */
    public function testEmptyChunksDoNotEndTheStream(): void
    {
        $gen = (function () {
            yield 'a';
            yield '';
            yield null;
            echo 'b';
            yield '';
            yield 'c';
        })();

        App::emitGeneratorStream($gen, 'GET');

        $writes = array_values(array_map(
            fn ($e) => $e[1],
            array_filter($this->fake->log, fn ($e) => $e[0] === 'write')
        ));
        $this->assertSame(['a', 'b', 'c'], $writes);
    }
}

/**
 * FakeOpenSwooleResponse plus a no-op flush() (the real one needs a socket).
 * write() behaves like OpenSwoole's: false for an empty string, and false once
 * the client has gone ($writesBeforeDisconnect writes) while isWritable()
 * stays true.
 */
final class FakeStreamResponse extends FakeOpenSwooleResponse
{
    public int $writesBeforeDisconnect = PHP_INT_MAX;

    public function write(string $data): bool
    {
        if ($data === '' || $this->writesBeforeDisconnect <= 0) {
            return false;
        }
        $this->writesBeforeDisconnect--;
        return parent::write($data);
    }

    public function flush(): bool
    {
        $this->log[] = ['flush'];
        return true;
    }
}

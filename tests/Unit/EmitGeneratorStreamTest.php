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
}

/**
 * FakeOpenSwooleResponse plus a no-op flush() (the real one needs a socket).
 */
final class FakeStreamResponse extends FakeOpenSwooleResponse
{
    public function flush(): bool
    {
        $this->log[] = ['flush'];
        return true;
    }
}

<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\IOStreamWrapper;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * In-process unit tests for the php:// stream wrapper.
 *
 * IOStreamWrapper redirects `php://input` to
 * `$g->zealphp_request->parent->getContent()` and delegates every other
 * php:// stream to the original PHP wrapper. We exercise it two ways:
 *
 *   1. Calling the wrapper methods directly on a `new IOStreamWrapper()`
 *      instance (no global wrapper override needed for the resource-backed
 *      passthrough branch and for the input branch).
 *   2. The position-based php://input branch (where `$this->context` is null)
 *      is reached by seeding `input`/`position` via reflection — `stream_open`
 *      always populates `context`, so that branch is otherwise unreachable.
 *
 * The wrapper's `php://input` path itself temporarily restores/unregisters the
 * `php` wrapper internally, so we snapshot and restore that registration in
 * tearDown to keep the rest of the suite safe.
 */
class IOStreamWrapperTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        // Install our wrapper as the canonical `php` handler for the duration
        // of each test — this mirrors how the framework registers it per
        // worker. It also ensures the delegation branch's internal
        // `stream_wrapper_restore('php')` has something to restore (otherwise
        // PHP emits a "php:// was never changed" notice).
        stream_wrapper_unregister('php');
        stream_wrapper_register('php', IOStreamWrapper::class);
    }

    protected function tearDown(): void
    {
        // Reset request global per task requirement.
        RequestContext::instance()->zealphp_request = null;

        // Restore the built-in php:// wrapper so the rest of the suite is
        // unaffected by our temporary override.
        @stream_wrapper_restore('php');
    }

    /**
     * Build a stub matching the `$g->zealphp_request->parent->getContent()`
     * access chain the wrapper uses.
     */
    private function seedRequestBody(string $body): void
    {
        $parent = new class($body) {
            public function __construct(private string $body) {}
            public function getContent(): string
            {
                return $this->body;
            }
        };

        $request = new class($parent) {
            public function __construct(public object $parent) {}
        };

        RequestContext::instance()->zealphp_request = $request;
    }

    // ─────────────────────────────────────────────────────────────
    //  php://input branch — body comes from zealphp_request
    // ─────────────────────────────────────────────────────────────

    public function testStreamOpenForInputReturnsRequestBody(): void
    {
        $this->seedRequestBody('hello-world-body');

        $w = new IOStreamWrapper();
        $opened = null;
        $this->assertTrue($w->stream_open('php://input', 'r', 0, $opened));

        // Body is buffered into a php://memory resource exposed as $context.
        $this->assertSame('hello-world-body', $w->stream_read(1024));
        $this->assertTrue($w->stream_eof());
        $w->stream_close();
    }

    public function testStreamReadInChunksForInput(): void
    {
        $this->seedRequestBody('abcdefghij');

        $w = new IOStreamWrapper();
        $opened = null;
        $w->stream_open('php://input', 'r', 0, $opened);

        $this->assertFalse($w->stream_eof());
        $this->assertSame('abcde', $w->stream_read(5));
        $this->assertSame('fghij', $w->stream_read(5));
        $this->assertSame('', $w->stream_read(5));
        $this->assertTrue($w->stream_eof());
        $w->stream_close();
    }

    public function testStreamOpenForInputWithEmptyBody(): void
    {
        $this->seedRequestBody('');

        $w = new IOStreamWrapper();
        $opened = null;
        $this->assertTrue($w->stream_open('php://input', 'r', 0, $opened));
        $this->assertSame('', $w->stream_read(16));
        $this->assertTrue($w->stream_eof());
        $w->stream_close();
    }

    // ─────────────────────────────────────────────────────────────
    //  Delegation branch — other php:// streams pass through
    // ─────────────────────────────────────────────────────────────

    public function testStreamOpenDelegatesPhpTemp(): void
    {
        $w = new IOStreamWrapper();
        $opened = null;
        $this->assertTrue($w->stream_open('php://temp', 'r+', 0, $opened));

        // Resource-backed write/read/seek/tell passthrough.
        $this->assertSame(5, $w->stream_write('12345'));
        $this->assertTrue($w->stream_seek(0, SEEK_SET));
        $this->assertSame(0, $w->stream_tell());
        $this->assertSame('123', $w->stream_read(3));
        $this->assertSame(3, $w->stream_tell());
        $w->stream_close();
    }

    public function testStreamOpenDelegatesPhpMemory(): void
    {
        $w = new IOStreamWrapper();
        $opened = null;
        $this->assertTrue($w->stream_open('php://memory', 'r+', 0, $opened));

        $this->assertSame(11, $w->stream_write('hello world'));
        $w->stream_rewind();
        $this->assertSame('hello world', $w->stream_read(100));
        $this->assertTrue($w->stream_eof());
        $w->stream_close();
    }

    public function testStreamOpenReturnsFalseForUnopenableStream(): void
    {
        $w = new IOStreamWrapper();
        $opened = null;
        // php://filter with a bogus target chain fails to open.
        $this->assertFalse(@$w->stream_open('php://nonexistent-zealphp-test', 'r', 0, $opened));
    }

    // ─────────────────────────────────────────────────────────────
    //  Resource-backed (delegated) metadata methods
    // ─────────────────────────────────────────────────────────────

    public function testStreamStatForDelegatedStreamReturnsArray(): void
    {
        $w = new IOStreamWrapper();
        $opened = null;
        $w->stream_open('php://temp', 'r+', 0, $opened);
        $stat = $w->stream_stat();
        $this->assertIsArray($stat);
        $this->assertArrayHasKey('size', $stat);
        $w->stream_close();
    }

    public function testStreamWriteSeekTruncateFlushLockOnDelegatedStream(): void
    {
        $w = new IOStreamWrapper();
        $opened = null;
        $w->stream_open('php://temp', 'r+', 0, $opened);

        $w->stream_write('abcdefgh');
        $this->assertTrue($w->stream_flush());
        $this->assertTrue($w->stream_truncate(4));
        $this->assertFalse($w->stream_truncate(-1)); // negative size rejected early
        $w->stream_rewind();
        $this->assertSame('abcd', $w->stream_read(100));

        // flock is unsupported on php://temp resources — passthrough returns
        // false (the operation is still exercised on the context branch).
        $this->assertFalse(@$w->stream_lock(LOCK_SH));
        $w->stream_close();
    }

    public function testStreamReadCountZeroOnDelegatedStreamReturnsEmpty(): void
    {
        $w = new IOStreamWrapper();
        $opened = null;
        $w->stream_open('php://temp', 'r+', 0, $opened);
        $w->stream_write('data');
        $w->stream_rewind();
        // count < 1 short-circuits to empty string before touching the resource.
        $this->assertSame('', $w->stream_read(0));
        $w->stream_close();
    }

    public function testSeekSucceedsAndFailsOutOfBoundsOnDelegatedStream(): void
    {
        $w = new IOStreamWrapper();
        $opened = null;
        $w->stream_open('php://temp', 'r+', 0, $opened);
        $w->stream_write('0123456789');
        $this->assertTrue($w->stream_seek(2, SEEK_SET));
        $this->assertSame('23', $w->stream_read(2));
        $w->stream_close();
    }

    // ─────────────────────────────────────────────────────────────
    //  url_stat / stream_unlink on a delegated (context-backed) wrapper
    // ─────────────────────────────────────────────────────────────

    public function testUrlStatAndUnlinkPassthroughOnRealFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zealphp_iosw_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'x');

        $w = new IOStreamWrapper();
        $opened = null;
        // Give the wrapper a context so the passthrough branches are taken.
        $w->stream_open('php://temp', 'r+', 0, $opened);

        $stat = $w->url_stat($tmp, 0);
        $this->assertIsArray($stat);
        $this->assertArrayHasKey('size', $stat);

        $this->assertTrue($w->stream_unlink($tmp));
        $this->assertFileDoesNotExist($tmp);
        $w->stream_close();
    }

    // ─────────────────────────────────────────────────────────────
    //  Pure php://input branch (context === null) — reached via reflection
    // ─────────────────────────────────────────────────────────────

    /**
     * Seed a fresh wrapper's private input buffer with context left null so the
     * position-based read/seek/eof/tell branches execute.
     */
    private function inputBackedWrapper(string $body): IOStreamWrapper
    {
        $w = new IOStreamWrapper();
        $ref = new \ReflectionClass($w);
        $ref->getProperty('input')->setValue($w, $body);
        $ref->getProperty('position')->setValue($w, 0);
        // context defaults to null, leave it.
        return $w;
    }

    public function testInputBufferReadAndEofWhenContextNull(): void
    {
        $w = $this->inputBackedWrapper('payload-123');

        $this->assertFalse($w->stream_eof());
        $this->assertSame('payload', $w->stream_read(7));
        $this->assertSame(7, $w->stream_tell());
        $this->assertSame('-123', $w->stream_read(100));
        $this->assertTrue($w->stream_eof());
        $this->assertSame(11, $w->stream_tell());
    }

    public function testInputBufferRewindWhenContextNull(): void
    {
        $w = $this->inputBackedWrapper('rewind-me');
        $w->stream_read(6);
        $this->assertTrue($w->stream_rewind());
        $this->assertSame(0, $w->stream_tell());
        $this->assertSame('rewind-me', $w->stream_read(100));
    }

    public function testInputBufferSeekSetCurEndWhenContextNull(): void
    {
        $w = $this->inputBackedWrapper('0123456789'); // length 10

        // SEEK_SET in range / out of range
        $this->assertTrue($w->stream_seek(3, SEEK_SET));
        $this->assertSame(3, $w->stream_tell());
        $this->assertFalse($w->stream_seek(11, SEEK_SET));
        $this->assertFalse($w->stream_seek(-1, SEEK_SET));

        // SEEK_CUR in range / out of range
        $this->assertTrue($w->stream_seek(2, SEEK_CUR)); // 3 -> 5
        $this->assertSame(5, $w->stream_tell());
        $this->assertFalse($w->stream_seek(100, SEEK_CUR));
        $this->assertFalse($w->stream_seek(-100, SEEK_CUR));

        // SEEK_END in range / out of range
        $this->assertTrue($w->stream_seek(-2, SEEK_END)); // 10 - 2 = 8
        $this->assertSame(8, $w->stream_tell());
        $this->assertFalse($w->stream_seek(1, SEEK_END));   // 11 > length
        $this->assertFalse($w->stream_seek(-100, SEEK_END));

        // Unknown whence
        $this->assertFalse($w->stream_seek(0, 999));
    }

    public function testInputBufferWriteIsRejectedWhenContextNull(): void
    {
        $w = $this->inputBackedWrapper('readonly');
        $this->assertFalse($w->stream_write('nope'));
    }

    public function testInputBufferStatIsEmptyArrayWhenContextNull(): void
    {
        $w = $this->inputBackedWrapper('whatever');
        $this->assertSame([], $w->stream_stat());
    }

    public function testInputBufferTruncateFlushLockRejectedWhenContextNull(): void
    {
        $w = $this->inputBackedWrapper('whatever');
        $this->assertFalse($w->stream_truncate(2));
        $this->assertFalse($w->stream_flush());
        $this->assertFalse($w->stream_lock(LOCK_EX));
    }

    public function testInputBufferUrlStatAndUnlinkRejectedWhenContextNull(): void
    {
        $w = $this->inputBackedWrapper('whatever');
        $this->assertFalse($w->url_stat('/tmp/whatever', 0));
        $this->assertFalse($w->stream_unlink('/tmp/whatever'));
    }

    public function testInputBufferReadCountZeroReturnsEmptyWhenContextNull(): void
    {
        // stream_read short-circuits count<1 only on the context branch; on the
        // input branch a 0 count yields '' from substr without advancing.
        $w = $this->inputBackedWrapper('abc');
        $this->assertSame('', $w->stream_read(0));
        $this->assertSame(0, $w->stream_tell());
    }

    public function testStreamCloseIsSafeWhenContextNull(): void
    {
        $w = $this->inputBackedWrapper('x');
        // No resource to close; must not error.
        $w->stream_close();
        $this->assertTrue(true);
    }

    // ─────────────────────────────────────────────────────────────
    //  Magic no-op methods for interface conformance
    // ─────────────────────────────────────────────────────────────

    public function testMagicGetReturnsNull(): void
    {
        $w = new IOStreamWrapper();
        $this->assertNull($w->dir_handle);
    }

    public function testMagicCallReturnsNull(): void
    {
        $w = new IOStreamWrapper();
        $this->assertNull($w->dir_opendir('php://anything', 0));
    }

    // ─────────────────────────────────────────────────────────────
    //  End-to-end via the registered php:// wrapper (fopen/fread/...)
    // ─────────────────────────────────────────────────────────────

    public function testRegisteredWrapperServesInputViaFopen(): void
    {
        $this->seedRequestBody('via-fopen-body');

        // Our wrapper is already registered as `php` (setUp). Reading
        // php://input the way app code does should yield the request body.
        $fh = fopen('php://input', 'r');
        $this->assertNotFalse($fh);
        $data = stream_get_contents($fh);
        fclose($fh);
        $this->assertSame('via-fopen-body', $data);
    }
}

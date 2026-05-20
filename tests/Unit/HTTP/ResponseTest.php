<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\HTTP\Response as ZResponse;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Characterization tests for ZealPHP\HTTP\Response.
 *
 * The wrapper forwards to an underlying OpenSwoole\Http\Response. We subclass
 * that class with an anonymous capture stub (recording status/header/cookie/
 * end/write/sendfile calls into a public array) so the logic-bearing methods
 * can be exercised without a live socket.
 */
class ResponseTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $g = RequestContext::instance();
        $g->status = null;
        $g->_streaming = false;
        $g->server = [];
        $this->setRequestHeaders([]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->tmpFiles = [];
        parent::tearDown();
    }

    /**
     * Build a capturing fake OpenSwoole response.
     */
    private function fake(bool $writable = true): FakeOpenSwooleResponse
    {
        $f = new FakeOpenSwooleResponse();
        $f->writable = $writable;
        return $f;
    }

    private function wrap(FakeOpenSwooleResponse $fake): ZResponse
    {
        return new ZResponse($fake);
    }

    /**
     * @param array<string, string> $headers
     */
    private function setRequestHeaders(array $headers): void
    {
        $g = RequestContext::instance();
        $or = new \OpenSwoole\Http\Request();
        $or->header = $headers;
        $g->zealphp_request = new ZRequest($or);
    }

    private function makeTempFile(string $contents, string $ext = 'css'): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'zealphp_resp_') . '.' . $ext;
        file_put_contents($file, $contents);
        $this->tmpFiles[] = $file;
        return $file;
    }

    // ---- status() ----------------------------------------------------------

    public function testStatusRecordsCodeAndUpdatesContext(): void
    {
        $g = RequestContext::instance();
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $this->assertTrue($resp->status(201));
        $this->assertSame(201, $g->status);
        $this->assertSame([['status', 201, '']], $fake->log);
    }

    public function testStatusPassesReasonPhrase(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->status(451, 'Unavailable For Legal Reasons');
        $this->assertSame([['status', 451, 'Unavailable For Legal Reasons']], $fake->log);
    }

    // ---- header() ----------------------------------------------------------

    public function testHeaderQueuesIntoHeadersList(): void
    {
        $resp = $this->wrap($this->fake());
        $this->assertTrue($resp->header('X-Custom', 'value'));
        $this->assertSame([['X-Custom', 'value']], $resp->headersList);
    }

    public function testHeaderBlocksCrlfInjectionInValue(): void
    {
        $resp = $this->wrap($this->fake());
        $ok = @$resp->header('X-Test', "evil\r\nSet-Cookie: x=1");
        $this->assertFalse($ok);
        $this->assertSame([], $resp->headersList);
    }

    public function testHeaderBlocksControlCharsInName(): void
    {
        $resp = $this->wrap($this->fake());
        $this->assertFalse(@$resp->header("X:Bad", 'v'));
        $this->assertFalse(@$resp->header("X Bad", 'v'));
        $this->assertSame([], $resp->headersList);
    }

    public function testLocationHeaderAutoSets302(): void
    {
        $g = RequestContext::instance();
        $g->status = null;
        $resp = $this->wrap($this->fake());

        $resp->header('Location', '/elsewhere');
        $this->assertSame(302, $g->status);
    }

    public function testLocationHeaderDoesNotOverrideExplicitStatus(): void
    {
        $g = RequestContext::instance();
        $g->status = 301;
        $resp = $this->wrap($this->fake());

        $resp->header('Location', '/elsewhere');
        $this->assertSame(301, $g->status);
    }

    // ---- json() ------------------------------------------------------------

    public function testJsonSetsContentTypeStatusAndBody(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->json(['ok' => true], 201);

        // header() queues into headersList (not emitted to the parent until flush).
        $this->assertContains(['Content-Type', 'application/json'], $resp->headersList);
        $this->assertContains(['status', 201, ''], $fake->log);
        $this->assertContains(['end', '{"ok":true}'], $fake->log);
    }

    public function testJsonDefaultStatusIs200(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->json(['a' => 1]);
        $this->assertContains(['status', 200, ''], $fake->log);
    }

    // ---- cookie() / rawCookie() --------------------------------------------

    public function testCookieQueuesFullTuple(): void
    {
        $resp = $this->wrap($this->fake());
        $this->assertTrue($resp->cookie('sid', 'abc', 100, '/x', 'ex.com', true, true, 'Lax', 'High'));

        $this->assertSame(
            [['sid', 'abc', 100, '/x', 'ex.com', true, true, 'Lax', 'High']],
            $resp->cookiesList
        );
    }

    public function testRawCookieQueuesFullTuple(): void
    {
        $resp = $this->wrap($this->fake());
        $this->assertTrue($resp->rawCookie('raw', 'v'));

        $this->assertSame(
            [['raw', 'v', 0, '/', '', false, false, '', '']],
            $resp->rawCookiesList
        );
    }

    // ---- redirect() --------------------------------------------------------

    public function testRedirectEmitsStatusLocationAndEnds(): void
    {
        $g = RequestContext::instance();
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->redirect('/login', 301);

        $this->assertSame(301, $g->status);
        $this->assertTrue($g->_streaming);
        $this->assertContains(['status', 301, 'Moved Permanently'], $fake->log);
        $this->assertContains(['header', 'Location', '/login'], $fake->log);
        $this->assertContains(['end', null], $fake->log);
    }

    public function testRedirectDefaultsTo302(): void
    {
        $g = RequestContext::instance();
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->redirect('/home');
        $this->assertSame(302, $g->status);
        $this->assertContains(['status', 302, 'Found'], $fake->log);
    }

    public function testRedirectUsesEmptyReasonForUnknownStatus(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->redirect('/x', 309);
        $this->assertContains(['status', 309, ''], $fake->log);
    }

    public function testRedirectRejectsControlCharacters(): void
    {
        $resp = $this->wrap($this->fake());
        $this->expectException(\InvalidArgumentException::class);
        $resp->redirect("/x\r\nSet-Cookie: a=b");
    }

    public function testRedirectRejectsLeadingWhitespace(): void
    {
        $resp = $this->wrap($this->fake());
        $this->expectException(\InvalidArgumentException::class);
        $resp->redirect('   javascript:alert(1)');
    }

    public function testRedirectRejectsBackslash(): void
    {
        $resp = $this->wrap($this->fake());
        $this->expectException(\InvalidArgumentException::class);
        $resp->redirect('/\\evil.com');
    }

    public function testRedirectRejectsJavascriptScheme(): void
    {
        $resp = $this->wrap($this->fake());
        $this->expectException(\InvalidArgumentException::class);
        $resp->redirect('javascript:alert(1)');
    }

    public function testRedirectRejectsDataScheme(): void
    {
        $resp = $this->wrap($this->fake());
        $this->expectException(\InvalidArgumentException::class);
        $resp->redirect('data:text/html,<script>x</script>');
    }

    public function testRedirectAllowsProtocolRelativeWithWarning(): void
    {
        // Protocol-relative URLs are allowed (logged as a warning) — they still redirect.
        $g = RequestContext::instance();
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->redirect('//cdn.example.com/asset');
        $this->assertSame(302, $g->status);
        $this->assertContains(['header', 'Location', '//cdn.example.com/asset'], $fake->log);
    }

    public function testRedirectCrossOriginAbsoluteAllowed(): void
    {
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'mysite.com'];
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->redirect('https://other.com/page');
        $this->assertSame(302, $g->status);
        $this->assertContains(['header', 'Location', 'https://other.com/page'], $fake->log);
    }

    public function testRedirectFlushesQueuedCookies(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->cookie('sid', 'v');
        $resp->rawCookie('raw', 'w');

        $resp->redirect('/done');

        $cookieCalls = array_values(array_filter($fake->log, static fn(array $e): bool => $e[0] === 'cookie'));
        $rawCalls = array_values(array_filter($fake->log, static fn(array $e): bool => $e[0] === 'rawCookie'));
        $this->assertNotEmpty($cookieCalls);
        $this->assertNotEmpty($rawCalls);
    }

    public function testRedirectNonWritableSkipsEmission(): void
    {
        $g = RequestContext::instance();
        $fake = $this->fake(writable: false);
        $resp = $this->wrap($fake);

        $resp->redirect('/x', 307);
        // Context still records the status + queued header, but nothing emitted.
        $this->assertSame(307, $g->status);
        $this->assertSame([], $fake->log);
        $this->assertContains(['Location', '/x'], $resp->headersList);
    }

    // ---- end() -------------------------------------------------------------

    public function testEndForwardsToParent(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $this->assertTrue($resp->end('body'));
        $this->assertContains(['end', 'body'], $fake->log);
    }

    // ---- flush() -----------------------------------------------------------

    public function testFlushEmitsAndClearsQueues(): void
    {
        $g = RequestContext::instance();
        $g->status = 200;
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $resp->header('X-A', '1');
        $resp->cookie('c', 'v');
        $resp->rawCookie('r', 'w');

        $this->assertTrue($resp->flush());

        $this->assertContains(['header', 'X-A', '1'], $fake->log);
        $this->assertSame([], $resp->headersList);
        $this->assertSame([], $resp->cookiesList);
        $this->assertSame([], $resp->rawCookiesList);
        $this->assertNull($g->status);
    }

    public function testFlushReturnsFalseWhenNotWritable(): void
    {
        $fake = $this->fake(writable: false);
        $resp = $this->wrap($fake);
        $resp->header('X-A', '1');

        $this->assertFalse($resp->flush());
        // Queue left intact for a later attempt.
        $this->assertSame([['X-A', '1']], $resp->headersList);
    }

    // ---- stream() ----------------------------------------------------------

    public function testStreamWritesChunksAndEnds(): void
    {
        $g = RequestContext::instance();
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->stream(function (callable $write): void {
            $write('hello ');
            $write('world');
        });

        $this->assertTrue($g->_streaming);
        $this->assertContains(['write', 'hello '], $fake->log);
        $this->assertContains(['write', 'world'], $fake->log);
        $this->assertContains(['end', null], $fake->log);
    }

    public function testStreamSwallowsExceptionsFromCallback(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        // Should not propagate the throw.
        $resp->stream(function (callable $write): void {
            $write('partial');
            throw new \RuntimeException('client gone');
        });

        $this->assertContains(['write', 'partial'], $fake->log);
        // end() still attempted since writable.
        $this->assertContains(['end', null], $fake->log);
    }

    public function testStreamWriteReturnsFalseWhenNotWritable(): void
    {
        $fake = $this->fake(writable: false);
        $resp = $this->wrap($fake);

        $captured = null;
        $resp->stream(function (callable $write) use (&$captured): void {
            $captured = $write('data');
        });

        $this->assertFalse($captured);
        // No write/end emitted because not writable.
        $this->assertSame([], $fake->log);
    }

    // ---- sse() -------------------------------------------------------------

    public function testSseSetsHeadersAndFormatsMessages(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sse(function (callable $emit): void {
            $emit('payload', 'update', '42');
            $emit('plain');
        });

        // SSE headers are flushed before the stream callback runs.
        $this->assertContains(['header', 'Content-Type', 'text/event-stream'], $fake->log);
        $this->assertContains(['header', 'Cache-Control', 'no-cache'], $fake->log);
        $this->assertContains(['header', 'X-Accel-Buffering', 'no'], $fake->log);

        $this->assertContains(['write', "id: 42\nevent: update\ndata: payload\n\n"], $fake->log);
        $this->assertContains(['write', "data: plain\n\n"], $fake->log);
    }

    // ---- sendFile() --------------------------------------------------------

    public function testSendFileMissingReturns404(): void
    {
        $g = RequestContext::instance();
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile('/no/such/file.css');

        $this->assertTrue($g->_streaming);
        $this->assertContains(['status', 404, ''], $fake->log);
        $this->assertContains(['end', 'File not found'], $fake->log);
    }

    public function testSendFileFullEmitsHeadersAndSendfile(): void
    {
        $path = $this->makeTempFile(str_repeat('a', 100), 'css');
        $this->setRequestHeaders([]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $headers = $this->headerCalls($fake);
        $this->assertContains(['header', 'Content-Type', 'text/css'], $headers);
        $this->assertContains(['header', 'Accept-Ranges', 'bytes'], $headers);
        $this->assertContains(['header', 'Content-Length', '100'], $headers);
        // sendfile(path, offset=0, length=total)
        $this->assertContains(['sendfile', $path, 0, 100], $fake->log);
    }

    public function testSendFileWithFilenameAddsContentDisposition(): void
    {
        $path = $this->makeTempFile('data', 'bin');
        $this->setRequestHeaders([]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path, 'report.bin');

        $this->assertContains(
            ['header', 'Content-Disposition', 'attachment; filename="report.bin"'],
            $this->headerCalls($fake)
        );
    }

    public function testSendFileRangeReturns206(): void
    {
        $path = $this->makeTempFile(str_repeat('b', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=0-9']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes 0-9/100'], $this->headerCalls($fake));
        $this->assertContains(['header', 'Content-Length', '10'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 0, 10], $fake->log);
    }

    public function testSendFileSuffixRange(): void
    {
        $path = $this->makeTempFile(str_repeat('c', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=-10']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes 90-99/100'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 90, 10], $fake->log);
    }

    public function testSendFileUnsatisfiableRangeReturns416(): void
    {
        $path = $this->makeTempFile(str_repeat('d', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=500-600']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 416, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes */100'], $this->headerCalls($fake));
    }

    public function testSendFileIfNoneMatchReturns304(): void
    {
        $path = $this->makeTempFile(str_repeat('e', 50), 'css');
        $mtime = (int) filemtime($path);
        $etag = 'W/"' . dechex($mtime) . '-' . dechex(50) . '"';
        $this->setRequestHeaders(['if-none-match' => $etag]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 304, ''], $fake->log);
        $this->assertContains(['end', ''], $fake->log);
    }

    public function testSendFileIfModifiedSinceReturns304(): void
    {
        $path = $this->makeTempFile('x', 'css');
        $mtime = (int) filemtime($path);
        $this->setRequestHeaders(['if-modified-since' => gmdate('D, d M Y H:i:s', $mtime + 100) . ' GMT']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 304, ''], $fake->log);
    }

    public function testSendFileGuessesJsMime(): void
    {
        $path = $this->makeTempFile('console.log(1);', 'js');
        $this->setRequestHeaders([]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['header', 'Content-Type', 'application/javascript'], $this->headerCalls($fake));
    }

    // ---- __call / __get proxies --------------------------------------------

    public function testCallProxiesToParent(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        // detach() exists on the parent stub — proxied through __call.
        $this->assertTrue($resp->detach());
        $this->assertContains(['detach'], $fake->log);
    }

    public function testCallThrowsForUnknownMethod(): void
    {
        $resp = $this->wrap($this->fake());
        $this->expectException(\BadMethodCallException::class);
        // @phpstan-ignore method.notFound
        $resp->thisMethodDoesNotExist();
    }

    public function testGetParentReturnsUnderlyingResponse(): void
    {
        $fake = $this->fake();
        $resp = $this->wrap($fake);
        $this->assertSame($fake, $resp->parent);
    }

    /**
     * Extract only the header() calls from the fake log.
     *
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function headerCalls(FakeOpenSwooleResponse $fake): array
    {
        $out = [];
        foreach ($fake->log as $entry) {
            if (($entry[0] ?? null) === 'header') {
                /** @var array{0: string, 1: string, 2: string} $entry */
                $out[] = $entry;
            }
        }
        return $out;
    }
}

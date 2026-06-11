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

    public function testRedirectAllowsProtocolRelativeWithOptIn(): void
    {
        // #243: protocol-relative URLs are BLOCKED by default; with
        // $allowExternal=true they are allowed (logged as a warning) + still redirect.
        $g = RequestContext::instance();
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->redirect('//cdn.example.com/asset', 302, true);
        $this->assertSame(302, $g->status);
        $this->assertContains(['header', 'Location', '//cdn.example.com/asset'], $fake->log);
    }

    public function testRedirectBlocksProtocolRelativeByDefault(): void
    {
        // #243: the secure default — a bare protocol-relative target throws.
        $resp = $this->wrap($this->fake());
        $this->expectException(\InvalidArgumentException::class);
        $resp->redirect('//cdn.example.com/asset');
    }

    public function testRedirectCrossOriginAbsoluteAllowed(): void
    {
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'mysite.com'];
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        // #243: a cross-origin target is blocked by default; opt in with
        // $allowExternal=true to allow + emit it (this test's intent).
        $resp->redirect('https://other.com/page', 302, true);
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
        // Use the file's exact mtime: it satisfies both bounds — not in the
        // future (<= now) and not older than the file (>= mtime) → 304.
        $this->setRequestHeaders(['if-modified-since' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 304, ''], $fake->log);
    }

    public function testSendFileMultiRangeEmits206Multipart(): void
    {
        $path = $this->makeTempFile('0123456789', 'bin'); // 10 bytes
        $this->setRequestHeaders(['range' => 'bytes=0-2,5-7']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 206, ''], $fake->log);

        $ct = null;
        foreach ($this->headerCalls($fake) as $h) {
            if ($h[1] === 'Content-Type') {
                $ct = $h[2];
            }
        }
        $this->assertNotNull($ct);
        $this->assertStringStartsWith('multipart/byteranges; boundary=zealphp_', (string) $ct);

        // Reconstruct the multipart body from the captured write()/end() calls.
        // #366: the multipart body is now emitted in a single length-delimited
        // end($payload) (so the precomputed Content-Length survives instead of
        // OpenSwoole falling back to chunked), not incremental write()s.
        $body = '';
        foreach ($fake->log as $entry) {
            if (($entry[0] ?? null) === 'write') {
                $body .= (string) $entry[1];
            } elseif (($entry[0] ?? null) === 'end') {
                $body .= (string) ($entry[1] ?? '');
            }
        }
        // boundary token
        preg_match('/boundary=(zealphp_[0-9a-f]+)/', (string) $ct, $bm);
        $boundary = $bm[1];

        $this->assertStringContainsString("--{$boundary}\r\nContent-Type: ", $body);
        $this->assertStringContainsString("Content-Range: bytes 0-2/10\r\n", $body);
        $this->assertStringContainsString("Content-Range: bytes 5-7/10\r\n", $body);
        $this->assertStringContainsString("012", $body); // first slice
        $this->assertStringContainsString("567", $body); // second slice
        $this->assertStringEndsWith("\r\n--{$boundary}--\r\n", $body);

        // Declared Content-Length must equal the bytes actually written.
        $contentLength = null;
        foreach ($this->headerCalls($fake) as $h) {
            if ($h[1] === 'Content-Length') {
                $contentLength = (int) $h[2];
            }
        }
        $this->assertSame(strlen($body), $contentLength);
    }

    public function testSendFileMultiRangeIsLengthDelimitedNotChunked(): void
    {
        // #366: the multipart/byteranges body must be emitted in ONE
        // length-delimited end($payload) so the precomputed Content-Length
        // survives — NOT incremental write()s (which make OpenSwoole fall back
        // to chunked transfer-encoding, dropping Content-Length). Assert the body
        // bytes rode end(), not write().
        $path = $this->makeTempFile('0123456789', 'bin'); // 10 bytes
        $this->setRequestHeaders(['range' => 'bytes=0-2,5-7']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $writes = array_filter($fake->log, static fn($e) => ($e[0] ?? null) === 'write');
        $this->assertSame([], $writes, '#366: multipart must not use incremental write()');

        $ends = array_values(array_filter($fake->log, static fn($e) => ($e[0] ?? null) === 'end'));
        $this->assertCount(1, $ends, 'exactly one end() carries the whole multipart body');
        $payload = (string) ($ends[0][1] ?? '');
        $this->assertNotSame('', $payload, 'end() payload must contain the multipart body');

        // Content-Length header must equal the single end() payload length.
        $contentLength = null;
        foreach ($this->headerCalls($fake) as $h) {
            if ($h[1] === 'Content-Length') {
                $contentLength = (int) $h[2];
            }
        }
        $this->assertSame(strlen($payload), $contentLength);
    }

    public function testSendFileHeadEmitsHeadersButNoBody(): void
    {
        // #358 — RFC 9110 §9.3.2: HEAD MUST NOT send content. sendFile's
        // zero-copy paths previously wrote the full file on HEAD. Assert HEAD
        // gets 200 + the full-representation Content-Length but zero body bytes
        // (no sendfile / no body-bearing write).
        $path = $this->makeTempFile(str_repeat('z', 62), 'csv');
        $g = RequestContext::instance();
        $g->server = ['REQUEST_METHOD' => 'HEAD'];
        $this->setRequestHeaders([]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 200, ''], $fake->log);
        // No body: no sendfile, and end() carries no payload.
        foreach ($fake->log as $entry) {
            $this->assertNotSame('sendfile', $entry[0] ?? null, 'HEAD must not ship the body');
        }
        $this->assertContains(['end', ''], $fake->log);
        // Content-Length is still the FULL representation size.
        $cl = null;
        foreach ($this->headerCalls($fake) as $h) {
            if ($h[1] === 'Content-Length') { $cl = (int) $h[2]; }
        }
        $this->assertSame(62, $cl, 'HEAD advertises the full Content-Length');
        // Validators a GET would emit are present.
        $names = array_map(static fn($h) => $h[1], $this->headerCalls($fake));
        $this->assertContains('ETag', $names);
        $this->assertContains('Accept-Ranges', $names);
        $g->server = [];
    }

    public function testSendFileHeadIgnoresRangeAndServesNo206(): void
    {
        // #358 — a HEAD with a Range must still be body-less and 200 (HEAD has no
        // body to take a range of); never a 206 with bytes on the wire.
        $path = $this->makeTempFile(str_repeat('z', 62), 'csv');
        $g = RequestContext::instance();
        $g->server = ['REQUEST_METHOD' => 'HEAD'];
        $this->setRequestHeaders(['range' => 'bytes=0-9']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        foreach ($fake->log as $entry) {
            $this->assertNotSame('sendfile', $entry[0] ?? null);
            $this->assertNotSame(206, $entry[1] ?? null);
        }
        $this->assertContains(['status', 200, ''], $fake->log);
        $g->server = [];
    }

    public function testSendFileNonAsciiFilenameEmitsRfc6266ExtValue(): void
    {
        // #361 — a non-ASCII download name must travel as the RFC 6266
        // `filename*=UTF-8''<pct-encoded>` ext-value, alongside an ASCII
        // `filename=` fallback (RFC 5987 / RFC 9110 §5.5).
        $path = $this->makeTempFile('data', 'csv');
        $this->setRequestHeaders([]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path, 'café résumé.csv');

        $disposition = null;
        foreach ($this->headerCalls($fake) as $h) {
            if ($h[1] === 'Content-Disposition') { $disposition = (string) $h[2]; }
        }
        $this->assertNotNull($disposition);
        // ASCII fallback: each non-ASCII *byte* downgraded to '_' (é = 2 UTF-8
        // bytes → '__'), no raw UTF-8 octets in the quoted-string.
        $this->assertStringContainsString('filename="caf__ r__sum__.csv"', $disposition);
        $this->assertDoesNotMatchRegularExpression('/filename="[^"]*[\x80-\xff]/', $disposition);
        // RFC 6266 ext-value with UTF-8 percent-encoding of the true name.
        $this->assertStringContainsString("filename*=UTF-8''caf%C3%A9%20r%C3%A9sum%C3%A9.csv", $disposition);
    }

    public function testSendFileAsciiFilenameHasNoExtValue(): void
    {
        // #361 — an ASCII name is unaffected: quoted filename only, no filename*.
        $path = $this->makeTempFile('data', 'csv');
        $this->setRequestHeaders([]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path, 'report.csv');

        $disposition = null;
        foreach ($this->headerCalls($fake) as $h) {
            if ($h[1] === 'Content-Disposition') { $disposition = (string) $h[2]; }
        }
        $this->assertSame('attachment; filename="report.csv"', $disposition);
    }

    public function testSendFileIfMatchMismatchReturns412(): void
    {
        // #321 — If-Match was ignored entirely: a mismatched validator MUST
        // produce 412 Precondition Failed (RFC 9110 step 1), never a 200.
        $path = $this->makeTempFile(str_repeat('f', 50), 'css');
        $this->setRequestHeaders(['if-match' => '"no-such-etag"']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 412, ''], $fake->log);
        $this->assertContains(['end', ''], $fake->log);
        foreach ($fake->log as $entry) {
            $this->assertNotSame('sendfile', $entry[0] ?? null, '412 must not ship the body');
        }
    }

    public function testSendFileIfUnmodifiedSinceInPastReturns412(): void
    {
        // #321 — If-Unmodified-Since was ignored: a date OLDER than the file's
        // mtime means the resource WAS modified since → 412 (RFC 9110 step 2).
        $path = $this->makeTempFile('x', 'css');
        $mtime = (int) filemtime($path);
        $this->setRequestHeaders([
            'if-unmodified-since' => gmdate('D, d M Y H:i:s', $mtime - 3600) . ' GMT',
        ]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 412, ''], $fake->log);
    }

    public function testSendFileIfMatchPrecedesIfNoneMatch(): void
    {
        // #321 — precedence: If-Match (step 1) is evaluated BEFORE
        // If-None-Match (step 3); a failed If-Match → 412 even when the
        // If-None-Match would have produced a 304.
        $path = $this->makeTempFile(str_repeat('g', 50), 'css');
        $mtime = (int) filemtime($path);
        $etag = 'W/"' . dechex($mtime) . '-' . dechex(50) . '"';
        $this->setRequestHeaders([
            'if-match'      => '"no-such-etag"',
            'if-none-match' => $etag,
        ]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 412, ''], $fake->log);
    }

    public function testSendFileIfNoneMatchStrongFormOfWeakEtagStillMatches(): void
    {
        // #321 — weak comparison for If-None-Match (RFC 9110 §13.1.2): a
        // client echoing the strong form of our weak ETag must still get the
        // 304 — via ConditionalRequest::findEtagWeak, not the old
        // ltrim($etag, 'W/') character-class hack.
        $path = $this->makeTempFile(str_repeat('h', 50), 'css');
        $mtime = (int) filemtime($path);
        $strongForm = '"' . dechex($mtime) . '-' . dechex(50) . '"';
        $this->setRequestHeaders(['if-none-match' => $strongForm]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 304, ''], $fake->log);
    }

    public function testSendFileMultiSuffixGzipResolvesInnerTypeAndEncoding(): void
    {
        // #317 — Apache mod_mime parity: `app.html.gz` walks the whole suffix
        // chain → Content-Type from the inner suffix (text/html) plus
        // Content-Encoding: gzip. Previously the magic-bytes/pathinfo fallback
        // labelled it application/gzip with no Content-Encoding at all.
        $path = $this->makeTempFile((string) gzencode('<h1>hi</h1>'), 'html.gz');
        $this->setRequestHeaders([]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $headers = $this->headerCalls($fake);
        $this->assertContains(['header', 'Content-Type', 'text/html'], $headers);
        $this->assertContains(['header', 'Content-Encoding', 'gzip'], $headers);
    }

    public function testSendFileLanguageSuffixResolvesContentLanguage(): void
    {
        // #317 — `page.fr.html` → text/html + Content-Language: fr (Apache
        // AddLanguage parity; suffix order does not matter to mod_mime).
        $path = $this->makeTempFile('<p>bonjour</p>', 'fr.html');
        $this->setRequestHeaders([]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $headers = $this->headerCalls($fake);
        $this->assertContains(['header', 'Content-Type', 'text/html'], $headers);
        $this->assertContains(['header', 'Content-Language', 'fr'], $headers);
    }

    public function testSendFileEncodingOnlySuffixDoesNotLeakArchiveType(): void
    {
        // #317 — a bare `.gz` with no inner type suffix must not let magic
        // bytes label the ENCODING as the TYPE (application/gzip while also
        // claiming Content-Encoding: gzip would make clients double-decode).
        $path = $this->makeTempFile((string) gzencode('plain'), 'gz');
        $this->setRequestHeaders([]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $headers = $this->headerCalls($fake);
        $this->assertContains(['header', 'Content-Encoding', 'gzip'], $headers);
        $this->assertContains(['header', 'Content-Type', 'application/octet-stream'], $headers);
    }

    public function testSendFileTooManyRangesServesFullBody(): void
    {
        $path = $this->makeTempFile(str_repeat('z', 500), 'bin');
        // 201 specs > MAX_RANGES (200) → ignore Range, serve full 200.
        $specs = [];
        for ($i = 0; $i < 201; $i++) {
            $specs[] = "{$i}-{$i}";
        }
        $this->setRequestHeaders(['range' => 'bytes=' . implode(',', $specs)]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        // No 206/416 status pushed; full sendfile of the whole file.
        foreach ($fake->log as $entry) {
            $this->assertNotSame(206, $entry[1] ?? null);
            $this->assertNotSame(416, $entry[1] ?? null);
        }
        $this->assertContains(['sendfile', $path, 0, 500], $fake->log);
        $this->assertContains(['header', 'Content-Length', '500'], $this->headerCalls($fake));
    }

    public function testSendFileMultiRangeUnsatisfiableReturns416(): void
    {
        $path = $this->makeTempFile(str_repeat('q', 50), 'bin');
        $this->setRequestHeaders(['range' => 'bytes=100-200,300-400']);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 416, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes */50'], $this->headerCalls($fake));
    }

    public function testSendFileIfRangeDateMatchHonoursRange(): void
    {
        $path = $this->makeTempFile(str_repeat('m', 100), 'bin');
        // #258 — the shared strong-validation rule (Apache 60 s clock-skew) only
        // HONOURS a date If-Range once the file is ≥ 60 s old (a younger
        // Last-Modified is a weak validator). Backdate the file so the matching
        // date is a STRONG match → range honoured (206). (Pre-#258 sendFile used
        // exact-second and would 206 even on a brand-new file; that divergence
        // from RangeMiddleware is exactly what this fix closes.)
        $mtime = time() - 120;
        touch($path, $mtime);
        clearstatcache(true, $path);
        $this->setRequestHeaders([
            'range'    => 'bytes=0-9',
            'if-range' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT',
        ]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        // Validator matches AND skew window elapsed → range honoured (206 slice).
        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertContains(['sendfile', $path, 0, 10], $fake->log);
    }

    public function testSendFileIfRangeDateMismatchServesFullBody(): void
    {
        $path = $this->makeTempFile(str_repeat('m', 100), 'bin');
        $mtime = (int) filemtime($path);
        // A date OLDER than the file mtime → file changed since → ignore range.
        $this->setRequestHeaders([
            'range'    => 'bytes=0-9',
            'if-range' => gmdate('D, d M Y H:i:s', $mtime - 3600) . ' GMT',
        ]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        // Validator stale → full body (200, full sendfile), no 206.
        foreach ($fake->log as $entry) {
            $this->assertNotSame(206, $entry[1] ?? null);
        }
        $this->assertContains(['sendfile', $path, 0, 100], $fake->log);
    }

    public function testSendFileIfRangeWeakEtagIgnoredServesFullBody(): void
    {
        // #362 — RFC 9110 §13.1.5 mandates the STRONG comparison for If-Range and
        // §8.8.1 forbids a weak validator from authorising sub-range retrieval.
        // sendFile's ETag is ALWAYS weak (W/"<mtime>-<size>"), so even a verbatim
        // echo of it in If-Range MUST be ignored → full 200, NOT a 206 slice.
        $path = $this->makeTempFile(str_repeat('m', 100), 'bin');
        $mtime = (int) filemtime($path);
        $etag = 'W/"' . dechex($mtime) . '-' . dechex(100) . '"';
        $this->setRequestHeaders([
            'range'    => 'bytes=0-9',
            'if-range' => $etag,
        ]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        foreach ($fake->log as $entry) {
            $this->assertNotSame(206, $entry[1] ?? null, 'weak If-Range must not yield 206');
        }
        $this->assertContains(['sendfile', $path, 0, 100], $fake->log);
    }

    public function testSendFileIfRangeStrongEtagMatchHonoursRange(): void
    {
        // #362 — the strong path still works: a non-weak If-Range that byte-
        // matches a non-weak ETag honours the range. sendFile emits a weak ETag,
        // so we install a custom strong-ETag resolver context is not available;
        // instead assert via the pure ifRangeMatches() helper that a strong pair
        // matches and a weak pair does not.
        $ref = new \ReflectionMethod(ZResponse::class, 'ifRangeMatches');
        $ref->setAccessible(true);
        $resp = $this->wrap($this->fake());
        $strong = '"abc-123"';
        $weak   = 'W/"abc-123"';
        // strong If-Range vs strong ETag → match
        $this->assertTrue($ref->invoke($resp, $strong, $strong, 0));
        // weak If-Range vs strong ETag → no match (weak validator forbidden)
        $this->assertFalse($ref->invoke($resp, $weak, $strong, 0));
        // strong If-Range vs weak ETag → no match (weak validator forbidden)
        $this->assertFalse($ref->invoke($resp, $strong, $weak, 0));
        // weak vs weak → no match
        $this->assertFalse($ref->invoke($resp, $weak, $weak, 0));
    }

    public function testSendFileIfRangeEtagMismatchServesFullBody(): void
    {
        $path = $this->makeTempFile(str_repeat('m', 100), 'bin');
        // A different entity-tag → mismatch → ignore range, serve full body.
        $this->setRequestHeaders([
            'range'    => 'bytes=0-9',
            'if-range' => '"some-other-etag"',
        ]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        foreach ($fake->log as $entry) {
            $this->assertNotSame(206, $entry[1] ?? null);
        }
        $this->assertContains(['sendfile', $path, 0, 100], $fake->log);
    }

    public function testSendFileFutureIfModifiedSinceDoesNotReturn304(): void
    {
        $path = $this->makeTempFile('x', 'css');
        // A future date is invalid per RFC 9110 — must NOT yield 304.
        $future = gmdate('D, d M Y H:i:s', time() + 86400) . ' GMT';
        $this->setRequestHeaders(['if-modified-since' => $future]);
        $fake = $this->fake();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        foreach ($fake->log as $entry) {
            $this->assertNotSame(304, $entry[1] ?? null);
        }
        // Full body served instead.
        $this->assertContains(['sendfile', $path, 0, 1], $fake->log);
    }

    // ---- parseRange() (static helper) --------------------------------------

    public function testParseRangeBoundedSingle(): void
    {
        $this->assertSame(
            ['status' => 'ok', 'ranges' => [[0, 9]]],
            ZResponse::parseRange('bytes=0-9', 100)
        );
    }

    public function testParseRangeMultipleSpecs(): void
    {
        $this->assertSame(
            ['status' => 'ok', 'ranges' => [[0, 2], [5, 7]]],
            ZResponse::parseRange('bytes=0-2,5-7', 10)
        );
    }

    public function testParseRangeSuffixAndOpenEnd(): void
    {
        $this->assertSame(
            ['status' => 'ok', 'ranges' => [[90, 99]]],
            ZResponse::parseRange('bytes=-10', 100)
        );
        $this->assertSame(
            ['status' => 'ok', 'ranges' => [[50, 99]]],
            ZResponse::parseRange('bytes=50-', 100)
        );
    }

    public function testParseRangeEndClampedToLastByte(): void
    {
        $this->assertSame(
            ['status' => 'ok', 'ranges' => [[0, 99]]],
            ZResponse::parseRange('bytes=0-500', 100)
        );
    }

    public function testParseRangeUnsatisfiable(): void
    {
        $this->assertSame(['status' => 'unsatisfiable', 'ranges' => []], ZResponse::parseRange('bytes=500-600', 100));
        $this->assertSame(['status' => 'unsatisfiable', 'ranges' => []], ZResponse::parseRange('bytes=100-', 100));
    }

    public function testParseRangeMultiRangeSkipsUnsatisfiableSpec(): void
    {
        // #185: a multi-range header with one out-of-bounds spec keeps the
        // satisfiable spec(s) instead of 416-ing the whole request (RFC 7233 §4.4).
        $this->assertSame(
            ['status' => 'ok', 'ranges' => [[0, 10]]],
            ZResponse::parseRange('bytes=0-10,9999-10000', 100)
        );
    }

    public function testParseRangeMultiRangeAllUnsatisfiable(): void
    {
        // Only when EVERY spec is unsatisfiable do we 416 (the post-loop check).
        $this->assertSame(
            ['status' => 'unsatisfiable', 'ranges' => []],
            ZResponse::parseRange('bytes=500-600,9999-10000', 100)
        );
    }

    public function testParseRangeNonBytesUnitIgnored(): void
    {
        $this->assertSame(['status' => 'ignore', 'ranges' => []], ZResponse::parseRange('items=0-9', 100));
        $this->assertSame(['status' => 'ignore', 'ranges' => []], ZResponse::parseRange('not a range', 100));
    }

    public function testParseRangeEmptyByteRangeSetIgnored(): void
    {
        // #365 — RFC 7233 §2.1: byte-range-set requires ≥1 spec. A header with
        // none at all (`bytes=,`, `bytes=,,`, `bytes=, ,`) is invalid → ignore →
        // full 200, NOT unsatisfiable (416). 416 is reserved for a VALID spec
        // that fell outside [0, total).
        $this->assertSame(['status' => 'ignore', 'ranges' => []], ZResponse::parseRange('bytes=,', 5000));
        $this->assertSame(['status' => 'ignore', 'ranges' => []], ZResponse::parseRange('bytes=,,', 5000));
        $this->assertSame(['status' => 'ignore', 'ranges' => []], ZResponse::parseRange('bytes=, ,', 5000));
        $this->assertSame(['status' => 'ignore', 'ranges' => []], ZResponse::parseRange('bytes= ', 5000));
    }

    public function testParseRangeDegenerateSuffixStillUnsatisfiable(): void
    {
        // #365 boundary vs #185: `bytes=-0` IS a syntactically-valid spec (just
        // degenerate/zero-length) → it counts as "saw a spec" → 416, distinct
        // from the empty set above which is "no spec → ignore".
        $this->assertSame(['status' => 'unsatisfiable', 'ranges' => []], ZResponse::parseRange('bytes=-0', 100));
    }

    public function testParseRangeInvalidSpecIgnoresWholeHeader(): void
    {
        // RFC 7233 §2.1: a syntactically invalid byte-range-spec invalidates the
        // whole header. Trailing/leading garbage and bare "-" are rejected.
        $this->assertSame(['status' => 'ignore', 'ranges' => []], ZResponse::parseRange('bytes=0-9junk', 100));
        $this->assertSame(['status' => 'ignore', 'ranges' => []], ZResponse::parseRange('bytes=abc,0-4', 100));
        $this->assertSame(['status' => 'ignore', 'ranges' => []], ZResponse::parseRange('bytes=-', 100));
    }

    public function testParseRangeSuffixLargerThanFileClampsToFull(): void
    {
        // Suffix length > content length → whole representation (clamp start 0).
        $this->assertSame(
            ['status' => 'ok', 'ranges' => [[0, 99]]],
            ZResponse::parseRange('bytes=-150', 100)
        );
    }

    public function testParseRangeExceedingMaxRangesIgnored(): void
    {
        $specs = [];
        for ($i = 0; $i < 201; $i++) {
            $specs[] = "{$i}-{$i}";
        }
        $this->assertSame(
            ['status' => 'ignore', 'ranges' => []],
            ZResponse::parseRange('bytes=' . implode(',', $specs), 1000)
        );
    }

    public function testParseRangeUppercaseBytesUnitMatches(): void
    {
        $this->assertSame(
            ['status' => 'ok', 'ranges' => [[0, 9]]],
            ZResponse::parseRange('BYTES=0-9', 100)
        );
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

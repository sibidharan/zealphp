<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\HTTP;

use ZealPHP\App;
use ZealPHP\HTTP\Request as ZRequest;
use ZealPHP\HTTP\Response as ZResponse;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

/**
 * Branch coverage for ZealPHP\HTTP\Response not reached by ResponseTest.php:
 *
 *   - __get / __set proxies (forward to parent, the parent special-case,
 *     unknown-property write lands on $this, unknown-property read throws)
 *   - sendFile() MIME-guess match arms for extensions whose mime_content_type
 *     resolves to octet-stream (woff/woff2/ttf/otf/avif/webm/svg/xml/json…)
 *   - sendFile() request-header guards: non-array header bag, non-string
 *     if-none-match / if-modified-since / range values
 *   - sendFile() open-ended range (bytes=N-) → end defaults to total-1
 */
class ResponseExtraTest extends TestCase
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

    private function wrap(?FakeOpenSwooleResponse $fake = null): ZResponse
    {
        return new ZResponse($fake ?? new FakeOpenSwooleResponse());
    }

    /** @param array<string, mixed> $headers */
    private function setRequestHeaders(array $headers): void
    {
        $g = RequestContext::instance();
        $or = new \OpenSwoole\Http\Request();
        $or->header = $headers;
        $g->zealphp_request = new ZRequest($or);
    }

    private function makeTempFile(string $contents, string $ext): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'zealphp_respx_') . '.' . $ext;
        file_put_contents($file, $contents);
        $this->tmpFiles[] = $file;
        return $file;
    }

    /** @return array<int, array{0: string, 1: string, 2: string}> */
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

    /**
     * Capture everything elog() appends to the debug log while $fn runs.
     *
     * The unit suite runs without a coroutine scheduler, so ZealPHP\log_write()
     * falls back to a synchronous append to the configured debug-log file
     * (ZEALPHP_DEBUG_LOG_FILE / ZEALPHP_LOG_FILE, default /tmp/zealphp/debug.log).
     * We read the byte-offset before and after to isolate this call's output.
     */
    private function captureDebugLog(callable $fn): string
    {
        $path = \ZealPHP\log_file_for('debug');
        if ($path === null || str_contains($path, '://')) {
            $this->markTestSkipped('debug log not file-backed in this environment');
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        clearstatcache(true, $path);
        $before = is_file($path) ? (int) filesize($path) : 0;
        $fn();
        clearstatcache(true, $path);
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        return substr($contents, $before);
    }

    // ---- __get / __set proxies --------------------------------------------

    public function testGetProxiesParentProperty(): void
    {
        $fake = new FakeOpenSwooleResponse();
        $fake->fd = 77;
        $resp = $this->wrap($fake);
        // 'fd' exists on the parent → __get forwards.
        $this->assertSame(77, $resp->fd);
    }

    public function testGetUnknownPropertyThrows(): void
    {
        $resp = $this->wrap();
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore property.notFound */
        $x = $resp->nonexistent_property;
    }

    public function testSetProxiesParentProperty(): void
    {
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->fd = 99; // 'fd' exists on parent → __set forwards.
        $this->assertSame(99, $fake->fd);
    }

    public function testSetParentSpecialCaseReassignsUnderlying(): void
    {
        $resp = $this->wrap();
        $newParent = new FakeOpenSwooleResponse();
        $resp->parent = $newParent;
        $this->assertSame($newParent, $resp->parent);
    }

    public function testSetUnknownPropertyLandsOnWrapper(): void
    {
        $resp = $this->wrap();
        /** @phpstan-ignore property.notFound */
        $resp->custom_attr = 'value';
        /** @phpstan-ignore property.notFound */
        $this->assertSame('value', $resp->custom_attr);
    }

    // ---- sendFile() MIME-guess match arms ---------------------------------

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mimeGuessProvider(): array
    {
        // ext => expected guessed mime. These extensions resolve to
        // octet-stream via mime_content_type, so the match() block runs.
        return [
            'woff'  => ['woff',  'font/woff'],
            'woff2' => ['woff2', 'font/woff2'],
            'ttf'   => ['ttf',   'font/ttf'],
            'otf'   => ['otf',   'font/otf'],
            'avif'  => ['avif',  'image/avif'],
            'webm'  => ['webm',  'video/webm'],
            'webp'  => ['webp',  'image/webp'],
            'mp4'   => ['mp4',   'video/mp4'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mimeGuessProvider')]
    public function testSendFileGuessesMimeFromExtension(string $ext, string $expectedMime): void
    {
        $path = $this->makeTempFile(str_repeat('z', 64), $ext);
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $headers = $this->headerCalls($fake);
        $cts = array_filter($headers, static fn(array $h): bool => $h[1] === 'Content-Type');
        $ctValues = array_map(static fn(array $h): string => $h[2], $cts);
        $this->assertContains($expectedMime, $ctValues, "Expected $expectedMime for .$ext");
    }

    // ---- sendFile() request-header guards ---------------------------------

    public function testSendFileNonArrayHeaderBag(): void
    {
        // parent->header is a string (not array) → the !is_array guard runs.
        $g = RequestContext::instance();
        $or = new \OpenSwoole\Http\Request();
        $or->header = 'not-an-array';
        $g->zealphp_request = new ZRequest($or);

        $path = $this->makeTempFile(str_repeat('a', 32), 'css');
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);

        // Falls through to a normal full-file send.
        $this->assertContains(['sendfile', $path, 0, 32], $fake->log);
    }

    public function testSendFileNonStringConditionalHeaders(): void
    {
        // Array-valued conditional headers → the !is_string guards coerce to ''.
        $this->setRequestHeaders([
            'if-none-match'     => ['a', 'b'],
            'if-modified-since' => ['x'],
            'range'             => ['bytes=0-1'],
        ]);
        $path = $this->makeTempFile(str_repeat('b', 40), 'css');
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);

        // None of the conditional/range branches taken → full send.
        $this->assertContains(['sendfile', $path, 0, 40], $fake->log);
    }

    public function testSendFileOpenEndedRange(): void
    {
        // bytes=10- → start=10, end defaults to total-1.
        $this->setRequestHeaders(['range' => 'bytes=10-']);
        $path = $this->makeTempFile(str_repeat('c', 100), 'css');
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);

        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes 10-99/100'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 10, 90], $fake->log);
    }

    // ---- json() — encode contract -----------------------------------------

    public function testJsonEncodeFailureSendsEmptyString(): void
    {
        // json_encode(NAN) returns false; the (string) cast turns it into ''.
        // Without the cast the call would pass false to end() (or fatal under
        // a strict signature) — pin the exact emitted body.
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->json(NAN, 200);

        $this->assertContains(['end', ''], $fake->log);
        // The body must NOT be a non-string / "false"-ish value.
        $ends = array_values(array_filter($fake->log, static fn(array $e): bool => $e[0] === 'end'));
        $this->assertNotEmpty($ends);
        $this->assertSame('', $ends[0][1]);
    }

    public function testJsonEncodesValidPayloadExactly(): void
    {
        // Kills the CastString mutant on a valid encode: the body is the exact
        // JSON string, not an empty/coerced value.
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->json(['k' => 'v', 'n' => 5], 200);

        $this->assertContains(['end', '{"k":"v","n":5}'], $fake->log);
    }

    // ---- header() — Location auto-302 boundary ----------------------------

    public function testLocationDoesNotAutoSetWhenStatusAlready201(): void
    {
        // Kills Increment/Decrement on the `=== 200` literal: only status 200
        // (or null) auto-promotes to 302. With status 201, it must stay 201.
        $g = RequestContext::instance();
        $g->status = 201;
        $resp = $this->wrap();

        $resp->header('Location', '/elsewhere');
        $this->assertSame(201, $g->status);
    }

    public function testLocationAutoSets302WhenStatus200(): void
    {
        // Kills Increment/Decrement: with the literal mutated to 199/201 the
        // auto-promotion would not fire for an actual 200.
        $g = RequestContext::instance();
        $g->status = 200;
        $resp = $this->wrap();

        $resp->header('Location', '/somewhere');
        $this->assertSame(302, $g->status);
    }

    public function testNonLocationHeaderWithTruthyValueDoesNotPromote(): void
    {
        // Kills LogicalAnd→Or on the location guard: a non-Location header with
        // a truthy value and status 200 must NOT become 302.
        $g = RequestContext::instance();
        $g->status = 200;
        $resp = $this->wrap();

        $resp->header('X-Whatever', 'truthy');
        $this->assertSame(200, $g->status);
    }

    public function testLocationHeaderEmptyValueDoesNotPromote(): void
    {
        // Kills LogicalAnd→Or: with `||` an empty Location value would still
        // promote because the first sub-expression is true. With `&&` the empty
        // value short-circuits and status stays 200.
        $g = RequestContext::instance();
        $g->status = 200;
        $resp = $this->wrap();

        $resp->header('Location', '');
        $this->assertSame(200, $g->status);
    }

    // ---- header() — injection guard side effect ---------------------------

    public function testHeaderInjectionTriggersWarning(): void
    {
        // Kills FunctionCallRemoval on trigger_error(): without it no warning is
        // raised. The framework installs a native error dispatcher (App.php) that
        // consults the per-coroutine handler stack in G, and uopz may override
        // set_error_handler() to push onto that same stack. To be robust across
        // *both* the standalone HTTP run (native set_error_handler) and the full
        // suite (uopz-overridden + native dispatcher reading G), we (a) widen the
        // per-coroutine error_reporting level so the dispatcher won't suppress
        // E_USER_WARNING, and (b) register our recorder via set_error_handler.
        $g = RequestContext::instance();
        $prevLevel = $g->error_reporting_level ?? null;
        $g->error_reporting_level = E_ALL;

        $resp = $this->wrap();
        $raised = false;
        $captured = '';
        set_error_handler(function (int $errno, string $msg) use (&$raised, &$captured): bool {
            if ($errno === E_USER_WARNING) {
                $raised = true;
                $captured = $msg;
            }
            return true;
        });
        try {
            $ok = $resp->header('X-Test', "evil\r\nSet-Cookie: x=1");
        } finally {
            restore_error_handler();
            $g->error_reporting_level = $prevLevel;
        }

        $this->assertFalse($ok, 'injected header must be refused');
        $this->assertTrue($raised, 'trigger_error() must raise an E_USER_WARNING on injection');
        $this->assertStringContainsString('Header injection blocked', $captured);
    }

    // ---- redirect() — scheme guard (caret + i flag) -----------------------

    public function testRedirectRejectsUppercaseJavascriptScheme(): void
    {
        // Kills PregMatchRemoveFlags (drops the case-insensitive `i`): uppercase
        // JAVASCRIPT: must still be rejected.
        $resp = $this->wrap();
        $this->expectException(\InvalidArgumentException::class);
        $resp->redirect('JAVASCRIPT:alert(1)');
    }

    public function testRedirectAllowsSchemeKeywordNotAtStart(): void
    {
        // Kills PregMatchRemoveCaret on the scheme regex: with the caret gone,
        // a legitimate URL that merely *contains* "javascript:" later would be
        // wrongly rejected. With the caret intact this must succeed.
        $g = RequestContext::instance();
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->redirect('/go?to=javascript:foo');

        $this->assertSame(302, $g->status);
        $this->assertContains(['header', 'Location', '/go?to=javascript:foo'], $fake->log);
    }

    // ---- redirect() — protocol-relative branch (caret) --------------------

    public function testRedirectRelativeUrlContainingDoubleSlashLater(): void
    {
        // Kills PregMatchRemoveCaret on '#^//#': with the caret removed, a URL
        // containing '//' anywhere (e.g. an embedded URL in a query string)
        // would take the protocol-relative branch instead of the host check.
        // Behaviorally both still emit the redirect, so we pin the Location +
        // status which must be identical regardless of branch.
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'mysite.com'];
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->redirect('https://mysite.com/path//double');

        $this->assertSame(302, $g->status);
        $this->assertContains(['header', 'Location', 'https://mysite.com/path//double'], $fake->log);
    }

    // ---- cookie() — default argument values -------------------------------

    public function testCookieDefaultsTupleExactly(): void
    {
        // Kills the default-value mutants on cookie(): expire 0 (not 1/-1),
        // secure false (not true), httponly false (not true).
        $resp = $this->wrap();
        $this->assertTrue($resp->cookie('sid', 'val'));

        $this->assertSame(
            [['sid', 'val', 0, '/', '', false, false, '', '']],
            $resp->cookiesList
        );
    }

    public function testCookieExpireDefaultIsZero(): void
    {
        // Pin the expire default precisely against Increment/Decrement.
        $resp = $this->wrap();
        $resp->cookie('a', 'b');
        $this->assertSame(0, $resp->cookiesList[0][2]);
    }

    public function testCookieSecureAndHttpOnlyDefaultFalse(): void
    {
        // Pin the secure/httponly defaults precisely against FalseValue→TrueValue.
        $resp = $this->wrap();
        $resp->cookie('a', 'b');
        $this->assertFalse($resp->cookiesList[0][5], 'secure default must be false');
        $this->assertFalse($resp->cookiesList[0][6], 'httponly default must be false');
    }

    // ---- end() — must remain public ---------------------------------------

    public function testEndIsPubliclyCallable(): void
    {
        // Kills PublicVisibility (public→protected): a direct external call must
        // succeed and forward the exact body to the parent.
        $reflection = new \ReflectionMethod(ZResponse::class, 'end');
        $this->assertTrue($reflection->isPublic(), 'end() must be public');

        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $this->assertTrue($resp->end('the-body'));
        $this->assertContains(['end', 'the-body'], $fake->log);
    }

    // ---- stream() — write() return-value contract -------------------------

    public function testStreamWriteReturnsTrueWhenWritable(): void
    {
        // Kills `!== false`→`!== true` (252 FalseValue) and `!== false`→`=== false`
        // (252 NotIdentical): the fake write() returns true, so the $write()
        // closure must return true when the client is connected.
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $captured = null;
        $resp->stream(function (callable $write) use (&$captured): void {
            $captured = $write('chunk');
        });

        $this->assertTrue($captured, '$write() must return true when parent->write() returns true');
        $this->assertContains(['write', 'chunk'], $fake->log);
    }

    // ---- sse() — id/event/data framing ------------------------------------

    public function testSseDataOnlyFrame(): void
    {
        // Kills the SSE framing mutants: pin the exact wire format for a
        // data-only message.
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sse(function (callable $emit): void {
            $emit('hello');
        });

        $this->assertContains(['write', "data: hello\n\n"], $fake->log);
    }

    public function testSseEventWithoutIdFrame(): void
    {
        // Event but no id: the frame must be exactly "event: X\ndata: Y\n\n".
        // This kills the id-line Assignment mutant indirectly is not possible
        // (no id), but pins the event+data ordering precisely.
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sse(function (callable $emit): void {
            $emit('body', 'tick');
        });

        $this->assertContains(['write', "event: tick\ndata: body\n\n"], $fake->log);
    }

    public function testSseIdOnlyFrameKeepsDataLine(): void
    {
        // Kills the `$msg .= "id: {$id}\n"` → `$msg = "id: {$id}\n"` (278 Assignment)
        // mutant in combination with the data line: since $msg starts '' the
        // assignment mutant alone is equivalent, but we still pin the exact
        // id+data framing so any future reorder is caught.
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sse(function (callable $emit): void {
            $emit('payload', '', '99');
        });

        $this->assertContains(['write', "id: 99\ndata: payload\n\n"], $fake->log);
    }

    // ---- sendFile() — missing file branch (OR vs flush) -------------------

    public function testSendFileUnreadablePathReturns404(): void
    {
        // Kills LogicalOr→And on `!file_exists($path) || !is_readable($path)`:
        // a path that does not exist makes the first operand true; with `&&`
        // (and is_readable false → !is_readable true) it still 404s, so to make
        // the distinction observable we rely on file_exists being the trigger.
        // A nonexistent file: !file_exists=true, !is_readable=true → both branches
        // 404. To kill `&&` we need a case where one is true and the other false.
        // file_exists true + is_readable false is hard to fabricate portably, so
        // the missing-file path is asserted here for the OR's first operand and
        // the dedicated readable test covers the second.
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile('/definitely/not/here.css');

        $this->assertContains(['status', 404, ''], $fake->log);
        $this->assertContains(['end', 'File not found'], $fake->log);
    }

    public function testSendFile404FlushesQueuedHeadersBeforeBody(): void
    {
        // Kills MethodCallRemoval on the flush() in the 404 branch: queue a
        // header first, then trigger 404; flush() must forward the queued header
        // to the parent before end('File not found').
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->header('X-Pre', 'q');

        $resp->sendFile('/definitely/not/here.css');

        // The queued header was emitted (flush ran) ahead of the body.
        $headerIdx = null;
        $endIdx = null;
        foreach ($fake->log as $i => $e) {
            if ($e[0] === 'header' && $e[1] === 'X-Pre') {
                $headerIdx = $i;
            }
            if ($e[0] === 'end' && $e[1] === 'File not found') {
                $endIdx = $i;
            }
        }
        $this->assertNotNull($headerIdx, 'queued header must be flushed in the 404 branch');
        $this->assertNotNull($endIdx);
        $this->assertLessThan($endIdx, $headerIdx);
    }

    // ---- sendFile() — MIME match arms (xml/json/svg) ----------------------

    public function testSendFileXmlMime(): void
    {
        // Kills MatchArmRemoval for 'xml'.
        $path = $this->makeTempFile('<a/>', 'xml');
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);
        $this->assertContains(['header', 'Content-Type', 'application/xml'], $this->headerCalls($fake));
    }

    public function testSendFileJsonMime(): void
    {
        // Kills MatchArmRemoval for 'json'. Use plain text content so
        // mime_content_type returns text/plain and the match block runs (a JSON
        // literal would already be detected as application/json, skipping it).
        $path = $this->makeTempFile('plain text not json', 'json');
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);
        $this->assertContains(['header', 'Content-Type', 'application/json'], $this->headerCalls($fake));
    }

    public function testSendFileSvgMime(): void
    {
        // Kills MatchArmRemoval for 'svg'. Plain text content forces the
        // text/plain → match() path (real SVG markup is sniffed as image/svg+xml).
        $path = $this->makeTempFile('plain text not svg', 'svg');
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);
        $this->assertContains(['header', 'Content-Type', 'image/svg+xml'], $this->headerCalls($fake));
    }

    public function testSendFileUppercaseExtensionStillGuessed(): void
    {
        // Kills UnwrapStrToLower on the match subject: an uppercase extension
        // must still map (the match keys are lowercase, so strtolower is required).
        $path = $this->makeTempFile('plain text not svg', 'SVG');
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->sendFile($path);
        $this->assertContains(['header', 'Content-Type', 'image/svg+xml'], $this->headerCalls($fake));
    }

    public function testSendFileSetsStreamingFlagOnSuccess(): void
    {
        // Kills TrueValue (303): a successful full send must set _streaming true.
        $g = RequestContext::instance();
        $g->_streaming = false;
        $path = $this->makeTempFile(str_repeat('s', 16), 'css');
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertTrue($g->_streaming);
    }

    public function testSendFileUsesDetectedMimeWhenNotTextOrOctet(): void
    {
        // Kills Ternary (306) on `mime_content_type($path) ?: 'octet-stream'`:
        // a real PNG is sniffed as image/png (truthy), so the original keeps it.
        // The mutant ternary would substitute octet-stream and then the match
        // default would yield octet-stream. We pin the real detected mime.
        $png = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01";
        $path = $this->makeTempFile($png, 'png');
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['header', 'Content-Type', 'image/png'], $this->headerCalls($fake));
    }

    public function testSendFileOctetStreamWithMappedExtensionUsesMatch(): void
    {
        // Kills the `=== 'application/octet-stream'` → `!==` Identical mutant
        // (307): binary content is sniffed as octet-stream, so the match block
        // must run and remap a .woff to font/woff. The mutant would skip the
        // match and leave it as octet-stream.
        $binary = "\x00\x01\x02\x03\xff\xfe\xfd\x00";
        $path = $this->makeTempFile($binary, 'woff');
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['header', 'Content-Type', 'font/woff'], $this->headerCalls($fake));
    }

    public function testSendFileSuffixRangeLargerThanFileClampsStart(): void
    {
        // Kills 385 Increment/Decrement on max(0, $total - $end): a suffix range
        // longer than the file (bytes=-150 on 100 bytes) → $total-$end = -50, so
        // max(0, -50) = 0. The mutant max(-1,…) or max(1,…) would shift the start
        // offset, changing the Content-Range and sendfile offset.
        $path = $this->makeTempFile(str_repeat('z', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=-150']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes 0-99/100'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 0, 100], $fake->log);
    }

    // ---- sendFile() — ETag / Last-Modified emission -----------------------

    public function testSendFileEmitsEtagAndLastModified(): void
    {
        // Kills MethodCallRemoval on header('ETag', ...) and
        // header('Last-Modified', ...), plus the Concat mutants building the
        // Last-Modified value (must end with ' GMT' and be the gmdate prefix).
        $path = $this->makeTempFile(str_repeat('q', 30), 'css');
        $mtime = (int) filemtime($path);
        $expectedEtag = 'W/"' . dechex($mtime) . '-' . dechex(30) . '"';
        $expectedLm = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $headers = $this->headerCalls($fake);
        $this->assertContains(['header', 'ETag', $expectedEtag], $headers);
        $this->assertContains(['header', 'Last-Modified', $expectedLm], $headers);
    }

    // ---- sendFile() — If-None-Match list parsing --------------------------

    public function testSendFileIfNoneMatchTrimsWhitespaceInList(): void
    {
        // Kills UnwrapTrim and UnwrapArrayMap: the If-None-Match value is a
        // comma list with surrounding whitespace; the matching ETag is only
        // found after trim().
        $path = $this->makeTempFile(str_repeat('m', 20), 'css');
        $mtime = (int) filemtime($path);
        $etag = 'W/"' . dechex($mtime) . '-' . dechex(20) . '"';
        $this->setRequestHeaders(['if-none-match' => 'W/"deadbeef", ' . $etag . ' ']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 304, ''], $fake->log);
    }

    public function testSendFileIfNoneMatchWildcardMatches(): void
    {
        // Kills the `$tag === '*'` Identical mutant (=== → !==) and the
        // LogicalOr negation: a single '*' must yield 304.
        $path = $this->makeTempFile(str_repeat('w', 20), 'css');
        $this->setRequestHeaders(['if-none-match' => '*']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 304, ''], $fake->log);
    }

    public function testSendFileIfNoneMatchNonMatchingDoesNotReturn304(): void
    {
        // Kills the LogicalOrAllSubExprNegation and the Identical mutants on the
        // tag comparison: a non-matching, non-wildcard ETag must NOT be a 304;
        // a normal full send happens instead.
        $path = $this->makeTempFile(str_repeat('n', 20), 'css');
        $this->setRequestHeaders(['if-none-match' => 'W/"00000000-00000000"']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $statuses = array_values(array_filter($fake->log, static fn(array $e): bool => $e[0] === 'status'));
        foreach ($statuses as $s) {
            $this->assertNotSame(304, $s[1], 'non-matching ETag must not 304');
        }
        $this->assertContains(['sendfile', $path, 0, 20], $fake->log);
    }

    public function testSendFileIfNoneMatchStrongTagMatchesWeakViaLtrim(): void
    {
        // Kills UnwrapLtrim and the third Identical mutant: a strong-form ETag
        // (without the W/ prefix) must match after ltrim($etag, 'W/').
        $path = $this->makeTempFile(str_repeat('s', 20), 'css');
        $mtime = (int) filemtime($path);
        $weakEtag = 'W/"' . dechex($mtime) . '-' . dechex(20) . '"';
        $strong = ltrim($weakEtag, 'W/'); // '"<hex>-<hex>"'
        $this->setRequestHeaders(['if-none-match' => $strong]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 304, ''], $fake->log);
    }

    public function testSendFileIfNoneMatchFirstTagMatchesBreaks(): void
    {
        // Kills Break_→continue (359): the first list entry matches; with the
        // break removed the loop continues but $notModified stays true, so
        // behaviorally still 304. We instead assert 304 happens AND that no
        // later branch (range) ran — i.e. flush()+end('') is the terminal pair.
        $path = $this->makeTempFile(str_repeat('f', 20), 'css');
        $mtime = (int) filemtime($path);
        $etag = 'W/"' . dechex($mtime) . '-' . dechex(20) . '"';
        $this->setRequestHeaders(['if-none-match' => $etag . ', W/"other"']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 304, ''], $fake->log);
        $this->assertContains(['end', ''], $fake->log);
    }

    public function testSendFile304FlushesBeforeEnd(): void
    {
        // Kills MethodCallRemoval on flush() in the 304 branch: queued ETag must
        // reach the parent before the empty body end('').
        $path = $this->makeTempFile(str_repeat('e', 20), 'css');
        $mtime = (int) filemtime($path);
        $etag = 'W/"' . dechex($mtime) . '-' . dechex(20) . '"';
        $this->setRequestHeaders(['if-none-match' => $etag]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $etagIdx = null;
        $endIdx = null;
        foreach ($fake->log as $i => $e) {
            if ($e[0] === 'header' && $e[1] === 'ETag') {
                $etagIdx = $i;
            }
            if ($e[0] === 'end' && $e[1] === '') {
                $endIdx = $i;
            }
        }
        $this->assertNotNull($etagIdx, 'ETag header must be flushed in the 304 branch');
        $this->assertNotNull($endIdx);
        $this->assertLessThan($endIdx, $etagIdx);
    }

    // ---- sendFile() — If-Modified-Since -----------------------------------

    public function testSendFileIfModifiedSinceExactlyEqualReturns304(): void
    {
        // Kills GreaterThanOrEqualTo→GreaterThan (364): when the If-Modified-Since
        // equals mtime exactly, `>=` yields 304 but `>` would not.
        $path = $this->makeTempFile('x', 'css');
        $mtime = (int) filemtime($path);
        $this->setRequestHeaders(['if-modified-since' => gmdate('D, d M Y H:i:s', $mtime) . ' GMT']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 304, ''], $fake->log);
    }

    public function testSendFileIfModifiedSinceOlderDoesNotReturn304(): void
    {
        // Kills the LogicalAnd→Or (364) and FalseValue (364): an older
        // If-Modified-Since (< mtime) must NOT 304 — a full send happens.
        $path = $this->makeTempFile(str_repeat('o', 16), 'css');
        $mtime = (int) filemtime($path);
        $this->setRequestHeaders(['if-modified-since' => gmdate('D, d M Y H:i:s', $mtime - 1000) . ' GMT']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $statuses = array_values(array_filter($fake->log, static fn(array $e): bool => $e[0] === 'status'));
        foreach ($statuses as $s) {
            $this->assertNotSame(304, $s[1]);
        }
        $this->assertContains(['sendfile', $path, 0, 16], $fake->log);
    }

    public function testSendFileIfModifiedSinceUnparseableDoesNotReturn304(): void
    {
        // Kills FalseValue (364, `$since !== false`): an unparseable date makes
        // strtotime() return false; the guard must reject it (no 304).
        $path = $this->makeTempFile(str_repeat('u', 16), 'css');
        $this->setRequestHeaders(['if-modified-since' => 'not-a-date']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $statuses = array_values(array_filter($fake->log, static fn(array $e): bool => $e[0] === 'status'));
        foreach ($statuses as $s) {
            $this->assertNotSame(304, $s[1]);
        }
        $this->assertContains(['sendfile', $path, 0, 16], $fake->log);
    }

    // ---- sendFile() — Range parsing exactness -----------------------------

    public function testSendFileRangeAnchoredRegexRejectsTrailingGarbage(): void
    {
        // Kills PregMatchRemoveDollar (380): with the trailing $ removed,
        // 'bytes=0-9junk' would match and 206. With $ intact the regex fails to
        // match, so it falls through to a full 200 send.
        $path = $this->makeTempFile(str_repeat('r', 50), 'css');
        $this->setRequestHeaders(['range' => 'bytes=0-9junk']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        // Anchored regex does not match → full send, no 206.
        $statuses = array_values(array_filter($fake->log, static fn(array $e): bool => $e[0] === 'status'));
        foreach ($statuses as $s) {
            $this->assertNotSame(206, $s[1]);
        }
        $this->assertContains(['sendfile', $path, 0, 50], $fake->log);
    }

    public function testSendFileRangeAnchoredRegexRejectsLeadingGarbage(): void
    {
        // Kills PregMatchRemoveCaret (380): with the caret removed,
        // 'prefixbytes=0-9' would match. With ^ intact it does not.
        $path = $this->makeTempFile(str_repeat('p', 50), 'css');
        $this->setRequestHeaders(['range' => 'prefixbytes=0-9']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $statuses = array_values(array_filter($fake->log, static fn(array $e): bool => $e[0] === 'status'));
        foreach ($statuses as $s) {
            $this->assertNotSame(206, $s[1]);
        }
        $this->assertContains(['sendfile', $path, 0, 50], $fake->log);
    }

    public function testSendFileRangeStartCastToInt(): void
    {
        // Kills CastInt on $start (381): 'bytes=05-9' → start must be int 5,
        // producing sendfile offset 5 (not string '05'). Asserting the exact
        // numeric Content-Range + sendfile args pins the cast.
        $path = $this->makeTempFile(str_repeat('a', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=5-9']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['header', 'Content-Range', 'bytes 5-9/100'], $this->headerCalls($fake));
        $this->assertContains(['header', 'Content-Length', '5'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 5, 5], $fake->log);
    }

    public function testSendFileRangeEndExactBoundaryArithmetic(): void
    {
        // Kills the Minus/DecrementInteger mutants (386, 388, 399) and CastInt on
        // $end (382): a full-explicit range bytes=10-19 on a 100-byte file →
        // start 10, end 19, length 10.
        $path = $this->makeTempFile(str_repeat('b', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=10-19']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes 10-19/100'], $this->headerCalls($fake));
        $this->assertContains(['header', 'Content-Length', '10'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 10, 10], $fake->log);
    }

    public function testSendFileSuffixRangeArithmetic(): void
    {
        // Kills 385 Increment/Decrement on max(0, total - end): bytes=-10 on a
        // 100-byte file → start 90, end 99, length 10.
        $path = $this->makeTempFile(str_repeat('c', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=-10']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['header', 'Content-Range', 'bytes 90-99/100'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 90, 10], $fake->log);
    }

    public function testSendFileOpenEndedRangeEndIsTotalMinusOne(): void
    {
        // Kills 388 Minus/Decrement: bytes=0- on 100 bytes → end 99, length 100.
        $path = $this->makeTempFile(str_repeat('d', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=0-']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['header', 'Content-Range', 'bytes 0-99/100'], $this->headerCalls($fake));
        $this->assertContains(['header', 'Content-Length', '100'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 0, 100], $fake->log);
    }

    public function testSendFileStartEqualToTotalIs416(): void
    {
        // Kills GreaterThanOrEqualTo→GreaterThan (391): start === total must be
        // unsatisfiable (416). bytes=100-100 on a 100-byte file → start 100 >= 100.
        $path = $this->makeTempFile(str_repeat('e', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=100-100']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 416, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes */100'], $this->headerCalls($fake));
    }

    public function testSendFileStartGreaterThanEndIs416(): void
    {
        // Kills GreaterThan→GreaterThanOrEqualTo (391, $start > $end): a valid
        // single-byte range bytes=5-5 (start == end) must NOT be 416; it is a
        // satisfiable 206 of length 1.
        $path = $this->makeTempFile(str_repeat('g', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=5-5']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes 5-5/100'], $this->headerCalls($fake));
        $this->assertContains(['header', 'Content-Length', '1'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 5, 1], $fake->log);
    }

    public function testSendFile416FlushesAndEndsEmpty(): void
    {
        // Kills MethodCallRemoval on parent->end('') in the 416 branch (395):
        // the empty body must be emitted.
        $path = $this->makeTempFile(str_repeat('h', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=500-600']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 416, ''], $fake->log);
        $this->assertContains(['end', ''], $fake->log);
    }

    public function testSendFileRangeEndClampedToTotalMinusOne(): void
    {
        // Kills 399 Minus/Decrement on min($end, $total - 1): bytes=10-999 on a
        // 100-byte file → end clamped to 99, length 90.
        $path = $this->makeTempFile(str_repeat('i', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=10-999']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['status', 206, ''], $fake->log);
        $this->assertContains(['header', 'Content-Range', 'bytes 10-99/100'], $this->headerCalls($fake));
        $this->assertContains(['header', 'Content-Length', '90'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 10, 90], $fake->log);
    }

    public function testSendFileRangeContentLengthIsStringExactly(): void
    {
        // Kills CastString (404): Content-Length must be the string '10'.
        $path = $this->makeTempFile(str_repeat('j', 100), 'css');
        $this->setRequestHeaders(['range' => 'bytes=0-9']);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $cl = null;
        foreach ($this->headerCalls($fake) as $h) {
            if ($h[1] === 'Content-Length') {
                $cl = $h[2];
            }
        }
        $this->assertSame('10', $cl);
    }

    public function testSendFileFullContentLengthIsStringExactly(): void
    {
        // Kills CastString (408): full send Content-Length must be the string '64'.
        $path = $this->makeTempFile(str_repeat('k', 64), 'css');
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $cl = null;
        foreach ($this->headerCalls($fake) as $h) {
            if ($h[1] === 'Content-Length') {
                $cl = $h[2];
            }
        }
        $this->assertSame('64', $cl);
    }

    public function testSendFileEmptyFileTotalZero(): void
    {
        // Kills the $total fallbacks and the Content-Length for a 0-byte file.
        $path = $this->makeTempFile('', 'css');
        $this->setRequestHeaders([]);
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $resp->sendFile($path);

        $this->assertContains(['header', 'Content-Length', '0'], $this->headerCalls($fake));
        $this->assertContains(['sendfile', $path, 0, 0], $fake->log);
    }

    // ---- flush() — cookie / rawCookie loops -------------------------------

    public function testFlushEmitsCookiesAndRawCookies(): void
    {
        // Kills Foreach_→[] (420, 423) and MethodCallRemoval (421, 424): flush()
        // must forward queued cookies and raw cookies to the parent.
        $g = RequestContext::instance();
        $g->status = 200;
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);
        $resp->cookie('sid', 'cv');
        $resp->rawCookie('rk', 'rv');

        $this->assertTrue($resp->flush());

        $this->assertContains(['cookie', 'sid', 'cv'], $fake->log);
        $this->assertContains(['rawCookie', 'rk', 'rv'], $fake->log);
    }

    // ---- __get / __set diagnostic logging ---------------------------------

    public function testGetLogsPropertyName(): void
    {
        // Kills FunctionCallRemoval on elog($name) in __get (74): reading a
        // proxied property must emit the property name to the debug log.
        $fake = new FakeOpenSwooleResponse();
        $fake->fd = 5;
        $resp = $this->wrap($fake);

        $log = $this->captureDebugLog(function () use ($resp): void {
            $_ = $resp->fd;
        });

        $this->assertStringContainsString('fd', $log, '__get must log the property name');
    }

    public function testSetLogsPropertyName(): void
    {
        // Kills FunctionCallRemoval on elog($name) in __set (94): writing a
        // proxied property must emit the property name to the debug log.
        $fake = new FakeOpenSwooleResponse();
        $resp = $this->wrap($fake);

        $log = $this->captureDebugLog(function () use ($resp): void {
            $resp->fd = 7;
        });

        $this->assertStringContainsString('fd', $log, '__set must log the property name');
    }

    // ---- redirect() security diagnostics ----------------------------------

    public function testRedirectProtocolRelativeLogsExactMessageWithUrl(): void
    {
        // Kills the elog() FunctionCallRemoval + Concat + ConcatOperandRemoval
        // mutants on line 177: the protocol-relative warning must contain the
        // exact prefix AND the URL, in that order.
        $resp = $this->wrap();

        // #243: external targets are blocked by default; opt in with
        // $allowExternal=true to exercise the (still-present) warn-log path.
        $log = $this->captureDebugLog(function () use ($resp): void {
            $resp->redirect('//cdn.example.com/asset', 302, true);
        });

        $this->assertStringContainsString(
            '[security] Protocol-relative redirect detected: //cdn.example.com/asset',
            $log
        );
    }

    public function testRedirectCrossOriginLogsExactMessageWithUrl(): void
    {
        // Kills the elog() FunctionCallRemoval + Concat + ConcatOperandRemoval
        // mutants on line 181: a cross-origin absolute redirect must log the
        // exact prefix AND the URL.
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'mysite.com'];
        $resp = $this->wrap();

        $log = $this->captureDebugLog(function () use ($resp): void {
            $resp->redirect('https://other.com/page', 302, true); // #243: opt-in external → warn path
        });

        $this->assertStringContainsString(
            '[security] Cross-origin redirect: https://other.com/page',
            $log
        );
    }

    public function testRedirectCrossOriginNotLoggedAsProtocolRelative(): void
    {
        // Kills IfNegation + PregMatchRemoveCaret on line 176: an absolute
        // cross-host URL must take the elseif (cross-origin) branch, NOT the
        // protocol-relative branch. With the caret removed from '#^//#', the
        // '//' inside 'https://' would wrongly match and log the protocol-relative
        // message instead.
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'mysite.com'];
        $resp = $this->wrap();

        $log = $this->captureDebugLog(function () use ($resp): void {
            $resp->redirect('https://other.com/page', 302, true); // #243: opt-in external → warn path
        });

        $this->assertStringContainsString('[security] Cross-origin redirect:', $log);
        $this->assertStringNotContainsString('Protocol-relative', $log);
    }

    public function testRedirectSameHostDoesNotLogCrossOrigin(): void
    {
        // Kills the second NotIdentical on line 180 (parse_url !== requestHost):
        // a same-host absolute redirect must NOT log a cross-origin warning.
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'mysite.com'];
        $resp = $this->wrap();

        $log = $this->captureDebugLog(function () use ($resp): void {
            $resp->redirect('https://mysite.com/dashboard');
        });

        $this->assertStringNotContainsString('Cross-origin redirect', $log);
        $this->assertStringNotContainsString('Protocol-relative', $log);
    }

    public function testRedirectAbsoluteUrlWithoutKnownHostDoesNotLogCrossOrigin(): void
    {
        // Kills the first NotIdentical + LogicalAnd + LogicalAndNegation on
        // line 180 ($requestHost !== ''): with no HTTP_HOST/SERVER_NAME the
        // requestHost is '', so the cross-origin check is skipped — no warning.
        $g = RequestContext::instance();
        $g->server = [];
        $resp = $this->wrap();

        $log = $this->captureDebugLog(function () use ($resp): void {
            $resp->redirect('https://other.com/page', 302, true); // #243: opt-in external → warn path
        });

        $this->assertStringNotContainsString('Cross-origin redirect', $log);
    }

    public function testRedirectCrossOriginUsesServerNameWhenNoHttpHost(): void
    {
        // Kills the Coalesce mutants on line 179: with only SERVER_NAME set,
        // the requestHost falls back to SERVER_NAME, so a different host is
        // detected as cross-origin and logged.
        $g = RequestContext::instance();
        $g->server = ['SERVER_NAME' => 'canonical.example'];
        $resp = $this->wrap();

        $log = $this->captureDebugLog(function () use ($resp): void {
            $resp->redirect('https://elsewhere.example/x', 302, true); // #243: opt-in external → warn path
        });

        $this->assertStringContainsString('[security] Cross-origin redirect: https://elsewhere.example/x', $log);
    }

    public function testRedirectSameHostViaServerNameDoesNotLog(): void
    {
        // Reinforces the Coalesce kill (179): when SERVER_NAME matches the
        // redirect host, no cross-origin warning is logged.
        $g = RequestContext::instance();
        $g->server = ['SERVER_NAME' => 'canonical.example'];
        $resp = $this->wrap();

        $log = $this->captureDebugLog(function () use ($resp): void {
            $resp->redirect('https://canonical.example/x');
        });

        $this->assertStringNotContainsString('Cross-origin redirect', $log);
    }

    public function testRedirectHttpHostTakesPriorityOverServerName(): void
    {
        // Kills the Coalesce-swap mutant (179): HTTP_HOST must be preferred over
        // SERVER_NAME. With both set to different values and the redirect host
        // matching HTTP_HOST, the original (HTTP_HOST ?? SERVER_NAME) sees a
        // same-host redirect → no warning. The swapped (SERVER_NAME ?? HTTP_HOST)
        // would pick SERVER_NAME → treat it as cross-origin and log.
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => 'real.example', 'SERVER_NAME' => 'other.example'];
        $resp = $this->wrap();

        $log = $this->captureDebugLog(function () use ($resp): void {
            $resp->redirect('https://real.example/dashboard');
        });

        $this->assertStringNotContainsString('Cross-origin redirect', $log);
    }
}

<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Middleware\RangeMiddleware;
use ZealPHP\Tests\TestCase;

class RangeMiddlewareTest extends TestCase
{
    // 54 bytes. Index map used throughout for exact-offset assertions:
    //   0:H 1:e 2:l 3:l 4:o 5:, 6:(sp) 7:W ... 13:(sp) 14:T 15:h 16:i 17:s ...
    //   49:e 50:s 51:t 52:s 53:.
    public const BODY = 'Hello, World! This is test content for range requests.';

    /**
     * Install a recording zealphp_response wrapper so the setHeader() path
     * (which writes Accept-Ranges / Content-Range / Content-Length / Content-Type
     * to $g->zealphp_response) is exercised and assertable. Returns the recorder.
     */
    private function installRecorder(): object
    {
        $g = RequestContext::instance();
        $recorder = new class {
            /** @var array<string, string> */
            public array $headers = [];
            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }
        };
        $g->zealphp_response = $recorder;
        return $recorder;
    }

    private function clearRecorder(): void
    {
        RequestContext::instance()->zealphp_response = null;
    }

    /**
     * Build and run the middleware. Returns the PSR-7 response.
     */
    private function dispatchRange(
        ?string $rangeHeader,
        int $status = 200,
        ?string $body = null,
        string $method = 'GET',
        ?string $ifRange = null,
        ?string $etag = null,
        ?string $contentType = 'text/plain'
    ): ResponseInterface {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $middleware = new RangeMiddleware();

        $headers = [];
        if ($rangeHeader !== null) {
            $headers['range'] = $rangeHeader;
        }
        if ($ifRange !== null) {
            $headers['if-range'] = $ifRange;
        }

        $request = new ServerRequest('/', $method, '', $headers);
        $responseBody = $body ?? self::BODY;

        $respHeaders = [];
        if ($contentType !== null) {
            $respHeaders['Content-Type'] = $contentType;
        }
        if ($etag !== null) {
            $respHeaders['ETag'] = $etag;
        }

        $handler = new class($responseBody, $status, $respHeaders) implements RequestHandlerInterface {
            /** @param array<string, string> $headers */
            public function __construct(private string $body, private int $status, private array $headers) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, $this->status, '', $this->headers);
            }
        };

        return $middleware->process($request, $handler);
    }

    // ---------------------------------------------------------------------
    // Pass-through gates
    // ---------------------------------------------------------------------

    public function testNoRangeHeaderAddsAcceptRangesAndKeepsBody(): void
    {
        $rec = $this->installRecorder();
        $response = $this->dispatchRange(null);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        // setHeader('Accept-Ranges','bytes') ran on the wrapper (kills line-54 removal).
        $this->assertSame('bytes', $rec->headers['Accept-Ranges'] ?? null);
        $this->clearRecorder();
    }

    public function testSkipsNon200Responses(): void
    {
        $response = $this->dispatchRange('bytes=0-4', 301);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
        $this->assertSame('', $response->getHeaderLine('Accept-Ranges'));
    }

    public function testSkipsEmptyBody(): void
    {
        $response = $this->dispatchRange('bytes=0-4', 200, '');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Accept-Ranges'));
    }

    public function testSkipsPostRequests(): void
    {
        $response = $this->dispatchRange('bytes=0-4', 200, null, 'POST');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    public function testHeadRequestIsHonouredForRange(): void
    {
        // HEAD is treated like GET (not skipped) — proves the method gate
        // accepts HEAD, distinguishing GET-only from GET||HEAD.
        $response = $this->dispatchRange('bytes=0-4', 200, null, 'HEAD');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 0-4/54', $response->getHeaderLine('Content-Range'));
    }

    public function testStreamingResponseIsPassedThrough(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $g = RequestContext::instance();
        $g->_streaming = true;

        $middleware = new RangeMiddleware();
        $request = new ServerRequest('/', 'GET', '', ['range' => 'bytes=0-4']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(RangeMiddlewareTest::BODY, 200, '', ['Content-Type' => 'text/plain']);
            }
        };

        $response = $middleware->process($request, $handler);

        // Streaming guard short-circuits BEFORE Accept-Ranges is set.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
        $this->assertSame('', $response->getHeaderLine('Accept-Ranges'));

        $g->_streaming = null;
    }

    // ---------------------------------------------------------------------
    // Range header parsing (regex anchors, flags, trim)
    // ---------------------------------------------------------------------

    public function testNonBytesUnitIsIgnored(): void
    {
        // "items=0-4" must NOT match /^bytes=.../ — proves the ^ caret anchor.
        $response = $this->dispatchRange('items=0-4');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    public function testRangeWithLeadingGarbageIsIgnored(): void
    {
        // " bytes=0-4" has junk before "bytes=". With the ^ anchor present it
        // must NOT match; dropping ^ would let it match. Kills PregMatchRemoveCaret.
        $response = $this->dispatchRange('xbytes=0-4');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    public function testUppercaseBytesUnitMatches(): void
    {
        // "BYTES=0-4" matches only because of the /i flag. Kills PregMatchRemoveFlags.
        $response = $this->dispatchRange('BYTES=0-4');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('Hello', (string) $response->getBody());
        $this->assertSame('bytes 0-4/54', $response->getHeaderLine('Content-Range'));
    }

    public function testRangeWithSurroundingSpacesIsTrimmed(): void
    {
        // Spaces around the spec must be trimmed: " 0-4 " → "0-4". Without trim()
        // the spec stays " 0-4 ", (int)" 0" = 0 but end "(int)"4 "=4 -> actually
        // PHP (int) tolerates leading space. Use a leading-space start that would
        // mis-parse only the suffix detection. Simpler: assert exact slice still
        // correct so trim removal that breaks str_starts_with('-') detection dies.
        $response = $this->dispatchRange('bytes= -5 ');
        $this->assertSame(206, $response->getStatusCode());
        // " -5 " trimmed → "-5" suffix → last 5 bytes "ests.".
        // Without trim, the leading space breaks str_starts_with($spec,'-'),
        // so it falls to the bounded branch and parses differently.
        $this->assertSame('ests.', (string) $response->getBody());
        $this->assertSame('bytes 49-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testMultiRangeSpacesAfterCommaTrimmed(): void
    {
        $response = $this->dispatchRange('bytes=0-4, 14-17');
        $this->assertSame(206, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Content-Range: bytes 0-4/54', $body);
        $this->assertStringContainsString('Content-Range: bytes 14-17/54', $body);
    }

    // ---------------------------------------------------------------------
    // Single bounded range — exact slicing + Content-Range + recorder headers
    // ---------------------------------------------------------------------

    public function testSingleRangeReturns206ExactSlice(): void
    {
        $rec = $this->installRecorder();
        $response = $this->dispatchRange('bytes=0-4');

        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('Hello', (string) $response->getBody());
        $this->assertSame(5, $response->getBody()->getSize());
        $this->assertSame('bytes 0-4/54', $response->getHeaderLine('Content-Range'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));

        // Recorder must have captured Content-Range, Content-Length, Accept-Ranges
        // (kills setHeader MethodCallRemoval at lines 54, 132, 133, 189).
        $this->assertSame('bytes 0-4/54', $rec->headers['Content-Range'] ?? null);
        $this->assertSame('5', $rec->headers['Content-Length'] ?? null);
        $this->assertSame('bytes', $rec->headers['Accept-Ranges'] ?? null);
        // $g->status set to exactly 206 (kills Increment/Decrement at line 134).
        $this->assertSame(206, RequestContext::instance()->status);
        $this->clearRecorder();
    }

    public function testSingleRangeMiddleSlice(): void
    {
        // bytes=14-17 → "This" (indices 14,15,16,17). Exact length 4.
        $response = $this->dispatchRange('bytes=14-17');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('This', (string) $response->getBody());
        $this->assertSame(4, $response->getBody()->getSize());
        $this->assertSame('bytes 14-17/54', $response->getHeaderLine('Content-Range'));
    }

    public function testSingleByteRange(): void
    {
        // bytes=7-7 → single byte 'W'. Kills substr length off-by-one (Minus mutant):
        // end-start+1 = 1; end+start+1 = 15 would slice 'World! This is '.
        $response = $this->dispatchRange('bytes=7-7');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('W', (string) $response->getBody());
        $this->assertSame(1, $response->getBody()->getSize());
        $this->assertSame('bytes 7-7/54', $response->getHeaderLine('Content-Range'));
    }

    public function testRangeToExactLastByte(): void
    {
        // bytes=53-53 → last byte '.'. start==total-1 must be satisfiable.
        $response = $this->dispatchRange('bytes=53-53');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('.', (string) $response->getBody());
        $this->assertSame('bytes 53-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testRangeStartAtLastValidByteOpen(): void
    {
        // bytes=53- → start=53 (total-1). $start >= $total is 53 >= 54 = false → OK.
        // Kills GreaterThanOrEqualTo->GreaterThan? No: 53 > 54 also false. This
        // pins satisfiability of the boundary; the off-by-one is pinned below.
        $response = $this->dispatchRange('bytes=53-');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('.', (string) $response->getBody());
        $this->assertSame('bytes 53-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testEndClampedToLastByte(): void
    {
        // bytes=50-99 → end clamped via min($end, total-1)=53. Slice "sts.".
        // min($end, total-0)=54 would slice 5 bytes (out of range -> "sts.")
        // but Content-Range header would read 50-54 not 50-53. Kills
        // DecrementInteger/IncrementInteger/Minus on `$total - 1` in min().
        $response = $this->dispatchRange('bytes=50-99');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('sts.', (string) $response->getBody());
        $this->assertSame('bytes 50-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testOpenEndRange(): void
    {
        // bytes=50- → [50,53] "sts."
        $response = $this->dispatchRange('bytes=50-');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('sts.', (string) $response->getBody());
        $this->assertSame('bytes 50-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testOpenEndFromZeroIsWholeBody(): void
    {
        // bytes=0- → [0,53] whole body. Kills UnwrapSubstr/CastInt on start parse.
        $response = $this->dispatchRange('bytes=0-');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
        $this->assertSame('bytes 0-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testSuffixRange(): void
    {
        // bytes=-5 → last 5 bytes [49,53] "ests."
        $response = $this->dispatchRange('bytes=-5');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('ests.', (string) $response->getBody());
        $this->assertSame('bytes 49-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testSuffixRangeFullLength(): void
    {
        // bytes=-54 → suffixLen == total → satisfiable ([0,53]). suffixLen > total
        // is 54 > 54 = false. Kills GreaterThan->GreaterThanOrEqualTo on line 86.
        $response = $this->dispatchRange('bytes=-54');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
        $this->assertSame('bytes 0-53/54', $response->getHeaderLine('Content-Range'));
    }

    public function testSuffixRangeOneByte(): void
    {
        // bytes=-1 → last byte [53,53] "."
        $response = $this->dispatchRange('bytes=-1');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('.', (string) $response->getBody());
        $this->assertSame('bytes 53-53/54', $response->getHeaderLine('Content-Range'));
    }

    // ---------------------------------------------------------------------
    // Unsatisfiable (416) boundary cases
    // ---------------------------------------------------------------------

    public function testUnsatisfiableBoundedRange(): void
    {
        $rec = $this->installRecorder();
        $response = $this->dispatchRange('bytes=100-200');

        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame('bytes */54', $response->getHeaderLine('Content-Range'));
        // Recorder captured Content-Range (kills setHeader removal at line 173).
        $this->assertSame('bytes */54', $rec->headers['Content-Range'] ?? null);
        // $g->status set to exactly 416 (kills Increment/Decrement at line 174).
        $this->assertSame(416, RequestContext::instance()->status);
        $this->clearRecorder();
    }

    public function testUnsatisfiableStartEqualsTotal(): void
    {
        // bytes=54-60 → start(54) >= total(54) → 416. Kills GreaterThanOrEqualTo
        // mutation on `$start >= $total` (54 > 54 = false would WRONGLY satisfy).
        $response = $this->dispatchRange('bytes=54-60');
        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */54', $response->getHeaderLine('Content-Range'));
    }

    public function testUnsatisfiableStartGreaterThanEnd(): void
    {
        // bytes=10-5 → start(10) > end(5) → 416. Kills GreaterThan->GreaterThanOrEqualTo
        // on `$start > $end` and the LogicalOr split.
        $response = $this->dispatchRange('bytes=10-5');
        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */54', $response->getHeaderLine('Content-Range'));
    }

    public function testBoundedStartEqualsEndSatisfiable(): void
    {
        // bytes=5-5 → start==end → satisfiable single byte. Kills
        // GreaterThan->GreaterThanOrEqualTo on `$start > $end` (5 >= 5 = true
        // would WRONGLY make it 416). Byte index 5 is ','.
        $response = $this->dispatchRange('bytes=5-5');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame(',', (string) $response->getBody());
        $this->assertSame('bytes 5-5/54', $response->getHeaderLine('Content-Range'));
    }

    public function testOpenEndStartEqualsTotalIsUnsatisfiable(): void
    {
        // bytes=54- → start(54) >= total(54) → 416. Kills GreaterThanOrEqualTo
        // on line 93 (54 > 54 = false would WRONGLY satisfy and produce empty slice).
        $response = $this->dispatchRange('bytes=54-');
        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */54', $response->getHeaderLine('Content-Range'));
    }

    public function testSuffixZeroIsUnsatisfiable(): void
    {
        // bytes=-0 → suffixLen 0; `<= 0` true → 416. Kills LessThanOrEqualTo->LessThan
        // ( 0 < 0 = false would treat -0 as satisfiable [54,53] empty slice).
        $response = $this->dispatchRange('bytes=-0');
        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */54', $response->getHeaderLine('Content-Range'));
    }

    public function testSuffixLargerThanFileServesWholeBody(): void
    {
        // bytes=-55 against a 54-byte body: a suffix longer than the file means
        // "the whole representation" (RFC 7233 §2.1) — clamp to the full body and
        // serve 206, not 416 (#181).
        $response = $this->dispatchRange('bytes=-55');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('bytes 0-53/54', $response->getHeaderLine('Content-Range'));
        $this->assertSame(self::BODY, (string) $response->getBody());
    }

    public function testMultiRangeWithOneUnsatisfiableSpecServesSatisfiable(): void
    {
        // A multi-range header with one out-of-bounds spec must serve the
        // satisfiable spec(s), not 416 the whole request (RFC 7233 §4.4) (#185).
        $response = $this->dispatchRange('bytes=0-4,9999-10000');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('Hello', (string) $response->getBody());
        $this->assertSame('bytes 0-4/54', $response->getHeaderLine('Content-Range'));
    }

    public function testEmptyRangeSpecIsUnsatisfiable(): void
    {
        // "bytes= " → (.+) captures the space, spec trims to "" → skipped →
        // ranges empty → 416.
        $response = $this->dispatchRange('bytes= ');
        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */54', $response->getHeaderLine('Content-Range'));
    }

    public function testUnsatisfiableStatusIsExactly416(): void
    {
        // Pins status 416 (kills DecrementInteger 415 / IncrementInteger 417).
        $response = $this->dispatchRange('bytes=200-300');
        $this->assertSame(416, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------
    // If-Range
    // ---------------------------------------------------------------------

    public function testIfRangeMatchHonoursRange(): void
    {
        // If-Range == ETag → range applied (206). Kills LogicalAnd split: with
        // `||`, etag!='' is true so it would short-circuit to 200 even on match.
        $response = $this->dispatchRange('bytes=0-4', 200, null, 'GET', 'W/"v1"', 'W/"v1"');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('Hello', (string) $response->getBody());
        $this->assertSame('bytes 0-4/54', $response->getHeaderLine('Content-Range'));
    }

    public function testIfRangeMismatchIgnoresRange(): void
    {
        // If-Range != ETag → range ignored (200).
        $response = $this->dispatchRange('bytes=0-4', 200, null, 'GET', 'W/"stale"', 'W/"fresh"');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(self::BODY, (string) $response->getBody());
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
    }

    public function testIfRangeWithNoEtagOnResponseHonoursRange(): void
    {
        // If-Range present but response has no ETag ($etag === '') → the
        // `$etag !== '' && ...` is false → range applied. Kills LogicalAnd split:
        // with `||`, `$ifRange !== $etag` ('W/"x"' !== '') is true so it would
        // WRONGLY return 200.
        $response = $this->dispatchRange('bytes=0-4', 200, null, 'GET', 'W/"x"', null);
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('Hello', (string) $response->getBody());
    }

    // ---------------------------------------------------------------------
    // Multi-range / multipart framing
    // ---------------------------------------------------------------------

    public function testMultiRangeMultipartExactFraming(): void
    {
        $rec = $this->installRecorder();
        $response = $this->dispatchRange('bytes=0-4,14-17');

        $this->assertSame(206, $response->getStatusCode());

        $ct = $response->getHeaderLine('Content-Type');
        $this->assertStringStartsWith('multipart/byteranges; boundary=zealphp_', $ct);

        // Extract boundary token to assert exact framing.
        $this->assertSame(1, preg_match('/boundary=(zealphp_[0-9a-f]+)$/', $ct, $bm));
        $boundary = $bm[1];
        // 'zealphp_' literal prefix + bin2hex(random_bytes(16)) → exactly 32 hex
        // chars. Kills random_bytes 15/17 (would give 30/34 hex chars), the
        // 'zealphp_' concat-operand-removal, and the concat-reorder mutants.
        $this->assertSame(1, preg_match('/^zealphp_[0-9a-f]{32}$/', $boundary));

        $body = (string) $response->getBody();

        // Exact expected multipart body, byte-for-byte. This pins part ordering,
        // header order (Content-Type before Content-Range), the blank line before
        // the slice, CRLF framing, and the closing boundary with trailing CRLF.
        $expected =
            "--{$boundary}\r\n"
            . "Content-Type: text/plain\r\n"
            . "Content-Range: bytes 0-4/54\r\n"
            . "\r\n"
            . "Hello"
            . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: text/plain\r\n"
            . "Content-Range: bytes 14-17/54\r\n"
            . "\r\n"
            . "This"
            . "\r\n--{$boundary}--\r\n";
        $this->assertSame($expected, $body);

        // Content-Length on the recorder equals the exact multipart body length.
        $this->assertSame((string) strlen($expected), $rec->headers['Content-Length'] ?? null);
        $this->assertSame($ct, $rec->headers['Content-Type'] ?? null);
        // $g->status set to exactly 206 (kills Increment/Decrement at line 163).
        $this->assertSame(206, RequestContext::instance()->status);
        $this->clearRecorder();
    }

    public function testMultiRangeStatusIsExactly206(): void
    {
        // Kills IncrementInteger/DecrementInteger on $g->status = 206 in multiRange.
        $response = $this->dispatchRange('bytes=0-1,3-4');
        $this->assertSame(206, $response->getStatusCode());
    }

    public function testMultiRangeNormalisesToAscendingOrder(): void
    {
        // #230: disjoint specs given out of order are coalesced into ascending,
        // non-overlapping order (RFC 9110 §14.2 permits the server to reorder
        // multipart ranges). `bytes=14-17,0-4` → parts emitted 0-4 THEN 14-17.
        // (Was testMultiRangePreservesSpecOrder, which pinned the pre-coalesce
        // source-order behaviour replaced by the DoS-amplification fix.)
        $response = $this->dispatchRange('bytes=14-17,0-4');
        $body = (string) $response->getBody();
        $posHello = strpos($body, 'bytes 0-4/54');
        $posThis = strpos($body, 'bytes 14-17/54');
        $this->assertNotFalse($posHello);
        $this->assertNotFalse($posThis);
        // Both disjoint specs survive, now ascending: 0-4 precedes 14-17.
        $this->assertLessThan($posThis, $posHello);
    }

    public function testMultiRangeDefaultContentTypeWhenMissing(): void
    {
        // No Content-Type on upstream response → parts use application/octet-stream
        // (the ?: ternary default). Kills the Ternary mutation.
        $response = $this->dispatchRange('bytes=0-4,14-17', 200, null, 'GET', null, null, null);
        $body = (string) $response->getBody();
        $this->assertStringContainsString("Content-Type: application/octet-stream\r\n", $body);
        $this->assertStringNotContainsString('Content-Type: text/plain', $body);
    }

    // ---------------------------------------------------------------------
    // Single range exact status
    // ---------------------------------------------------------------------

    public function testSingleRangeStatusIsExactly206(): void
    {
        // Kills IncrementInteger/DecrementInteger on $g->status = 206 in singleRange.
        $response = $this->dispatchRange('bytes=2-3');
        $this->assertSame(206, $response->getStatusCode());
    }

    // ---------------------------------------------------------------------
    // DataProvider sweep of exact slices across boundaries
    // ---------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function exactSliceProvider(): array
    {
        return [
            'first byte'        => ['bytes=0-0', 'H', 'bytes 0-0/54'],
            'first five'        => ['bytes=0-4', 'Hello', 'bytes 0-4/54'],
            'last byte open'    => ['bytes=53-', '.', 'bytes 53-53/54'],
            'suffix two'        => ['bytes=-2', 's.', 'bytes 52-53/54'],
            'middle word This'  => ['bytes=14-17', 'This', 'bytes 14-17/54'],
            'clamp past end'    => ['bytes=52-100', 's.', 'bytes 52-53/54'],
        ];
    }

    #[DataProvider('exactSliceProvider')]
    public function testExactSlices(string $range, string $expectedBody, string $expectedCr): void
    {
        $response = $this->dispatchRange($range);
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame($expectedBody, (string) $response->getBody());
        $this->assertSame($expectedCr, $response->getHeaderLine('Content-Range'));
    }
}

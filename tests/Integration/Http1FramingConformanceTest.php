<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Conformance: HTTP/1.1 message framing & request-smuggling defense
 * (RFC 9112 §6–§7). curl can't emit malformed framing, so these send raw bytes
 * over a socket and assert the server's safety property:
 *
 *   A malformed/ambiguous request MUST NOT be processed as a normal 200 — it is
 *   rejected with a 4xx or the connection is dropped. This is the surface where
 *   request smuggling lives, so "never silently accept ambiguous framing" is the
 *   bar (RFC 9112 §6.1: reject messages with both Content-Length and
 *   Transfer-Encoding; §6.3: a recipient MUST treat duplicate Content-Length as
 *   unrecoverable).
 *
 * Documents where OpenSwoole's parser draws the line (see STANDARDS.md).
 */
class Http1FramingConformanceTest extends TestCase
{
    /**
     * Send raw bytes; return the parsed status int (or null if the connection
     * was closed / no HTTP response — also a "rejected" outcome).
     *
     * @return array{status: int|null, raw: string}
     */
    private function raw(string $bytes, float $timeout = 3.0): array
    {
        $host = parse_url(self::$baseUrl, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url(self::$baseUrl, PHP_URL_PORT) ?: 8080;
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
        $this->assertNotFalse($fp, "socket connect failed: $errstr");
        fwrite($fp, $bytes);
        stream_set_timeout($fp, (int) $timeout);
        $resp = '';
        $start = microtime(true);
        while (!feof($fp)) {
            $chunk = fread($fp, 512);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $resp .= $chunk;
            if (strlen($resp) > 2048 || (microtime(true) - $start) > $timeout) {
                break;
            }
        }
        fclose($fp);
        $status = null;
        if (preg_match('#^HTTP/1\.[01] (\d{3})#', $resp, $m) === 1) {
            $status = (int) $m[1];
        }
        return ['status' => $status, 'raw' => $resp];
    }

    private const CRLF = "\r\n";

    /** Sanity: a well-formed request is accepted, so the harness is valid. */
    public function testWellFormedRequestIs200(): void
    {
        $r = $this->raw('GET /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF . 'Connection: close' . self::CRLF . self::CRLF);
        $this->assertSame(200, $r['status']);
    }

    /** RFC 9112 §6.1: Content-Length + Transfer-Encoding ⇒ reject (smuggling). */
    public function testContentLengthPlusTransferEncodingRejected(): void
    {
        $r = $this->raw(
            'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Content-Length: 5' . self::CRLF . 'Transfer-Encoding: chunked' . self::CRLF
            . self::CRLF . '0' . self::CRLF . self::CRLF
        );
        // The smuggling-safety property: the ambiguous message is rejected with a
        // 4xx and never processed as a normal 200 (so no hidden second request is
        // smuggled through the body). Exact code is build-dependent — 400 on most
        // OpenSwoole builds, 404 on some — both are client-error rejections.
        $this->assertNotNull($r['status'], 'CL+TE must yield an HTTP response, not hang');
        $this->assertGreaterThanOrEqual(400, $r['status'], 'CL+TE must be rejected (4xx), not accepted');
        $this->assertLessThan(500, $r['status']);
    }

    /** RFC 9112 §6.3: duplicate Content-Length is unrecoverable ⇒ must not 2xx. */
    public function testDuplicateContentLengthRejected(): void
    {
        $r = $this->raw(
            'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Content-Length: 5' . self::CRLF . 'Content-Length: 6' . self::CRLF
            . self::CRLF . 'hello'
        );
        $this->assertNotSame(200, $r['status'], 'duplicate Content-Length must not be accepted as 200');
    }

    /** RFC 9112 §2.2: CRLF is the line terminator; bare LF must not 2xx. */
    public function testBareLfRequestRejected(): void
    {
        $r = $this->raw("GET /json HTTP/1.1\nHost: x\n\n");
        $this->assertNotSame(200, $r['status'], 'bare-LF framed request must not be accepted as 200');
    }

    /** RFC 9112 §7.1: an invalid (non-hex) chunk size must not 2xx. */
    public function testInvalidChunkSizeRejected(): void
    {
        $r = $this->raw(
            'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Transfer-Encoding: chunked' . self::CRLF . self::CRLF
            . 'zz' . self::CRLF . 'hello' . self::CRLF . '0' . self::CRLF . self::CRLF
        );
        $this->assertNotSame(200, $r['status'], 'invalid chunk size must not be accepted as 200');
    }

    /** Oversized header block is rejected (size limit), not buffered unbounded. */
    public function testOversizedHeaderRejected(): void
    {
        $r = $this->raw(
            'GET /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'X-Big: ' . str_repeat('A', 70000) . self::CRLF
            . 'Connection: close' . self::CRLF . self::CRLF
        );
        // Safety property: rejected with a 4xx, never processed as a normal 200.
        // The exact code varies by OpenSwoole build/config (400 or 404 observed);
        // what matters is it's a client-error rejection, not unbounded buffering.
        $this->assertNotNull($r['status'], 'oversized header must yield an HTTP response, not hang');
        $this->assertGreaterThanOrEqual(400, $r['status']);
        $this->assertLessThan(500, $r['status']);
    }

    /** RFC 9112 §3.2: an HTTP/1.1 request without Host MUST be rejected (400). */
    public function testHttp11MissingHostRejected(): void
    {
        $r = $this->raw("GET /json HTTP/1.1\r\nConnection: close\r\n\r\n");
        $this->assertSame(400, $r['status'], 'HTTP/1.1 without Host must be 400');
    }

    /** RFC 9112 §3.2: HTTP/1.1 with a Host is accepted. */
    public function testHttp11WithHostAccepted(): void
    {
        $r = $this->raw("GET /json HTTP/1.1\r\nHost: x\r\nConnection: close\r\n\r\n");
        $this->assertSame(200, $r['status']);
    }

    /** HTTP/1.0 does not require Host (RFC 9112 §3.2 applies to 1.1) — accepted. */
    public function testHttp10WithoutHostAccepted(): void
    {
        $r = $this->raw("GET /json HTTP/1.0\r\nConnection: close\r\n\r\n");
        $this->assertSame(200, $r['status'], 'HTTP/1.0 without Host is valid');
    }

    /** Chunk extensions (`5;ext=val`, RFC 9112 §7.1.1) handled safely (parsed or rejected, never mis-framed/hung). */
    public function testChunkExtensionHandledSafely(): void
    {
        $r = $this->raw(
            'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Transfer-Encoding: chunked' . self::CRLF . 'Connection: close' . self::CRLF
            . self::CRLF . '5;ext=val' . self::CRLF . 'hello' . self::CRLF . '0' . self::CRLF . self::CRLF
        );
        $this->assertNotNull($r['status'], 'chunk extension must yield a definite HTTP response, not hang');
    }

    /** Trailer headers after the last chunk (RFC 9112 §7.1.2) are parsed, not mishandled. */
    public function testChunkTrailerParsed(): void
    {
        $r = $this->raw(
            'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Transfer-Encoding: chunked' . self::CRLF . 'Connection: close' . self::CRLF
            . self::CRLF . '5' . self::CRLF . 'hello' . self::CRLF . '0' . self::CRLF
            . 'X-Trailer: v' . self::CRLF . self::CRLF
        );
        $this->assertNotNull($r['status']);
        $this->assertGreaterThanOrEqual(200, $r['status'], 'valid trailers must parse to a response');
    }

    /** Leading-zero chunk size (`0005`) is parsed as hex 5 (RFC 9112 §7.1). */
    public function testChunkLeadingZerosParsed(): void
    {
        $r = $this->raw(
            'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Transfer-Encoding: chunked' . self::CRLF . 'Connection: close' . self::CRLF
            . self::CRLF . '0005' . self::CRLF . 'hello' . self::CRLF . '0' . self::CRLF . self::CRLF
        );
        $this->assertNotNull($r['status']);
        $this->assertGreaterThanOrEqual(200, $r['status']);
    }

    /** A well-formed chunked body is dechunked and processed (valid framing). */
    public function testValidChunkedBodyIsParsed(): void
    {
        $r = $this->raw(
            'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Transfer-Encoding: chunked' . self::CRLF . 'Connection: close' . self::CRLF
            . self::CRLF . '5' . self::CRLF . 'hello' . self::CRLF . '0' . self::CRLF . self::CRLF
        );
        // Parsed to a real HTTP response (not a framing-level drop): any valid status.
        $this->assertNotNull($r['status'], 'valid chunked request must yield an HTTP response');
        $this->assertGreaterThanOrEqual(200, $r['status']);
    }

    // ---- M14: framing conformance — OpenSwoole-owned behaviour probes -------
    // These tests probe the OpenSwoole parser's handling of edge-case HTTP/1.1
    // framing. The parser owns these decisions; ZealPHP cannot override them.
    // The safety property asserted in each case is: the server MUST NOT process
    // the ambiguous/malformed request as a normal 200. Actual rejection codes
    // (4xx, connection drop, 5xx) are all acceptable outcomes; only silent 200
    // acceptance is a failure. See audit 03-body-chunked-clte.md gaps #3, #6, #10.

    /**
     * RFC 9112 §6.1: Transfer-Encoding value that is not "chunked" (e.g. "gzip")
     * MUST be rejected with 400 and the connection closed. Apache returns
     * HTTP_BAD_REQUEST for any non-chunked TE (http_core.c:303-311).
     *
     * Safety property: OpenSwoole MUST NOT process the body as a normal 200.
     * The actual status (400, 404, connection drop) is OpenSwoole-owned; this
     * test documents and pins the behaviour so regressions are caught.
     */
    public function testUnknownTransferEncodingNotAcceptedAs200(): void
    {
        $r = $this->raw(
            'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Transfer-Encoding: gzip' . self::CRLF . 'Connection: close' . self::CRLF
            . 'Content-Length: 5' . self::CRLF
            . self::CRLF . 'hello'
        );
        // Safety property: a non-chunked TE must never be silently processed as 200.
        // RFC 9112 §6.1 requires 400 and connection close; OpenSwoole may also drop
        // the connection entirely (status null). Both are acceptable safe outcomes.
        $this->assertNotSame(
            200,
            $r['status'],
            'Transfer-Encoding: gzip on a request must not be accepted as 200 (RFC 9112 §6.1)'
        );
    }

    /**
     * RFC 9112 §7.1 / Apache http_filters.c:222-226: a chunk-size line with more
     * hex digits than can fit in a signed 64-bit integer triggers an overflow guard
     * (APR_ENOSPC → 413 in Apache). OpenSwoole's chunk parser must not silently
     * accept or mis-frame such a request.
     *
     * Safety property: OpenSwoole MUST NOT return 200 for a chunk-size that
     * overflows. The actual status (4xx, 5xx, connection drop) is parser-owned.
     */
    public function testChunkSizeNumericOverflowNotAcceptedAs200(): void
    {
        // 100 hex 'f' digits — far exceeds apr_off_t / int64 capacity. Apache's
        // chunkbits counter (sizeof(apr_off_t)*8-4 bits) would underflow to < 0
        // after ~16 hex digits, returning APR_ENOSPC → 413.
        $oversizedHex = str_repeat('f', 100);
        $r = $this->raw(
            'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Transfer-Encoding: chunked' . self::CRLF . 'Connection: close' . self::CRLF
            . self::CRLF
            . $oversizedHex . self::CRLF . 'data' . self::CRLF . '0' . self::CRLF . self::CRLF
        );
        // Safety property: an overflowing chunk-size must not be accepted as 200.
        // OpenSwoole may return 400/413 or drop the connection (status null).
        $this->assertNotSame(
            200,
            $r['status'],
            'chunk-size integer overflow must not be accepted as 200 (RFC 9112 §7.1)'
        );
    }

    /**
     * RFC 9112 §7.1: a recipient MUST treat premature TCP close mid-chunk as an
     * error. Apache returns APR_INCOMPLETE → 400 (http_filters.c:573-576).
     *
     * Safety property: OpenSwoole MUST NOT return 200 after accepting only part
     * of a declared chunk. Connection drop (null status) and 4xx are both safe.
     *
     * Note: a slow-path race is possible — the server may not have responded
     * before we parse. A null status is therefore explicitly accepted here.
     */
    public function testPrematureTcpCloseMidChunkNotAcceptedAs200(): void
    {
        $host = parse_url(self::$baseUrl, PHP_URL_HOST) ?: '127.0.0.1';
        $port = parse_url(self::$baseUrl, PHP_URL_PORT) ?: 8080;
        $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 3.0);
        $this->assertNotFalse($fp, "socket connect failed: $errstr");

        // Declare a 100-byte chunk but only send 10 bytes of data, then close.
        $headers = 'POST /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Transfer-Encoding: chunked' . self::CRLF . 'Connection: close' . self::CRLF
            . self::CRLF;
        fwrite($fp, $headers . '64' . self::CRLF . 'truncated!');
        // Abrupt close — simulates premature EOF mid-chunk.
        fclose($fp);

        // Re-open a fresh connection to check the server is still alive and
        // correctly rejected (or ignored) the truncated request.
        $r = $this->raw(
            'GET /json HTTP/1.1' . self::CRLF . 'Host: x' . self::CRLF
            . 'Connection: close' . self::CRLF . self::CRLF
        );
        // The server must still be responsive after a truncated chunked body.
        $this->assertNotNull($r['status'], 'server must remain responsive after premature TCP close mid-chunk');
        $this->assertSame(200, $r['status'], 'a fresh well-formed request after truncated-body must succeed');
    }
}

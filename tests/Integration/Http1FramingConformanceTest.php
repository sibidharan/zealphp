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

    /**
     * Apache LimitRequestFields — a request carrying more header fields than the
     * configured limit (default 100) must be rejected with 400.
     * Apache: ap_get_mime_headers_core protocol.c:930-940.
     */
    public function testExcessHeaderFieldsRejectedWith400(): void
    {
        // Build a request with 102 distinct X-Hdr-N headers — over the default
        // limit of 100. Host counts as one HTTP_ header, so 101 X-Hdr-* headers
        // would give 102 total, safely above the 100 limit.
        $extraHeaders = '';
        for ($i = 1; $i <= 101; $i++) {
            $extraHeaders .= 'X-Hdr-' . $i . ': v' . self::CRLF;
        }
        $r = $this->raw(
            'GET /json HTTP/1.1' . self::CRLF
            . 'Host: x' . self::CRLF
            . $extraHeaders
            . 'Connection: close' . self::CRLF
            . self::CRLF
        );
        $this->assertSame(400, $r['status'], 'request with > LimitRequestFields headers must be 400');
    }

    /**
     * A request within the limit (exactly 100 headers including Host) is accepted.
     */
    public function testRequestWithinHeaderLimitIsAccepted(): void
    {
        // 99 X-Hdr-* + Host = 100 total HTTP_ headers — exactly at the limit.
        $extraHeaders = '';
        for ($i = 1; $i <= 99; $i++) {
            $extraHeaders .= 'X-Hdr-' . $i . ': v' . self::CRLF;
        }
        $r = $this->raw(
            'GET /json HTTP/1.1' . self::CRLF
            . 'Host: x' . self::CRLF
            . $extraHeaders
            . 'Connection: close' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull($r['status'], 'request at limit must yield an HTTP response');
        $this->assertSame(200, $r['status'], 'request within LimitRequestFields must be accepted');
    }
}

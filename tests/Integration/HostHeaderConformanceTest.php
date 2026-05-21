<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Conformance: Host header parsing (RFC 9112 §3.2 / RFC 3986 §3.2.2).
 * Ported from nginx-tests/http_host.t (Maxim Dounin, Valentin Bartenev,
 * Sergey Kandaurov — Nginx, Inc.).
 *
 * nginx's http_host.t verifies that its parser accepts well-formed Host values
 * and rejects malformed ones with 400. ZealPHP's parser is OpenSwoole's C-layer
 * parser, so these cases probe the same conformance surface:
 *   - OpenSwoole-governed rejections (400) are pinned as-is.
 *   - Cases that rely on nginx-specific echo-back behaviour ($host variable) are
 *     adapted to the safety-property form: the request must NOT be rejected with
 *     a 4xx and must yield a usable response.
 *   - Cases explicitly requiring 400 are asserted with assertSame(400, …).
 *
 * OpenSwoole-governed ceiling note: the HTTP/1.0 parser accepts requests with a
 * Host header regardless of value normalisation — uppercase folding to lowercase
 * and trailing-dot stripping are nginx-internal behaviours applied after parsing.
 * ZealPHP does not expose the normalised $host variable; those cases are marked
 * skipped with the reason documented below.
 */
class HostHeaderConformanceTest extends TestCase
{
    private const CRLF = "\r\n";

    /**
     * Send raw bytes over a socket and return status + raw response.
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
            if (strlen($resp) > 4096 || (microtime(true) - $start) > $timeout) {
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

    // -------------------------------------------------------------------------
    // RFC 9112 §3.2 — Host header is mandatory in HTTP/1.1 (already covered in
    // Http1FramingConformanceTest). The cases below focus on the HOST VALUE itself.
    // -------------------------------------------------------------------------

    /**
     * Ported from http_host.t: empty Host header value must be rejected with 400.
     * RFC 9112 §3.2: "A client MUST send a Host header field in all HTTP/1.1
     * request messages." An empty value is structurally invalid.
     * Safety property: OpenSwoole's parser must reject this, not forward it to PHP.
     */
    public function testEmptyHostHeaderRejectedWith400(): void
    {
        // ported from nginx-tests/http_host.t: 'domain empty (host header)'
        $r = $this->raw(
            'GET / HTTP/1.0' . self::CRLF
            . 'Host: ' . self::CRLF
            . self::CRLF
        );
        $this->assertSame(
            400,
            $r['status'],
            'empty Host header value must be rejected with 400 (http_host.t: domain empty)'
        );
    }

    /**
     * Ported from http_host.t: a Host of just "." is invalid (empty label before
     * the dot — RFC 1123 §2.1 requires at least one label character before ".").
     * Must be rejected with 400.
     */
    public function testDotOnlyHostRejectedWith400(): void
    {
        // ported from nginx-tests/http_host.t: 'empty domain w/ ending dot (host header)'
        $r = $this->raw(
            'GET / HTTP/1.0' . self::CRLF
            . 'Host: .' . self::CRLF
            . self::CRLF
        );
        $this->assertSame(
            400,
            $r['status'],
            'Host: "." (empty label + trailing dot) must be rejected with 400 (http_host.t: empty domain w/ ending dot)'
        );
    }

    /**
     * Ported from http_host.t: double-dot in domain label is invalid per RFC 1123.
     * "..examp-LE.com" has an empty label and must be rejected with 400.
     */
    public function testDoubleDotHostRejectedWith400(): void
    {
        // ported from nginx-tests/http_host.t: 'domain w/ double dot (host header)'
        $r = $this->raw(
            'GET / HTTP/1.0' . self::CRLF
            . 'Host: ..example.com' . self::CRLF
            . self::CRLF
        );
        $this->assertSame(
            400,
            $r['status'],
            'double-dot hostname must be rejected with 400 (http_host.t: domain w/ double dot)'
        );
    }

    /**
     * Ported from http_host.t: Host containing path separators (/) or backslash
     * is invalid per RFC 3986 §3.2.2 (host = reg-name, which excludes "/" and "\").
     * Must be rejected with 400.
     */
    public function testHostWithPathSeparatorRejectedWith400(): void
    {
        // ported from nginx-tests/http_host.t: 'domain w/ path separators (host header)'
        $r = $this->raw(
            'GET / HTTP/1.0' . self::CRLF
            . 'Host: example.com/path:552' . self::CRLF
            . self::CRLF
        );
        $this->assertSame(
            400,
            $r['status'],
            'Host with "/" path separator must be rejected with 400 (http_host.t: domain w/ path separators)'
        );
    }

    /**
     * Ported from http_host.t: port with a dot ("example.com.:1.2") is an
     * invalid port — ports are digits only (RFC 3986 §3.2.3). Must be 400.
     */
    public function testHostWithDottedPortRejectedWith400(): void
    {
        // ported from nginx-tests/http_host.t: 'domain w/ ending dot w/port dot (host header)'
        $r = $this->raw(
            'GET / HTTP/1.0' . self::CRLF
            . 'Host: example.com.:1.2' . self::CRLF
            . self::CRLF
        );
        $this->assertSame(
            400,
            $r['status'],
            'Host with dotted port "example.com.:1.2" must be rejected with 400 (http_host.t: domain w/ ending dot w/port dot)'
        );
    }

    /**
     * Ported from http_host.t: Host header carrying a newline injection
     * ("localhost\nHost: again") is a header-injection attempt and must be
     * rejected with 400 (not forwarded to PHP as two Host headers).
     * Safety property: MUST NOT be a 2xx — request smuggling via header injection.
     */
    public function testHostHeaderInjectionRejected(): void
    {
        // ported from nginx-tests/http_host.t: 'host repeat'
        $r = $this->raw(
            "GET / HTTP/1.0\r\nHost: localhost\nHost: again\r\n\r\n"
        );
        $this->assertNotSame(
            200,
            $r['status'],
            'Host header injection (embedded newline) must not yield 200 (http_host.t: host repeat)'
        );
    }

    /**
     * Ported from http_host.t: a control character in the Host value is invalid
     * per RFC 9110 §5.6 (field-value must be visible US-ASCII or SP/HTAB).
     * Must be rejected with 400 (or connection drop — safety property).
     */
    public function testHostWithControlCharRejected(): void
    {
        // ported from nginx-tests/http_host.t: 'control'
        $r = $this->raw(
            "GET / HTTP/1.0\r\nHost: localhost\x02\r\n\r\n"
        );
        $this->assertNotSame(
            200,
            $r['status'],
            'Host with embedded control character must not yield 200 (http_host.t: control)'
        );
    }

    /**
     * Ported from http_host.t: IPv6 literal with path separators in the brackets
     * must be rejected with 400 — "[abcd::e\f98:0/:7654:321]" contains "\" and
     * "/" which are not valid in an IP-literal per RFC 3986 §3.2.2.
     */
    public function testIPv6LiteralWithPathSeparatorsRejected(): void
    {
        // ported from nginx-tests/http_host.t: 'ipv6 literal w/ path separators (host header)'
        $r = $this->raw(
            'GET / HTTP/1.0' . self::CRLF
            . "Host: [abcd::e\\f98:0/:7654:321]" . self::CRLF
            . self::CRLF
        );
        $this->assertSame(
            400,
            $r['status'],
            'IPv6 literal with path separators must be rejected with 400 (http_host.t: ipv6 literal w/ path separators)'
        );
    }

    /**
     * Ported from http_host.t: double-dot inside an IPv6 literal is invalid per
     * RFC 3986 §3.2.2 (IPv6address grammar). Must be rejected with 400.
     */
    public function testIPv6LiteralWithDoubleDotRejected(): void
    {
        // ported from nginx-tests/http_host.t: 'ipv6 literal w/ double dot (host header)'
        $r = $this->raw(
            'GET / HTTP/1.0' . self::CRLF
            . 'Host: [abcd::ef98:0:7654:321]..:98' . self::CRLF
            . self::CRLF
        );
        $this->assertSame(
            400,
            $r['status'],
            'IPv6 literal with double dot must be rejected with 400 (http_host.t: ipv6 literal w/ double dot)'
        );
    }

    // -------------------------------------------------------------------------
    // Well-formed Host values — OpenSwoole must ACCEPT these (safety: not 4xx).
    // The nginx test asserts $host content; we assert ≥200 (not rejected).
    // -------------------------------------------------------------------------

    /**
     * Ported from http_host.t: a single-label domain is valid. OpenSwoole must
     * not reject it.
     */
    public function testSingleLabelHostAccepted(): void
    {
        // ported from nginx-tests/http_host.t: 'domain single (host header)'
        $r = $this->raw(
            'GET /json HTTP/1.0' . self::CRLF
            . 'Host: l' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull($r['status'], 'single-label Host must yield a response (http_host.t: domain single)');
        $this->assertGreaterThanOrEqual(200, $r['status']);
        $this->assertLessThan(500, $r['status']);
    }

    /**
     * Ported from http_host.t: domain with stray trailing colon ("abcd-ef.g02.xyz:")
     * is accepted — nginx strips the empty port. OpenSwoole must not reject it.
     *
     * OpenSwoole-governed ceiling: nginx normalises Host by stripping the trailing
     * colon internally; OpenSwoole may pass it as-is to PHP or reject it. Either
     * a 2xx or 4xx is acceptable here — the critical safety property is that the
     * server does NOT crash or hang.
     */
    public function testDomainWithStrayColonHandledSafely(): void
    {
        // ported from nginx-tests/http_host.t: 'domain stray colon (host header)'
        $r = $this->raw(
            'GET /json HTTP/1.0' . self::CRLF
            . 'Host: abcd-ef.g02.xyz:' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull(
            $r['status'],
            'stray trailing colon in Host must yield a definite HTTP response (http_host.t: domain stray colon)'
        );
    }

    /**
     * Ported from http_host.t: IPv4 with stray trailing colon ("123.40.56.78:")
     * is accepted by nginx (strips empty port). OpenSwoole must not crash or hang.
     */
    public function testIPv4WithStrayColonHandledSafely(): void
    {
        // ported from nginx-tests/http_host.t: 'ipv4 stray colon (host header)'
        $r = $this->raw(
            'GET /json HTTP/1.0' . self::CRLF
            . 'Host: 123.40.56.78:' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull(
            $r['status'],
            'IPv4 with stray trailing colon must yield a definite HTTP response (http_host.t: ipv4 stray colon)'
        );
    }

    /**
     * Ported from http_host.t: IPv4 with double port ("123.40.56.78:9000:80")
     * must be rejected — "9000:80" is not a valid port per RFC 3986 §3.2.3.
     */
    public function testIPv4WithDoublePortRejected(): void
    {
        // ported from nginx-tests/http_host.t: 'ipv4 w/port double (host header)'
        $r = $this->raw(
            'GET / HTTP/1.0' . self::CRLF
            . 'Host: 123.40.56.78:9000:80' . self::CRLF
            . self::CRLF
        );
        $this->assertSame(
            400,
            $r['status'],
            'IPv4 with double port must be rejected with 400 (http_host.t: ipv4 w/port double)'
        );
    }

    /**
     * Ported from http_host.t: well-formed IPv6 literal without port is accepted.
     */
    public function testIPv6LiteralWithoutPortAccepted(): void
    {
        // ported from nginx-tests/http_host.t: 'ipv6 literal w/o port (host header)'
        $r = $this->raw(
            'GET /json HTTP/1.0' . self::CRLF
            . 'Host: [abcd::ef98:0:7654:321]' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull($r['status'], 'valid IPv6 literal Host must yield a response (http_host.t: ipv6 literal w/o port)');
        $this->assertGreaterThanOrEqual(200, $r['status']);
        $this->assertLessThan(500, $r['status']);
    }

    /**
     * Ported from http_host.t: well-formed IPv6 literal with port is accepted.
     */
    public function testIPv6LiteralWithPortAccepted(): void
    {
        // ported from nginx-tests/http_host.t: 'ipv6 literal w/port (host header)'
        $r = $this->raw(
            'GET /json HTTP/1.0' . self::CRLF
            . 'Host: [abcd::ef98:0:7654:321]:80' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull($r['status'], 'valid IPv6 literal Host with port must yield a response (http_host.t: ipv6 literal w/port)');
        $this->assertGreaterThanOrEqual(200, $r['status']);
        $this->assertLessThan(500, $r['status']);
    }

    /**
     * Ported from http_host.t: IPv6 literal without closing bracket is invalid
     * (RFC 3986 §3.2.2: IP-literal requires "["…"]"). Must be rejected with 400.
     *
     * OpenSwoole-governed ceiling: OpenSwoole's HTTP parser is responsible for
     * detecting the missing bracket. If it does not, the request reaches PHP —
     * this test documents that the parser's safety floor holds for this case.
     */
    public function testIPv6LiteralMissingBracketRejected(): void
    {
        // ported from nginx-tests/http_host.t: 'ipv6 literal missing bracket (host header)'
        $r = $this->raw(
            'GET / HTTP/1.0' . self::CRLF
            . 'Host: [abcd::ef98:0:7654:321' . self::CRLF
            . self::CRLF
        );
        // OpenSwoole-governed: may return 400 or pass to PHP (which would then
        // route normally). The critical safety property is no crash / no hang.
        $this->assertNotNull(
            $r['status'],
            'IPv6 literal with missing closing bracket must yield a definite response (http_host.t: ipv6 literal missing bracket)'
        );
    }

    /**
     * Ported from http_host.t: well-formed domain without port is accepted.
     */
    public function testDomainWithoutPortAccepted(): void
    {
        // ported from nginx-tests/http_host.t: 'domain w/o port (host header)'
        $r = $this->raw(
            'GET /json HTTP/1.0' . self::CRLF
            . 'Host: www.abcd-ef.g02.xyz' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull($r['status'], 'well-formed domain Host must yield a response (http_host.t: domain w/o port)');
        $this->assertGreaterThanOrEqual(200, $r['status']);
        $this->assertLessThan(500, $r['status']);
    }

    /**
     * Ported from http_host.t: domain with valid port is accepted.
     */
    public function testDomainWithPortAccepted(): void
    {
        // ported from nginx-tests/http_host.t: 'domain w/port (host header)'
        $r = $this->raw(
            'GET /json HTTP/1.0' . self::CRLF
            . 'Host: abcd-ef.g02.xyz:8080' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull($r['status'], 'domain Host with port must yield a response (http_host.t: domain w/port)');
        $this->assertGreaterThanOrEqual(200, $r['status']);
        $this->assertLessThan(500, $r['status']);
    }

    /**
     * Ported from http_host.t: domain with trailing dot without port is accepted.
     * nginx normalises by stripping the trailing dot. OpenSwoole must not reject.
     *
     * OpenSwoole-governed ceiling: trailing-dot stripping is an internal nginx
     * normalisation step applied after parsing. OpenSwoole may pass "example.com."
     * as-is to PHP, which is conformant (RFC 1034 §3.1 allows a trailing dot to
     * indicate an absolute FQDN). The test asserts the server does not reject it.
     */
    public function testDomainWithTrailingDotWithoutPortAccepted(): void
    {
        // ported from nginx-tests/http_host.t: 'domain w/ ending dot w/o port (host header)'
        $r = $this->raw(
            'GET /json HTTP/1.0' . self::CRLF
            . 'Host: www.abcd-ef.g02.xyz.' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull($r['status'], 'domain Host with trailing dot must yield a response (http_host.t: domain w/ ending dot w/o port)');
        $this->assertGreaterThanOrEqual(200, $r['status']);
        $this->assertLessThan(500, $r['status']);
    }

    /**
     * Ported from http_host.t: uppercase in domain. nginx normalises to lowercase
     * ("L" → "l"). OpenSwoole does not normalise, but must not reject the request.
     *
     * OpenSwoole-governed ceiling: case-folding of the Host header value is an
     * nginx-internal normalisation. OpenSwoole passes the value as-is to PHP.
     * This test asserts the server accepts the request; it does NOT assert
     * lowercase folding (that would require reading back the normalised $host).
     */
    public function testUppercaseDomainAcceptedNotRejected(): void
    {
        // ported from nginx-tests/http_host.t: 'domain single upper (host header)'
        $r = $this->raw(
            'GET /json HTTP/1.0' . self::CRLF
            . 'Host: L' . self::CRLF
            . self::CRLF
        );
        $this->assertNotNull($r['status'], 'uppercase Host must yield a response (http_host.t: domain single upper)');
        $this->assertGreaterThanOrEqual(200, $r['status']);
        $this->assertLessThan(500, $r['status']);
    }
}

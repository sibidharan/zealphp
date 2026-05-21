<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration tests for HTTP protocol features:
 * redirects, HEAD, OPTIONS, cookies.
 */
class HttpFeaturesTest extends TestCase
{
    public function test301Redirect(): void
    {
        $r = $this->get('/http/redirect/301');
        $this->assertStatus(301, $r);
        $this->assertHeader('location', '/http/redirect-target', $r);
    }

    public function test302Redirect(): void
    {
        $r = $this->get('/http/redirect/302');
        $this->assertStatus(302, $r);
        $this->assertHeader('location', '/http/redirect-target', $r);
    }

    public function test307Redirect(): void
    {
        $r = $this->get('/http/redirect/307');
        $this->assertStatus(307, $r);
    }

    /**
     * Regression for issue #12 — Set-Cookie missing on 302 redirects when
     * a new session was started mid-request. OAuth flows that
     * `session_start(); $_SESSION['state'] = ...; header('Location: ...')`
     * lost their state on the next request because no PHPSESSID was
     * emitted. zeal_session_start() now auto-emits Set-Cookie for new
     * sessions, regardless of whether SessionStartMiddleware is registered.
     */
    public function testSessionCookieEmittedOnRedirect(): void
    {
        $r = $this->get('/http/session-redirect');
        $this->assertStatus(302, $r);
        $this->assertHeader('location', '/http/session-target', $r);
        $this->assertHeader('set-cookie', 'PHPSESSID=', $r);

        // Pull the session id out of the Set-Cookie header and replay it.
        // The data we stashed in $_SESSION must round-trip back to us.
        preg_match('/PHPSESSID=([^;]+)/', $r['headers']['set-cookie'] ?? '', $m);
        $this->assertNotEmpty($m[1] ?? '', 'PHPSESSID value missing from Set-Cookie');
        $follow = $this->get('/http/session-target', ['Cookie' => 'PHPSESSID=' . $m[1]]);
        $this->assertStatus(200, $follow);
        $json = $this->assertJsonResponse($follow);
        $this->assertSame('state-xyz', $json['oauth_state']);
    }

    public function testHeadReturnsNoBody(): void
    {
        $r = $this->http('HEAD', '/http/head-test');
        $this->assertStatus(200, $r);
        $this->assertSame('', $r['body']);
        $this->assertHeader('content-length', '2048', $r);
    }

    public function testHeadPreservesCustomHeaders(): void
    {
        $r = $this->http('HEAD', '/http/head-test');
        $this->assertHeader('x-custom-header', 'zealphp', $r);
    }

    public function testOptionsReturnsAllow(): void
    {
        $r = $this->http('OPTIONS', '/http/options-test');
        $this->assertStatus(204, $r);
        $allow = $r['headers']['allow'] ?? '';
        $this->assertStringContainsString('GET', $allow);
        $this->assertStringContainsString('POST', $allow);
        $this->assertStringContainsString('HEAD', $allow);
    }

    /** RFC 9110 §15.5.6: wrong method on a known resource → 405 + Allow. */
    public function testMethodNotAllowedReturns405WithAllow(): void
    {
        $r = $this->http('PUT', '/json'); // /json is a GET resource
        $this->assertStatus(405, $r);
        $allow = $r['headers']['allow'] ?? '';
        $this->assertStringContainsString('GET', $allow, 'Allow must list supported methods');
        $this->assertStringContainsString('OPTIONS', $allow);
    }

    /**
     * M4 (audit #4) — RFC 9110 §15.6.2: an unrecognised method is 501 Not
     * Implemented, not 404. Apache returns 501 for M_INVALID (protocol.c:1253).
     */
    public function testUnknownMethodReturns501(): void
    {
        $r = $this->http('FOOBAR', '/json');
        $this->assertStatus(501, $r);
    }

    /**
     * M6 (audit #4) — HEAD on an error response (404) must carry no body, the
     * same as HEAD on a 200. Apache's ap_send_error_response honours header_only.
     */
    public function testHeadOnNotFoundHasNoBody(): void
    {
        $r = $this->http('HEAD', '/no-such-route-zsh-method');
        $this->assertStatus(404, $r);
        $this->assertSame('', $r['body'], 'HEAD on 404 must not return a body');
    }

    /**
     * M5 (audit #4) — `OPTIONS *` is a server-wide capability probe: Apache
     * returns 200 with an empty body and no Allow header (http_core.c:336).
     * curl normalises the `*` target, so this case is driven over a raw socket.
     */
    public function testOptionsAsteriskReturns200(): void
    {
        $r = $this->rawRequest("OPTIONS * HTTP/1.1\r\nHost: 127.0.0.1\r\nConnection: close\r\n\r\n");
        $this->assertStringContainsString('HTTP/1.1 200', $r, "OPTIONS * should be 200, got:\n$r");
    }

    /**
     * H8 (audit #4) — TRACE is disabled by default (XST defence): 405 with an
     * Allow header, no request echo. The demo server does not call
     * traceEnabled(true), so this pins the hardened default.
     */
    public function testTraceDisabledByDefaultReturns405(): void
    {
        $r = $this->http('TRACE', '/json');
        $this->assertStatus(405, $r);
        $this->assertStringContainsString('GET', $r['headers']['allow'] ?? '');
    }

    /**
     * Raw HTTP/1.1 request over a socket — needed for request targets curl
     * rewrites (e.g. the `*` form of OPTIONS). Returns the full raw response.
     */
    private function rawRequest(string $request): string
    {
        $parts = parse_url(self::$baseUrl);
        $host  = $parts['host'] ?? '127.0.0.1';
        $port  = $parts['port'] ?? 80;
        $fp = @fsockopen($host, (int)$port, $errno, $errstr, 5);
        $this->assertNotFalse($fp, "socket connect failed: $errstr ($errno)");
        fwrite($fp, $request);
        $resp = '';
        while (!feof($fp)) {
            $chunk = fread($fp, 8192);
            if ($chunk === false) {
                break;
            }
            $resp .= $chunk;
        }
        fclose($fp);
        return $resp;
    }

    public function testCookieSameSite(): void
    {
        $r = $this->get('/http/cookie-test');
        $this->assertStatus(200, $r);
        $setCookie = $r['headers']['set-cookie'] ?? '';
        $this->assertStringContainsString('samesite', strtolower($setCookie));
    }

    public function testJsonContentType(): void
    {
        $r = $this->get('/demo/response/json');
        $this->assertHeader('content-type', 'application/json', $r);
    }

    public function testCustomResponseHeader(): void
    {
        $r = $this->get('/demo/response/headers');
        $this->assertStatus(200, $r);
        $this->assertArrayHasKey('x-powered-by', $r['headers']); // always set by ZealPHP
    }

    public function testCorsOnGet(): void
    {
        $r = $this->get('/demo/middleware/cors', ['Origin' => 'http://example.com']);
        $this->assertHeader('access-control-allow-origin', '*', $r);
    }

    public function testRangeSingleReturns206(): void
    {
        $r = $this->get('/http/range-test', ['Range' => 'bytes=0-9']);
        $this->assertStatus(206, $r);
        $this->assertSame('abcdefghij', $r['body']);
        $this->assertHeader('content-range', 'bytes 0-9/1000', $r);
        $this->assertHeader('accept-ranges', 'bytes', $r);
    }

    public function testRangeSuffixReturns206(): void
    {
        $r = $this->get('/http/range-test', ['Range' => 'bytes=-10']);
        $this->assertStatus(206, $r);
        $this->assertSame('abcdefghij', $r['body']);
        $this->assertHeader('content-range', 'bytes 990-999/1000', $r);
    }

    public function testRangeUnsatisfiableReturns416(): void
    {
        $r = $this->get('/http/range-test', ['Range' => 'bytes=5000-6000']);
        $this->assertStatus(416, $r);
        $this->assertHeader('content-range', 'bytes */1000', $r);
    }

    public function testRangeMultiReturnsMultipart(): void
    {
        $r = $this->get('/http/range-test', ['Range' => 'bytes=0-4,10-14']);
        $this->assertStatus(206, $r);
        $this->assertHeader('content-type', 'multipart/byteranges', $r);
        $this->assertStringContainsString('bytes 0-4/1000', $r['body']);
        $this->assertStringContainsString('abcde', $r['body']);
        $this->assertStringContainsString('bytes 10-14/1000', $r['body']);
    }

    public function testNoRangeHeaderAddsAcceptRanges(): void
    {
        $r = $this->get('/http/range-test');
        $this->assertStatus(200, $r);
        $this->assertHeader('accept-ranges', 'bytes', $r);
    }

    public function testSendFileServesFile(): void
    {
        $r = $this->get('/http/sendfile-test');
        $this->assertStatus(200, $r);
        $this->assertHeader('content-type', 'text/css', $r);
        $this->assertHeader('accept-ranges', 'bytes', $r);
        $this->assertNotEmpty($r['body']);
    }

    public function testSendFileRangeReturns206(): void
    {
        $r = $this->get('/http/sendfile-test', ['Range' => 'bytes=0-99']);
        $this->assertStatus(206, $r);
        $this->assertHeader('content-range', 'bytes 0-99/', $r);
        $this->assertSame(100, strlen($r['body']));
    }

    public function testStreamingResponseSetsAcceptRangesNone(): void
    {
        $r = $this->get('/stream/ssr');
        $this->assertStatus(200, $r);
        $this->assertHeader('accept-ranges', 'none', $r);
    }

    public function testPhpInfoRendersHtml(): void
    {
        $r = $this->get('/phpinfo');
        $this->assertStatus(200, $r);
        $this->assertHeader('content-type', 'text/html', $r);
        $this->assertStringContainsString('<!DOCTYPE html>', $r['body']);
        $this->assertStringContainsString('<table', $r['body']);
        $this->assertStringContainsString('PHP Version', $r['body']);
        $this->assertStringContainsString('ZealPHP', $r['body']);
    }
}

<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use ZealPHP\App;
use ZealPHP\ResponseMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * Unit coverage for the pure helpers behind the Apache-parity method-semantics
 * fixes in src/App.php (audit #4):
 *  - M4: App::KNOWN_METHODS gates the 501 path for unrecognised verbs.
 *  - H8: ResponseMiddleware::buildTraceEcho() reconstructs the message/http
 *    body Apache's ap_send_http_trace() echoes back (http_filters.c:1130).
 *
 * The wire-level cases (unknown method -> 501, OPTIONS * -> 200, HEAD on 404
 * has no body, TRACE-enabled echo) live in tests/Integration/HttpFeaturesTest.
 */
class MethodSemanticsTest extends TestCase
{
    public function testKnownMethodsCoverStandardVerbs(): void
    {
        foreach (['GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'TRACE', 'PATCH', 'CONNECT'] as $m) {
            $this->assertContains($m, App::KNOWN_METHODS, "$m must be a recognised method");
        }
    }

    public function testKnownMethodsCoverWebdavVerbs(): void
    {
        foreach (['PROPFIND', 'PROPPATCH', 'MKCOL', 'COPY', 'MOVE', 'LOCK', 'UNLOCK'] as $m) {
            $this->assertContains($m, App::KNOWN_METHODS, "WebDAV $m should be recognised (Apache registers it)");
        }
    }

    public function testKnownMethodsExcludesGarbageVerbs(): void
    {
        // These are the verbs the 501 path must catch.
        $this->assertNotContains('FOOBAR', App::KNOWN_METHODS);
        $this->assertNotContains('BREW', App::KNOWN_METHODS);
        $this->assertNotContains('get', App::KNOWN_METHODS, 'method match is case-sensitive (RFC 9110)');
    }

    public function testTraceEchoStartsWithRequestLine(): void
    {
        $body = ResponseMiddleware::buildTraceEcho('TRACE', '/probe', 'HTTP/1.1', []);
        $this->assertStringStartsWith("TRACE /probe HTTP/1.1\r\n", $body);
    }

    public function testTraceEchoTerminatesWithBlankLine(): void
    {
        // No headers: request line + CRLF + the terminating blank CRLF.
        $body = ResponseMiddleware::buildTraceEcho('TRACE', '/x', 'HTTP/1.1', []);
        $this->assertSame("TRACE /x HTTP/1.1\r\n\r\n", $body);
    }

    public function testTraceEchoIncludesHeaders(): void
    {
        $body = ResponseMiddleware::buildTraceEcho('TRACE', '/x', 'HTTP/1.1', [
            'host'       => 'example.com',
            'user-agent' => 'curl/8.0',
        ]);
        $this->assertSame(
            "TRACE /x HTTP/1.1\r\nhost: example.com\r\nuser-agent: curl/8.0\r\n\r\n",
            $body
        );
    }

    /**
     * A header value carrying CR/LF must not break out into extra wire lines —
     * the echo strips them so TRACE can't be used to forge response framing.
     */
    public function testTraceEchoStripsCrlfFromHeaderValues(): void
    {
        $body = ResponseMiddleware::buildTraceEcho('TRACE', '/x', 'HTTP/1.1', [
            'x-evil' => "a\r\nInjected: 1",
        ]);
        // The CR/LF is folded out, so "Injected: 1" stays inside the x-evil
        // value — it never becomes its own wire line (no "...\r\nInjected: 1").
        $this->assertStringNotContainsString("\r\nInjected: 1", $body);
        $this->assertSame("TRACE /x HTTP/1.1\r\nx-evil: aInjected: 1\r\n\r\n", $body);
    }

    public function testTraceEchoStripsCrlfFromHeaderNames(): void
    {
        $body = ResponseMiddleware::buildTraceEcho('TRACE', '/x', 'HTTP/1.1', [
            "x-a\r\nx-b" => 'v',
        ]);
        $this->assertSame("TRACE /x HTTP/1.1\r\nx-ax-b: v\r\n\r\n", $body);
    }

    public function testTraceEchoPreservesProtocolVersion(): void
    {
        $body = ResponseMiddleware::buildTraceEcho('TRACE', '/x', 'HTTP/1.0', []);
        $this->assertStringStartsWith("TRACE /x HTTP/1.0\r\n", $body);
    }
}

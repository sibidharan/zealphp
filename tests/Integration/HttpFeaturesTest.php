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
}

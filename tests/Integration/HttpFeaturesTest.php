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
}

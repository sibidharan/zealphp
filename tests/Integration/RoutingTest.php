<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration tests for route matching and parameter injection.
 * Requires a running ZealPHP server (php app.php) on TEST_SERVER_URL.
 *
 * The demo endpoints at /demo/inject/* exercise every injection case.
 */
class RoutingTest extends TestCase
{
    // --- Parameter injection cases ---

    public function testUrlParamOnly(): void
    {
        $r = $this->get('/demo/inject/url/42');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame('42', $j['id']);
    }

    public function testUrlPlusRequest(): void
    {
        $r = $this->get('/demo/inject/url-request/99');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame('99', $j['id']);
        $this->assertArrayHasKey('method', $j);
        $this->assertSame('GET', $j['method']);
    }

    public function testUrlPlusResponse(): void
    {
        $r = $this->get('/demo/inject/url-response/7');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame('7', $j['id']);
        $this->assertArrayHasKey('response_class', $j);
    }

    public function testRequestOnly(): void
    {
        $r = $this->get('/demo/inject/request-only');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame('GET', $j['method']);
    }

    public function testAllParamsInjected(): void
    {
        $r = $this->get('/demo/inject/all/123');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame('123', $j['id']);
        $this->assertSame('GET', $j['method']);
        $this->assertArrayHasKey('response_class', $j);
    }

    public function testDefaultParamUsed(): void
    {
        $r = $this->get('/demo/inject/defaults/abc');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame(1, $j['page']); // default = 1
    }

    public function testDefaultParamOverridden(): void
    {
        $r = $this->get('/demo/inject/defaults/abc/5');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame('5', $j['page']);
    }

    // --- Route types ---

    public function testNsRoute(): void
    {
        $r = $this->get('/demo/route/ns/items');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertArrayHasKey('route_type', $j);
        $this->assertSame('nsRoute', $j['route_type']);
    }

    public function testNsPathRoute(): void
    {
        $r = $this->get('/demo/route/ns-path/api/v1/users/list');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame('nsPathRoute', $j['route_type']);
        $this->assertStringContainsString('api/v1/users/list', $j['captured']);
    }

    public function testPatternRoute(): void
    {
        $r = $this->get('/demo/route/pattern');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame('patternRoute', $j['route_type']);
    }

    // --- HTTP methods ---

    public function testPostMethodAccepted(): void
    {
        $r = $this->post('/demo/inject/url/1');
        // route registered for GET only — should 404
        // (method not allowed results in 404 with ZealPHP currently)
        $this->assertContains($r['status'], [404, 405]);
    }

    public function test404ForUnknownRoute(): void
    {
        $r = $this->get('/this/does/not/exist/xyz123');
        $this->assertStatus(404, $r);
    }

    public function testReturnJsonObject(): void
    {
        $r = $this->get('/demo/response/json');
        $this->assertStatus(200, $r);
        $this->assertHeader('content-type', 'application/json', $r);
        $j = $this->assertJsonResponse($r);
        $this->assertArrayHasKey('framework', $j);
    }

    public function testMultipleParamRouteSegments(): void
    {
        $r = $this->get('/demo/inject/all/hello');
        $this->assertStatus(200, $r);
        $j = $this->assertJsonResponse($r);
        $this->assertSame('hello', $j['id']);
    }
}

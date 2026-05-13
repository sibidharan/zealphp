<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration tests for middleware: CORS, ETag, Compression.
 */
class MiddlewareTest extends TestCase
{
    public function testCorsPreflightReturns204(): void
    {
        $r = $this->http('OPTIONS', '/http/cors-data', [
            'Origin'                         => 'http://frontend.test',
            'Access-Control-Request-Method'  => 'POST',
        ]);
        $this->assertStatus(204, $r);
    }

    public function testCorsPreflightHasAllowHeaders(): void
    {
        $r = $this->http('OPTIONS', '/http/cors-data', [
            'Origin'                         => 'http://frontend.test',
            'Access-Control-Request-Method'  => 'POST',
        ]);
        $this->assertHeader('access-control-allow-methods', 'GET', $r);
    }

    public function testCorsRegularResponseHasOriginHeader(): void
    {
        $r = $this->get('/http/cors-data', ['Origin' => 'http://app.test']);
        $this->assertStatus(200, $r);
        $this->assertHeader('access-control-allow-origin', '*', $r);
    }

    public function testETagPresentOnGet(): void
    {
        $r = $this->get('/http/etag-test');
        $this->assertStatus(200, $r);
        $this->assertArrayHasKey('etag', $r['headers']);
        $this->assertStringStartsWith('W/"', $r['headers']['etag']);
    }

    public function testETag304OnMatch(): void
    {
        $first  = $this->get('/http/etag-test');
        $etag   = $first['headers']['etag'];
        $second = $this->get('/http/etag-test', ['If-None-Match' => $etag]);
        $this->assertStatus(304, $second);
        $this->assertSame('', $second['body']);
    }

    public function testCompressionGzipHeader(): void
    {
        $r = $this->get('/http/compress-test', ['Accept-Encoding' => 'gzip']);
        $this->assertStatus(200, $r);
        $this->assertHeader('content-encoding', 'gzip', $r);
    }

    public function testCompressionAppliedToLargeBody(): void
    {
        // /http/compress-test returns ~4KB — should be gzip-compressed
        $r = $this->get('/http/compress-test', ['Accept-Encoding' => 'gzip']);
        $this->assertStatus(200, $r);
        $this->assertHeader('content-encoding', 'gzip', $r);
        $decoded = gzdecode($r['body']);
        $this->assertIsString($decoded);
        $this->assertStringContainsString('<html>', $decoded);
    }

    public function testNonCorsOptionsNoOrigin(): void
    {
        $r = $this->http('OPTIONS', '/http/options-test');
        $this->assertStatus(204, $r);
        $this->assertHeader('allow', 'GET', $r);
    }
}

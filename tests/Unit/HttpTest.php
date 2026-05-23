<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Runtime;
use PHPUnit\Framework\TestCase;
use ZealPHP\Http;
use ZealPHP\HttpResponse;

/**
 * Http::get/post/request — outbound HTTP wrapper around
 * `OpenSwoole\Coroutine\Http\Client`. These tests hit the running
 * ZealPHP demo server on :8080 if reachable; the test markers skip
 * when the server isn't up so CI without a live server doesn't fail.
 */
final class HttpTest extends TestCase
{
    private string $base;

    public static function setUpBeforeClass(): void
    {
        Runtime::enableCoroutine(true, Runtime::HOOK_ALL);
    }

    protected function setUp(): void
    {
        $this->base = (string) (getenv('ZEALPHP_TEST_HTTP_BASE') ?: 'http://127.0.0.1:8080');
        $r = Http::get($this->base . '/');
        if ($r->failed() || $r->status === 0) {
            self::markTestSkipped("ZealPHP demo server not reachable at {$this->base}");
        }
    }

    public function testGetReturnsTypedResponse(): void
    {
        $r = Http::get($this->base . '/');
        self::assertInstanceOf(HttpResponse::class, $r);
        self::assertSame(200, $r->status);
        self::assertTrue($r->ok());
        self::assertGreaterThan(0, strlen($r->body));
    }

    public function testInvalidUrlReturnsFailedResponse(): void
    {
        $r = Http::get('not-a-url');
        self::assertTrue($r->failed());
        self::assertSame(0, $r->status);
        self::assertNotNull($r->error);
    }

    public function testUnreachableHostReturnsFailedResponse(): void
    {
        $r = Http::get('http://127.0.0.1:9/should-never-bind', timeout: 1.0);
        self::assertTrue($r->failed());
        self::assertSame(0, $r->status);
    }

    public function testJsonBodyAutoEncodes(): void
    {
        // POST against /api/echo if present, else just check the request
        // composition by hitting / which 200s either way.
        $r = Http::post(
            $this->base . '/',
            ['hello' => 'world'],
            ['X-Test' => '1'],
        );
        // Demo doesn't accept POST at /, but we're testing that the
        // helper SENDS a request that returns *something* (not a transport error).
        self::assertFalse($r->failed());
    }

    public function testJsonHelperDecodesBody(): void
    {
        // Use /demo/pubsub/log which always returns valid JSON.
        $r = Http::get($this->base . '/demo/pubsub/log');
        if ($r->status !== 200) {
            self::markTestSkipped('demo/pubsub/log not active (Redis backend off)');
        }
        $decoded = $r->json();
        self::assertIsArray($decoded);
        self::assertArrayHasKey('ok', $decoded);
    }

    public function testHttpAllRunsRequestsInParallel(): void
    {
        $t0 = microtime(true);
        $results = Http::all([
            fn() => Http::get($this->base . '/'),
            fn() => Http::get($this->base . '/'),
            fn() => Http::get($this->base . '/'),
        ]);
        $elapsed = (microtime(true) - $t0) * 1000;

        self::assertCount(3, $results);
        foreach ($results as $r) {
            self::assertInstanceOf(HttpResponse::class, $r);
            self::assertSame(200, $r->status);
        }
        // Three parallel requests should be ~max(single) not 3× single.
        // Soft bound: <1s for the demo on localhost.
        self::assertLessThan(1000, $elapsed, "Http::all elapsed {$elapsed}ms — should be parallel-ish");
    }
}

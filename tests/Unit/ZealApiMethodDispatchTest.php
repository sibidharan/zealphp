<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use Psr\Http\Message\ResponseInterface;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * Tests for per-method dispatch in ZealAPI::processApi() — the Next.js
 * App Router-style fallback where $get/$post/$put/$delete/$patch closures
 * in an API file map to HTTP methods, with auto 405, auto HEAD from GET,
 * and unreachable-method warnings when both conventions coexist.
 */
class ZealApiMethodDispatchTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $g = G::instance();
        $g->server = ['REQUEST_METHOD' => 'GET'];
        $g->get = [];
        $g->post = [];
        $g->_streaming = null;
        $g->status = null;
        $g->zealphp_request = new \stdClass();
        $g->zealphp_response = new class {
            /** @var array<string, mixed> */
            public array $headers = [];
            public int $status = 200;

            public function header(string $name, mixed $value, bool $ucwords = true): void
            {
                $this->headers[$name] = $value;
            }

            public function status(int $status): void
            {
                $this->status = $status;
            }
        };

        $this->tmpRoot = sys_get_temp_dir() . '/zealphp_api_method_' . bin2hex(random_bytes(6));
        $apiDir = $this->tmpRoot . '/api';
        @mkdir($apiDir . '/resource', 0777, true);

        // Filename-match handler (BC) — all methods reach $list
        file_put_contents(
            $apiDir . '/resource/list.php',
            '<?php $list = function() { return ["mode" => "filename", "method" => $this->get_request_method()]; };'
        );

        // Method-based: only $get and $post defined
        file_put_contents(
            $apiDir . '/resource/users.php',
            '<?php
$get = function() { return ["mode" => "method", "handler" => "get"]; };
$post = function() { return ["mode" => "method", "handler" => "post"]; };'
        );

        // Method-based: only $get (for HEAD auto-derive + 405 testing)
        file_put_contents(
            $apiDir . '/resource/readonly.php',
            '<?php $get = function() { return ["handler" => "get", "data" => "read-only"]; };'
        );

        // Both conventions: $items (filename match) + $get/$post (unreachable)
        file_put_contents(
            $apiDir . '/resource/items.php',
            '<?php
$items = function() { return ["mode" => "filename", "handler" => "items"]; };
$get = function() { return ["mode" => "method", "handler" => "get"]; };
$post = function() { return ["mode" => "method", "handler" => "post"]; };'
        );

        // Method-based with PATCH
        file_put_contents(
            $apiDir . '/resource/patchable.php',
            '<?php
$get = function() { return ["handler" => "get"]; };
$patch = function() { return ["handler" => "patch"]; };'
        );

        // Variable name mismatch — neither filename match nor method handlers
        file_put_contents(
            $apiDir . '/resource/mismatch.php',
            '<?php $wrongname = function() { return ["x" => 1]; };'
        );

        // All five methods defined
        file_put_contents(
            $apiDir . '/resource/full.php',
            '<?php
$get = function() { return ["handler" => "get"]; };
$post = function() { return ["handler" => "post"]; };
$put = function() { return ["handler" => "put"]; };
$delete = function() { return ["handler" => "delete"]; };
$patch = function() { return ["handler" => "patch"]; };'
        );
    }

    protected function tearDown(): void
    {
        $g = G::instance();
        $g->zealphp_request = null;
        $g->zealphp_response = null;
        $g->_streaming = null;
        $g->status = null;
        $g->server = [];
        $g->get = [];
        $g->post = [];

        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private function makeApi(): ZealAPI
    {
        return new ZealAPI(null, null, $this->tmpRoot);
    }

    /** @return array{return: mixed, echo: string} */
    private function dispatch(ZealAPI $api, string $module, ?string $request): array
    {
        $depth = ob_get_level();
        ob_start();
        $ret = $api->processApi($module, $request);
        $echo = (string) ob_get_clean();
        while (ob_get_level() > $depth) {
            ob_end_clean();
        }
        return ['return' => $ret, 'echo' => $echo];
    }

    private function setMethod(string $method): void
    {
        G::instance()->server['REQUEST_METHOD'] = $method;
    }

    // ── BC: filename match still works ──────────────────────────────

    public function testFilenameMatchAcceptsGetRequest(): void
    {
        $this->setMethod('GET');
        $r = $this->dispatch($this->makeApi(), 'resource', 'list');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertSame('filename', $body['mode']);
        $this->assertSame('GET', $body['method']);
    }

    public function testFilenameMatchAcceptsPostRequest(): void
    {
        $this->setMethod('POST');
        $r = $this->dispatch($this->makeApi(), 'resource', 'list');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertSame('filename', $body['mode']);
        $this->assertSame('POST', $body['method']);
    }

    // ── Method-based dispatch ───────────────────────────────────────

    public function testMethodDispatchRoutesGetToGetHandler(): void
    {
        $this->setMethod('GET');
        $r = $this->dispatch($this->makeApi(), 'resource', 'users');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertSame('method', $body['mode']);
        $this->assertSame('get', $body['handler']);
    }

    public function testMethodDispatchRoutesPostToPostHandler(): void
    {
        $this->setMethod('POST');
        $r = $this->dispatch($this->makeApi(), 'resource', 'users');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertSame('method', $body['mode']);
        $this->assertSame('post', $body['handler']);
    }

    public function testMethodDispatchRoutesPatchHandler(): void
    {
        $this->setMethod('PATCH');
        $r = $this->dispatch($this->makeApi(), 'resource', 'patchable');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertSame('patch', $body['handler']);
    }

    public function testMethodDispatchAllFiveMethods(): void
    {
        foreach (['GET' => 'get', 'POST' => 'post', 'PUT' => 'put', 'DELETE' => 'delete', 'PATCH' => 'patch'] as $method => $expected) {
            $this->setMethod($method);
            $r = $this->dispatch($this->makeApi(), 'resource', 'full');
            $this->assertInstanceOf(ResponseInterface::class, $r['return'], "Failed for $method");
            $body = json_decode((string) $r['return']->getBody(), true);
            $this->assertSame($expected, $body['handler'], "Wrong handler for $method");
        }
    }

    // ── 405 Method Not Allowed ──────────────────────────────────────

    public function testUndefinedMethodReturns405(): void
    {
        $this->setMethod('DELETE');
        $r = $this->dispatch($this->makeApi(), 'resource', 'readonly');
        $this->assertNull($r['return']);
        $decoded = json_decode($r['echo'], true);
        $this->assertSame('method_not_allowed', $decoded['error']);
        $this->assertContains('GET', $decoded['allowed']);
        $this->assertContains('HEAD', $decoded['allowed']);
        $this->assertContains('OPTIONS', $decoded['allowed']);
        $this->assertNotContains('DELETE', $decoded['allowed']);
    }

    public function testPostOn405WhenOnlyGetDefined(): void
    {
        $this->setMethod('POST');
        $r = $this->dispatch($this->makeApi(), 'resource', 'readonly');
        $this->assertNull($r['return']);
        $decoded = json_decode($r['echo'], true);
        $this->assertSame('method_not_allowed', $decoded['error']);
    }

    public function testPutReturns405WhenOnlyGetAndPostDefined(): void
    {
        $this->setMethod('PUT');
        $r = $this->dispatch($this->makeApi(), 'resource', 'users');
        $this->assertNull($r['return']);
        $decoded = json_decode($r['echo'], true);
        $this->assertSame('method_not_allowed', $decoded['error']);
        $this->assertContains('GET', $decoded['allowed']);
        $this->assertContains('POST', $decoded['allowed']);
    }

    // ── Auto HEAD from GET ──────────────────────────────────────────

    public function testHeadAutoDerivesFromGetHandler(): void
    {
        $this->setMethod('HEAD');
        $r = $this->dispatch($this->makeApi(), 'resource', 'readonly');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertSame('get', $body['handler']);
    }

    public function testHeadIncludedInAllowHeader(): void
    {
        $this->setMethod('DELETE');
        $r = $this->dispatch($this->makeApi(), 'resource', 'readonly');
        $decoded = json_decode($r['echo'], true);
        $this->assertContains('HEAD', $decoded['allowed']);
    }

    // ── Filename match priority + unreachable warning ───────────────

    public function testFilenameMatchWinsOverMethodHandlers(): void
    {
        $this->setMethod('GET');
        $r = $this->dispatch($this->makeApi(), 'resource', 'items');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertSame('filename', $body['mode']);
        $this->assertSame('items', $body['handler']);
    }

    public function testFilenameMatchWinsForPostToo(): void
    {
        $this->setMethod('POST');
        $r = $this->dispatch($this->makeApi(), 'resource', 'items');
        $this->assertInstanceOf(ResponseInterface::class, $r['return']);
        $body = json_decode((string) $r['return']->getBody(), true);
        $this->assertSame('filename', $body['mode']);
        $this->assertSame('items', $body['handler']);
    }

    // ── Allow header correctness ────────────────────────────────────

    public function testAllowHeaderIncludesOptionsAlways(): void
    {
        $this->setMethod('DELETE');
        $r = $this->dispatch($this->makeApi(), 'resource', 'users');
        $decoded = json_decode($r['echo'], true);
        $this->assertContains('OPTIONS', $decoded['allowed']);
    }

    // ── Handler not found — helpful error ─────────────────────────

    public function testMismatchedVariableReturnsHandlerNotFound(): void
    {
        $r = $this->dispatch($this->makeApi(), 'resource', 'mismatch');
        $this->assertNull($r['return']);
        $decoded = json_decode($r['echo'], true);
        $this->assertSame('handler_not_found', $decoded['error']);
        $this->assertArrayHasKey('hint', $decoded);
        $this->assertStringContainsString('$mismatch', $decoded['hint']);
        $this->assertStringContainsString('$get', $decoded['hint']);
    }

    public function testAllowHeaderOmitsHeadWhenNoGet(): void
    {
        // Create a file with only $post
        $apiDir = $this->tmpRoot . '/api/resource';
        file_put_contents(
            $apiDir . '/postonly.php',
            '<?php $post = function() { return ["handler" => "post"]; };'
        );

        $this->setMethod('GET');
        $r = $this->dispatch($this->makeApi(), 'resource', 'postonly');
        $decoded = json_decode($r['echo'], true);
        $this->assertNotContains('HEAD', $decoded['allowed']);
        $this->assertContains('POST', $decoded['allowed']);
    }
}

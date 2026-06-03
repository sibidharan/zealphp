<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration tests for App::when() path-scoped middleware + the api in-file
 * `$middleware` convention (route/middleware.php + api/ fixtures). Requires the
 * demo server on :8080.
 */
class WhenMiddlewareTest extends TestCase
{
    public function testWhenStampsHeaderOnApiNamespace(): void
    {
        // App::when('/api/secured', ['api-secured']) → X-Api-Secured on every
        // api/secured/* endpoint, with no per-file glue.
        $r = $this->get('/api/secured/list');
        $this->assertStatus(200, $r);
        $this->assertHeader('x-api-secured', '1', $r);
    }

    public function testWhenIsPathScopedAcrossNamespaces(): void
    {
        // A sibling api namespace with no when() scope must NOT carry the header.
        $r = $this->get('/api/open/list');
        $this->assertStatus(200, $r);
        $this->assertArrayNotHasKey('x-api-secured', $r['headers']);
    }

    public function testInFileMiddlewareRunsInnermost(): void
    {
        // api/secured/profile.php declares `$middleware = ['request-id']` — it
        // runs inside the when('/api/secured') scope: X-Api-Secured (when) AND
        // X-Request-Id (in-file), and the handler reads the id from the memo.
        $r = $this->get('/api/secured/profile');
        $this->assertStatus(200, $r);
        $this->assertHeader('x-api-secured', '1', $r);
        $this->assertArrayHasKey('x-request-id', $r['headers']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $r['headers']['x-request-id']);

        $body = json_decode($r['body'], true);
        $this->assertIsArray($body);
        $this->assertSame($r['headers']['x-request-id'], $body['request_id']);
    }

    public function testWhenGuardShortCircuits(): void
    {
        // App::when('/api/blocked', ['block']) → ReturnMiddleware(403); handler never runs.
        $r = $this->get('/api/blocked/secret');
        $this->assertStatus(403, $r);
        $this->assertStringNotContainsString('never see this body', $r['body']);
    }

    public function testWhenAlsoScopesNonApiRoutes(): void
    {
        // when() is not api-only — /demo/scoped/* is a normal route under a scope.
        $r = $this->get('/demo/scoped/test');
        $this->assertStatus(200, $r);
        $this->assertHeader('x-demo-route', 'route-level', $r);
    }

    public function testPreflightIsNotGatedByWhen(): void
    {
        // CORS preflight must be answered before when() auth/guards run.
        $r = $this->http('OPTIONS', '/api/secured/list', [
            'Origin'                        => 'http://app.test',
            'Access-Control-Request-Method' => 'GET',
        ]);
        $this->assertSame(204, $r['status']);
    }

    public function testVisualizerReportsWhenScopes(): void
    {
        $r = $this->get('/demo/middleware/visualize');
        $this->assertStatus(200, $r);
        $desc = json_decode($r['body'], true);
        $this->assertIsArray($desc);
        $this->assertArrayHasKey('when', $desc);

        $scopes = array_column($desc['when'], 'scope');
        $this->assertContains('/api/secured', $scopes);
        $this->assertContains('/api/blocked', $scopes);
    }
}

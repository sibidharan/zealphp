<?php
namespace ZealPHP\Tests\Integration;

use ZealPHP\Tests\TestCase;

/**
 * Integration tests for per-route middleware (route/middleware.php demo).
 * Requires the demo server running on :8080.
 */
class RouteMiddlewareTest extends TestCase
{
    public function testRouteLevelMiddlewareStampsHeaders(): void
    {
        $r = $this->get('/demo/middleware/route-level');
        $this->assertStatus(200, $r);
        // RequestIdMiddleware → 32-hex id; HeaderMiddleware → X-Demo-Route.
        $this->assertArrayHasKey('x-request-id', $r['headers']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $r['headers']['x-request-id']);
        $this->assertHeader('x-demo-route', 'route-level', $r);
    }

    public function testHandlerCanReadRequestIdFromMemo(): void
    {
        $r = $this->get('/demo/middleware/route-level');
        $body = json_decode($r['body'], true);
        $this->assertIsArray($body);
        // The handler echoed the id it read from RequestContext::once('request_id').
        $this->assertSame($r['headers']['x-request-id'], $body['request_id']);
    }

    public function testMiddlewareIsRouteScoped(): void
    {
        // A sibling route with no middleware must NOT carry the route-level header.
        $r = $this->get('/demo/middleware/plain');
        $this->assertStatus(200, $r);
        $this->assertArrayNotHasKey('x-demo-route', $r['headers']);
    }

    public function testGuardMiddlewareShortCircuits(): void
    {
        $r = $this->get('/demo/middleware/blocked');
        $this->assertStatus(403, $r);
        $this->assertStringContainsString('Blocked by route-level middleware', $r['body']);
        // The handler never ran — its body must be absent.
        $this->assertStringNotContainsString('never see this body', $r['body']);
    }

    public function testGroupSharedMiddlewareAppliesToEveryRoute(): void
    {
        foreach (['alpha', 'beta'] as $leaf) {
            $r = $this->get("/demo/mwgroup/$leaf");
            $this->assertStatus(200, $r);
            $this->assertHeader('x-demo-group', 'mwgroup', $r);
        }
    }

    public function testRequestIdTrustsInboundHeader(): void
    {
        $r = $this->get('/demo/middleware/route-level', ['X-Request-Id' => 'upstream-corr-id']);
        $this->assertHeader('x-request-id', 'upstream-corr-id', $r);
    }

    public function testVisualizerReportsTopology(): void
    {
        $r = $this->get('/demo/middleware/visualize');
        $this->assertStatus(200, $r);
        $desc = json_decode($r['body'], true);
        $this->assertIsArray($desc);

        // Global chain ends with the router; aliases list the demo aliases.
        $this->assertSame('ResponseMiddleware (router)', end($desc['global']));
        $this->assertContains('request-id', $desc['aliases']);

        // The route-level route reports its resolved middleware chain.
        $found = null;
        foreach ($desc['routes'] as $route) {
            if ($route['path'] === '/demo/middleware/route-level') {
                $found = $route;
                break;
            }
        }
        $this->assertNotNull($found);
        // Resolved to instances at boot → class short-names.
        $this->assertSame(['RequestIdMiddleware', 'HeaderMiddleware'], $found['middleware']);
    }

    public function testExactlyOneRequestIdHeaderOnTheWire(): void
    {
        // RequestIdMiddleware writes the id to both the live OpenSwoole response
        // and the PSR-7 response (->withHeader, replace). Pin that the emit path
        // does NOT duplicate the header — exactly one X-Request-Id on the wire.
        $ch = curl_init(self::$baseUrl . '/demo/middleware/route-level');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $raw   = (string) curl_exec($ch);
        $hSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerRaw = substr($raw, 0, $hSize);
        $count = preg_match_all('/^x-request-id:/im', $headerRaw);
        $this->assertSame(1, $count, 'Exactly one X-Request-Id header must be on the wire');
    }
}

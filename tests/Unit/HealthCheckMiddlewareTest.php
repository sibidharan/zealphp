<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ZealPHP\Middleware\HealthCheckMiddleware;
use OpenSwoole\Core\Psr\ServerRequest;
use OpenSwoole\Core\Psr\Response;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class HealthCheckMiddlewareTest extends TestCase
{
    private RequestHandlerInterface $handler;

    protected function setUp(): void
    {
        $this->handler = new class implements RequestHandlerInterface {
            public bool $called = false;
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->called = true;
                return new Response('app response', 200);
            }
        };
    }

    public function testHealthzReturnsJson(): void
    {
        $mw = new HealthCheckMiddleware();
        $request = new ServerRequest('/healthz', 'GET');
        $response = $mw->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($this->handler->called);

        $body = (string) $response->getBody();
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertSame('ok', $data['status']);
        $this->assertArrayHasKey('uptime_sec', $data);
        $this->assertArrayHasKey('memory', $data);
    }

    public function testNonHealthPathPassesThrough(): void
    {
        $mw = new HealthCheckMiddleware();
        $request = new ServerRequest('/api/users', 'GET');
        $response = $mw->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($this->handler->called);
    }

    public function testCustomPathWorks(): void
    {
        $mw = new HealthCheckMiddleware(paths: ['/readyz', '/_health']);
        $request = new ServerRequest('/_health', 'GET');
        $response = $mw->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($this->handler->called);

        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('ok', $data['status']);
    }

    public function testCustomCheckHealthy(): void
    {
        $mw = new HealthCheckMiddleware(check: fn(): ?string => null);
        $request = new ServerRequest('/healthz', 'GET');
        $response = $mw->process($request, $this->handler);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('ok', $data['status']);
    }

    public function testCustomCheckUnhealthy(): void
    {
        $mw = new HealthCheckMiddleware(check: fn(): ?string => 'redis unreachable');
        $request = new ServerRequest('/healthz', 'GET');
        $response = $mw->process($request, $this->handler);

        $this->assertSame(503, $response->getStatusCode());
        $data = json_decode((string) $response->getBody(), true);
        $this->assertSame('unhealthy', $data['status']);
        $this->assertSame('redis unreachable', $data['reason']);
    }

    public function testCustomCheckThatThrowsIsTreatedAsUnhealthy(): void
    {
        // A readiness probe that THROWS (e.g. a DB/Redis ping that raises instead
        // of returning an error string) must surface as 503, not a 500 (#309).
        $mw = new HealthCheckMiddleware(check: function (): ?string {
            throw new \RuntimeException('db connection refused');
        });
        $request = new ServerRequest('/healthz', 'GET');
        $response = $mw->process($request, $this->handler);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($this->handler->called);
        $data = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($data);
        $this->assertSame('unhealthy', $data['status']);
        $this->assertStringContainsString('db connection refused', $data['reason']);
    }

    public function testDefaultPathDoesNotMatchSubpaths(): void
    {
        $mw = new HealthCheckMiddleware();
        $request = new ServerRequest('/healthz/deep', 'GET');
        $response = $mw->process($request, $this->handler);

        $this->assertTrue($this->handler->called);
    }
}

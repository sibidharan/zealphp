<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\ScopedMiddleware;
use ZealPHP\Tests\TestCase;

class ScopedMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    public function testDefaultConstructorTreatsPatternAsLiteralPrefixNotRegex(): void
    {
        // Pattern '/admin' is NOT a valid PCRE delimiter pattern; if the
        // default $regex flag were `true` (the mutant) preg_match() would
        // error/return false and the inner middleware would never run.
        // As a literal prefix it must match '/admin/secret'.
        $inner = $this->blockingInner();
        $mw = new ScopedMiddleware($inner, '/admin');

        $response = $this->process($mw, '/admin/secret');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked', (string) $response->getBody());
    }

    public function testLocationInScopeRunsInner(): void
    {
        $mw = ScopedMiddleware::location($this->blockingInner(), '/admin');
        $response = $this->process($mw, '/admin');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testLocationOutOfScopePassesThrough(): void
    {
        $mw = ScopedMiddleware::location($this->blockingInner(), '/admin');
        $response = $this->process($mw, '/public');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string) $response->getBody());
    }

    public function testMatchInScopeRunsInner(): void
    {
        $mw = ScopedMiddleware::match($this->blockingInner(), '#^/api/#');
        $response = $this->process($mw, '/api/users');
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMatchOutOfScopePassesThrough(): void
    {
        $mw = ScopedMiddleware::match($this->blockingInner(), '#^/api/#');
        $response = $this->process($mw, '/web/users');
        $this->assertSame(200, $response->getStatusCode());
    }

    private function process(ScopedMiddleware $mw, string $path): ResponseInterface
    {
        $request = new ServerRequest($path, 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }

    private function blockingInner(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response('blocked', 403, '', ['Content-Type' => 'text/plain']);
            }
        };
    }
}

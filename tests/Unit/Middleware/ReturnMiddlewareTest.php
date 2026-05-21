<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\ReturnMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;
use ZealPHP\Tests\TestCase;

class ReturnMiddlewareTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('handler-ran', 200);
            }
        };
    }

    public function testStatusOnly(): void
    {
        $res = (new ReturnMiddleware(403))->process(new ServerRequest('/x', 'GET'), $this->handler());
        $this->assertSame(403, $res->getStatusCode());
        $this->assertSame('', (string) $res->getBody());
    }

    public function testRedirect(): void
    {
        $res = (new ReturnMiddleware(301, '/new'))->process(new ServerRequest('/old', 'GET'), $this->handler());
        $this->assertSame(301, $res->getStatusCode());
        $this->assertSame('/new', $res->getHeaderLine('Location'));
    }

    public function testFixedBody(): void
    {
        $res = (new ReturnMiddleware(200, 'pong'))->process(new ServerRequest('/ping', 'GET'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('pong', (string) $res->getBody());
    }

    public function testAlwaysShortCircuitsHandler(): void
    {
        $res = (new ReturnMiddleware(204))->process(new ServerRequest('/anything', 'GET'), $this->handler());
        $this->assertSame(204, $res->getStatusCode());
        $this->assertNotSame('handler-ran', (string) $res->getBody());
    }

    public function testComposesWithScopedMiddleware(): void
    {
        // nginx `location /blocked { return 403; }`
        $scoped = ScopedMiddleware::location(new ReturnMiddleware(403), '/blocked');

        $inScope = $scoped->process(new ServerRequest('/blocked/x', 'GET'), $this->handler());
        $this->assertSame(403, $inScope->getStatusCode());

        $outScope = $scoped->process(new ServerRequest('/public', 'GET'), $this->handler());
        $this->assertSame(200, $outScope->getStatusCode());
        $this->assertSame('handler-ran', (string) $outScope->getBody());
    }
}

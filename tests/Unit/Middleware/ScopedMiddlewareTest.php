<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\ScopedMiddleware;
use ZealPHP\Tests\TestCase;

class ScopedMiddlewareTest extends TestCase
{
    /** Inner middleware that tags the response so we can see whether it ran. */
    private function taggingInner(): MiddlewareInterface
    {
        return new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request)->withHeader('X-Inner', 'ran');
            }
        };
    }

    private function passthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('handler', 200);
            }
        };
    }

    public function testLocationPrefixMatchRunsInner(): void
    {
        $mw  = ScopedMiddleware::location($this->taggingInner(), '/admin');
        $res = $mw->process(new ServerRequest('/admin/users', 'GET'), $this->passthroughHandler());
        $this->assertSame('ran', $res->getHeaderLine('X-Inner'));
    }

    public function testLocationPrefixNonMatchSkipsInner(): void
    {
        $mw  = ScopedMiddleware::location($this->taggingInner(), '/admin');
        $res = $mw->process(new ServerRequest('/public/page', 'GET'), $this->passthroughHandler());
        $this->assertSame('', $res->getHeaderLine('X-Inner'));
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testLocationMatchRegexRunsInner(): void
    {
        $mw  = ScopedMiddleware::match($this->taggingInner(), '#\.php$#');
        $res = $mw->process(new ServerRequest('/legacy/index.php', 'GET'), $this->passthroughHandler());
        $this->assertSame('ran', $res->getHeaderLine('X-Inner'));
    }

    public function testRegexNonMatchSkipsInner(): void
    {
        $mw  = ScopedMiddleware::match($this->taggingInner(), '#\.php$#');
        $res = $mw->process(new ServerRequest('/page.html', 'GET'), $this->passthroughHandler());
        $this->assertSame('', $res->getHeaderLine('X-Inner'));
    }

    public function testInnerCanShortCircuitWithinScope(): void
    {
        // An inner middleware that denies (403) only fires inside the scope.
        $deny = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response('Forbidden', 403);
            }
        };
        $scoped = ScopedMiddleware::location($deny, '/private');

        $denied = $scoped->process(new ServerRequest('/private/x', 'GET'), $this->passthroughHandler());
        $this->assertSame(403, $denied->getStatusCode());

        $allowed = $scoped->process(new ServerRequest('/open', 'GET'), $this->passthroughHandler());
        $this->assertSame(200, $allowed->getStatusCode());
    }
}

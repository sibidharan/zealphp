<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\BodySizeLimitMiddleware;
use ZealPHP\Tests\TestCase;

class BodySizeLimitMiddlewareTest extends TestCase
{
    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('handled', 200);
            }
        };
    }

    private function withLen(int $len): ServerRequestInterface
    {
        return (new ServerRequest('/upload', 'POST'))->withHeader('Content-Length', (string) $len);
    }

    public function testUnderLimitPassesThrough(): void
    {
        $res = (new BodySizeLimitMiddleware(1024))->process($this->withLen(500), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('handled', (string) $res->getBody());
    }

    public function testOverLimitReturns413(): void
    {
        $res = (new BodySizeLimitMiddleware(1024))->process($this->withLen(2048), $this->handler());
        $this->assertSame(413, $res->getStatusCode());
    }

    public function testExactLimitPasses(): void
    {
        $res = (new BodySizeLimitMiddleware(1024))->process($this->withLen(1024), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testHumanReadableSizeParsing(): void
    {
        $mw = new BodySizeLimitMiddleware('1k'); // 1024 bytes
        $this->assertSame(413, $mw->process($this->withLen(1025), $this->handler())->getStatusCode());
        $this->assertSame(200, $mw->process($this->withLen(1024), $this->handler())->getStatusCode());
    }

    public function testMegabyteParsing(): void
    {
        $mw = new BodySizeLimitMiddleware('2m'); // 2097152
        $this->assertSame(200, $mw->process($this->withLen(2_000_000), $this->handler())->getStatusCode());
        $this->assertSame(413, $mw->process($this->withLen(3_000_000), $this->handler())->getStatusCode());
    }

    public function testNoContentLengthPassesThrough(): void
    {
        $res = (new BodySizeLimitMiddleware(10))->process(new ServerRequest('/', 'GET'), $this->handler());
        $this->assertSame(200, $res->getStatusCode());
    }
}

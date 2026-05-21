<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\MergeSlashesMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class MergeSlashesMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::superglobals(false);
    }

    private function dispatchWithUri(string $uri): string
    {
        $g = RequestContext::instance();
        $g->server = ['REQUEST_URI' => $uri];
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ok', 200);
            }
        };
        (new MergeSlashesMiddleware())->process(new ServerRequest('/', 'GET'), $handler);
        return (string) (RequestContext::instance()->server['REQUEST_URI'] ?? '');
    }

    public function testCollapsesDuplicateSlashes(): void
    {
        $this->assertSame('/a/b/c', $this->dispatchWithUri('/a//b///c'));
    }

    public function testPreservesQueryString(): void
    {
        $this->assertSame('/a/b?x=1//2', $this->dispatchWithUri('/a//b?x=1//2'));
    }

    public function testLeavesCleanPathUntouched(): void
    {
        $this->assertSame('/already/clean', $this->dispatchWithUri('/already/clean'));
    }

    public function testCollapsesLeadingDoubleSlash(): void
    {
        $this->assertSame('/admin', $this->dispatchWithUri('//admin'));
    }
}

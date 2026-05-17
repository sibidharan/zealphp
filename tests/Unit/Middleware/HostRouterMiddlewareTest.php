<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\HostRouterMiddleware;
use ZealPHP\Tests\TestCase;

class HostRouterMiddlewareTest extends TestCase
{
    public function testDispatchesToMatchingHost(): void
    {
        $mw = new HostRouterMiddleware([
            'a.example' => fn() => 'A site',
            'b.example' => fn() => 'B site',
        ]);

        $response = $mw->process($this->req('a.example'), $this->failHandler());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('A site', (string)$response->getBody());
    }

    public function testStripsPortBeforeMatching(): void
    {
        $mw = new HostRouterMiddleware(['a.example' => fn() => 'A site']);

        $response = $mw->process($this->req('a.example:8080'), $this->failHandler());

        $this->assertSame('A site', (string)$response->getBody());
    }

    public function testCaseInsensitiveHostMatch(): void
    {
        $mw = new HostRouterMiddleware(['a.example' => fn() => 'A site']);

        $response = $mw->process($this->req('A.Example'), $this->failHandler());

        $this->assertSame('A site', (string)$response->getBody());
    }

    public function testFallsThroughWhenNoMatchAndNoCatchAll(): void
    {
        $mw = new HostRouterMiddleware(['a.example' => fn() => 'A site']);

        $response = $mw->process(
            $this->req('unknown.host'),
            $this->okHandler('fallthrough')
        );

        $this->assertSame('fallthrough', (string)$response->getBody());
    }

    public function testCatchAllDispatch(): void
    {
        $mw = new HostRouterMiddleware([
            'a.example' => fn() => 'A site',
            '*'         => fn() => 'default',
        ]);

        $response = $mw->process($this->req('whatever.com'), $this->failHandler());

        $this->assertSame('default', (string)$response->getBody());
    }

    public function testWildcardSubdomain(): void
    {
        $mw = new HostRouterMiddleware([
            '*.example.com' => fn() => 'subdomain',
        ]);

        $response = $mw->process($this->req('foo.example.com'), $this->failHandler());
        $this->assertSame('subdomain', (string)$response->getBody());

        // Bare host does NOT match the *.example.com rule
        $response = $mw->process($this->req('example.com'), $this->okHandler('bare'));
        $this->assertSame('bare', (string)$response->getBody());
    }

    public function testCoercesArrayReturnAsJson(): void
    {
        $mw = new HostRouterMiddleware([
            'api.example' => fn() => ['status' => 'ok'],
        ]);

        $response = $mw->process($this->req('api.example'), $this->failHandler());

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"status":"ok"}', (string)$response->getBody());
    }

    public function testCoercesIntReturnAsStatusCode(): void
    {
        $mw = new HostRouterMiddleware([
            'down.example' => fn() => 503,
        ]);

        $response = $mw->process($this->req('down.example'), $this->failHandler());

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testRejectsNonCallableHandler(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HostRouterMiddleware(['a.example' => 'not callable']);
    }

    private function req(string $host): ServerRequestInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        return (new ServerRequest('/', 'GET'))->withHeader('Host', $host);
    }

    private function okHandler(string $body): RequestHandlerInterface
    {
        return new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200, '', ['Content-Type' => 'text/plain']);
            }
        };
    }

    private function failHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \LogicException('handler must not be reached');
            }
        };
    }
}

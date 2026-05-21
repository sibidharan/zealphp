<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\ReturnMiddleware;
use ZealPHP\Tests\TestCase;

class ReturnMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    public function testStatus300WithUrlIsRedirect(): void
    {
        // Boundary: 300 is the lower edge of the 3xx redirect range.
        // Kills `>= 300` → `> 300` (mutant would emit body instead of Location).
        $response = $this->process(new ReturnMiddleware(300, '/new'));

        $this->assertSame(300, $response->getStatusCode());
        $this->assertSame('/new', $response->getHeaderLine('Location'));
        $this->assertSame('', (string) $response->getBody());
    }

    public function testStatus301WithUrlIsRedirect(): void
    {
        $response = $this->process(new ReturnMiddleware(301, '/moved'));

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/moved', $response->getHeaderLine('Location'));
    }

    public function testStatus400WithTextIsBodyNotRedirect(): void
    {
        // Boundary: 400 is just outside the 3xx range — must be a body, no Location.
        // Kills `< 400` → `<= 400` (mutant would treat 400 as redirect).
        $response = $this->process(new ReturnMiddleware(400, 'bad input'));

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('Location'));
        $this->assertSame('bad input', (string) $response->getBody());
    }

    public function testStatus403WithoutTextEmptyBody(): void
    {
        $response = $this->process(new ReturnMiddleware(403));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testStatus200WithBody(): void
    {
        $response = $this->process(new ReturnMiddleware(200, 'pong'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('pong', (string) $response->getBody());
        $this->assertSame('', $response->getHeaderLine('Location'));
    }

    private function process(ReturnMiddleware $mw): ResponseInterface
    {
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('handler must not run — ReturnMiddleware short-circuits');
            }
        };
        return $mw->process($request, $handler);
    }
}

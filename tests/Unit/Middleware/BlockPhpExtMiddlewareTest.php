<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Middleware\BlockPhpExtMiddleware;
use ZealPHP\Tests\TestCase;

class BlockPhpExtMiddlewareTest extends TestCase
{
    public function testBlocksDotPhpUrlWith404(): void
    {
        $response = (new BlockPhpExtMiddleware())->process(
            new ServerRequest('/admin.php', 'GET'),
            $this->okHandler()
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', (string)$response->getBody());
    }

    public function testBlocksDotPhpAtAnyDepth(): void
    {
        $response = (new BlockPhpExtMiddleware())->process(
            new ServerRequest('/admin/login.php', 'GET'),
            $this->okHandler()
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAllowsExtensionlessUrls(): void
    {
        $response = (new BlockPhpExtMiddleware())->process(
            new ServerRequest('/admin', 'GET'),
            $this->okHandler()
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string)$response->getBody());
    }

    public function testAllowsOtherExtensions(): void
    {
        $response = (new BlockPhpExtMiddleware())->process(
            new ServerRequest('/file.html', 'GET'),
            $this->okHandler()
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testCaseInsensitive(): void
    {
        $response = (new BlockPhpExtMiddleware())->process(
            new ServerRequest('/admin.PHP', 'GET'),
            $this->okHandler()
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ok', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
    }
}

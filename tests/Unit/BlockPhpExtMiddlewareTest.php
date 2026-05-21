<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\BlockPhpExtMiddleware;
use ZealPHP\Tests\TestCase;

class BlockPhpExtMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    public function testBlocksPhpExtensionWith404PlainText(): void
    {
        $response = $this->process('/index.php');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', (string) $response->getBody());
        // Kills ArrayItemRemoval — Content-Type header must be present.
        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testPassesThroughNonPhpPath(): void
    {
        $response = $this->process('/index');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string) $response->getBody());
    }

    private function process(string $path): ResponseInterface
    {
        $middleware = new BlockPhpExtMiddleware();
        $request = new ServerRequest($path, 'GET', '', []);

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };

        return $middleware->process($request, $handler);
    }
}

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

    public function testBlocksPhpWithPathInfoSuffix(): void
    {
        // PATH_INFO bypass (#184): `.php` followed by more path segments must
        // still be blocked, not just `.php` at the very end of the path.
        $this->assertSame(404, $this->process('/admin.php/foo')->getStatusCode());
        $this->assertSame(404, $this->process('/admin.php/')->getStatusCode());
        $this->assertSame(404, $this->process('/a/b.php/c/d')->getStatusCode());
    }

    public function testPassesPathsWithNoPhpSegment(): void
    {
        // Paths that merely CONTAIN "php" (no `.php` segment) pass through.
        $this->assertSame(200, $this->process('/phpinfo')->getStatusCode());
        $this->assertSame(200, $this->process('/api/graphql')->getStatusCode());
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

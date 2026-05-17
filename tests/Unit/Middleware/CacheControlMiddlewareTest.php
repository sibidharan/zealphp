<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\CacheControlMiddleware;
use ZealPHP\Tests\TestCase;

class CacheControlMiddlewareTest extends TestCase
{
    public function testStampsCacheControlOnCss(): void
    {
        $response = $this->invoke('/site.css');
        $this->assertSame('max-age=2628000, public', $response->getHeaderLine('Cache-Control'));
    }

    public function testStampsCacheControlOnImages(): void
    {
        $response = $this->invoke('/logo.png');
        $this->assertSame('max-age=2628000, public', $response->getHeaderLine('Cache-Control'));
    }

    public function testSkipsUnknownExtensions(): void
    {
        $response = $this->invoke('/file.xyz');
        $this->assertFalse($response->hasHeader('Cache-Control'));
    }

    public function testSkipsExtensionlessUrls(): void
    {
        $response = $this->invoke('/api/users');
        $this->assertFalse($response->hasHeader('Cache-Control'));
    }

    public function testRespectsExistingCacheControl(): void
    {
        $response = $this->invoke('/site.css', null, ['Cache-Control' => 'no-store']);
        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testCustomMap(): void
    {
        $mw = new CacheControlMiddleware(['html' => 60]);
        $response = $this->invoke('/page.html', $mw);
        $this->assertSame('max-age=60, public', $response->getHeaderLine('Cache-Control'));
    }

    public function testPrivateCacheFlag(): void
    {
        $mw = new CacheControlMiddleware(null, publicCache: false);
        $response = $this->invoke('/avatar.png', $mw);
        $this->assertSame('max-age=2628000, private', $response->getHeaderLine('Cache-Control'));
    }

    private function invoke(string $path, ?CacheControlMiddleware $mw = null, array $headers = []): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $mw ??= new CacheControlMiddleware();
        $handler = new class($headers) implements RequestHandlerInterface {
            public function __construct(private array $headers) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('asset', 200, '', $this->headers);
            }
        };
        return $mw->process(new ServerRequest($path, 'GET'), $handler);
    }
}

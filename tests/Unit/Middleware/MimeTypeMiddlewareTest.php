<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\MimeTypeMiddleware;
use ZealPHP\Tests\TestCase;

class MimeTypeMiddlewareTest extends TestCase
{
    public function testSetsCustomMimeFromExtension(): void
    {
        $mw = new MimeTypeMiddleware(['wasm' => 'application/wasm']);
        $response = $this->invoke($mw, '/module.wasm');

        $this->assertSame('application/wasm', $response->getHeaderLine('Content-Type'));
    }

    public function testNeverOverridesExistingContentType(): void
    {
        $mw = new MimeTypeMiddleware(['wasm' => 'application/wasm']);
        $response = $this->invoke($mw, '/module.wasm', ['Content-Type' => 'text/plain']);

        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testIgnoresUnmappedExtensions(): void
    {
        $mw = new MimeTypeMiddleware(['glb' => 'model/gltf-binary']);
        $response = $this->invoke($mw, '/picture.png');

        $this->assertFalse($response->hasHeader('Content-Type'));
    }

    public function testCaseInsensitiveExtension(): void
    {
        $mw = new MimeTypeMiddleware(['WASM' => 'application/wasm']);
        $response = $this->invoke($mw, '/module.WASM');

        $this->assertSame('application/wasm', $response->getHeaderLine('Content-Type'));
    }

    private function invoke(MimeTypeMiddleware $mw, string $path, array $headers = []): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $handler = new class($headers) implements RequestHandlerInterface {
            public function __construct(private array $headers) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('bytes', 200, '', $this->headers);
            }
        };
        return $mw->process(new ServerRequest($path, 'GET'), $handler);
    }
}

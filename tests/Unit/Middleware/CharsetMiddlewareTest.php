<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\CharsetMiddleware;
use ZealPHP\Tests\TestCase;

class CharsetMiddlewareTest extends TestCase
{
    public function testAppendsCharsetToHtml(): void
    {
        $response = $this->invoke('text/html');
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testAppendsCharsetToJson(): void
    {
        $response = $this->invoke('application/json');
        $this->assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testCustomCharset(): void
    {
        $response = $this->invoke('text/plain', new CharsetMiddleware('iso-8859-1'));
        $this->assertSame('text/plain; charset=iso-8859-1', $response->getHeaderLine('Content-Type'));
    }

    public function testLeavesExistingCharsetAlone(): void
    {
        $response = $this->invoke('text/html; charset=ascii');
        $this->assertSame('text/html; charset=ascii', $response->getHeaderLine('Content-Type'));
    }

    public function testSkipsBinaryTypes(): void
    {
        $response = $this->invoke('image/png');
        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
    }

    public function testMissingContentTypeGetsDefaultMimeType(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $prev = App::$default_mimetype;
        App::$default_mimetype = 'text/html'; // mod_php default

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200);
            }
        };

        $response = (new CharsetMiddleware())->process(new ServerRequest('/', 'GET'), $handler);
        App::$default_mimetype = $prev;
        // default_mimetype parity: untyped response gets text/html + charset.
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testMissingContentTypeLeftUntouchedWhenDefaultDisabled(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        $prev = App::$default_mimetype;
        App::$default_mimetype = ''; // opt out — leave untyped responses alone

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200);
            }
        };

        $response = (new CharsetMiddleware())->process(new ServerRequest('/', 'GET'), $handler);
        App::$default_mimetype = $prev;
        $this->assertSame('', $response->getHeaderLine('Content-Type'));
    }

    private function invoke(string $contentType, ?CharsetMiddleware $mw = null): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $mw ??= new CharsetMiddleware();
        $handler = new class($contentType) implements RequestHandlerInterface {
            public function __construct(private string $ct) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200, '', ['Content-Type' => $this->ct]);
            }
        };
        return $mw->process(new ServerRequest('/', 'GET'), $handler);
    }
}

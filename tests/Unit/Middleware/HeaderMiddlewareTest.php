<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\HeaderMiddleware;
use ZealPHP\Tests\TestCase;

class HeaderMiddlewareTest extends TestCase
{
    public function testSetsSecurityHeaders(): void
    {
        $response = $this->invoke(new HeaderMiddleware([
            'set' => [
                'X-Frame-Options'        => 'DENY',
                'X-Content-Type-Options' => 'nosniff',
            ],
        ]));

        $this->assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    public function testSetOverridesExistingHeader(): void
    {
        $response = $this->invoke(
            new HeaderMiddleware(['set' => ['Content-Type' => 'application/xml']]),
            ['Content-Type' => 'text/html']
        );

        $this->assertSame('application/xml', $response->getHeaderLine('Content-Type'));
    }

    public function testAppendMergesValues(): void
    {
        $response = $this->invoke(
            new HeaderMiddleware(['append' => ['Vary' => 'Accept-Encoding']]),
            ['Vary' => 'Origin']
        );

        $this->assertSame('Origin, Accept-Encoding', $response->getHeaderLine('Vary'));
    }

    public function testAppendOnEmptyHeaderJustSets(): void
    {
        $response = $this->invoke(new HeaderMiddleware(['append' => ['Vary' => 'Accept-Encoding']]));

        $this->assertSame('Accept-Encoding', $response->getHeaderLine('Vary'));
    }

    public function testUnsetRemovesHeader(): void
    {
        $response = $this->invoke(
            new HeaderMiddleware(['unset' => ['Server']]),
            ['Server' => 'OpenSwoole/22']
        );

        $this->assertFalse($response->hasHeader('Server'));
    }

    public function testAddCreatesMultipleEntries(): void
    {
        $response = $this->invoke(new HeaderMiddleware([
            'add' => ['Link' => ['<a.css>; rel=preload', '<b.js>; rel=preload']],
        ]));

        $values = $response->getHeader('Link');
        $this->assertCount(2, $values);
        $this->assertSame('<a.css>; rel=preload', $values[0]);
        $this->assertSame('<b.js>; rel=preload', $values[1]);
    }

    private function invoke(HeaderMiddleware $mw, array $upstreamHeaders = []): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $handler = new class($upstreamHeaders) implements RequestHandlerInterface {
            public function __construct(private array $headers) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200, '', $this->headers);
            }
        };
        return $mw->process(new ServerRequest('/', 'GET'), $handler);
    }
}

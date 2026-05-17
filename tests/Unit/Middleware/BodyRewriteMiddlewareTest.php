<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\BodyRewriteMiddleware;
use ZealPHP\Tests\TestCase;

class BodyRewriteMiddlewareTest extends TestCase
{
    public function testAppliesSingleSubstitution(): void
    {
        $mw = new BodyRewriteMiddleware([
            ['pattern' => '#http://internal\.lan#', 'replacement' => 'https://example.com'],
        ]);
        $response = $this->invoke($mw, 'text/html', '<a href="http://internal.lan/x">link</a>');

        $this->assertSame('<a href="https://example.com/x">link</a>', (string)$response->getBody());
        $this->assertSame((string)strlen((string)$response->getBody()), $response->getHeaderLine('Content-Length'));
    }

    public function testAppliesMultipleRulesInOrder(): void
    {
        $mw = new BodyRewriteMiddleware([
            ['pattern' => '/foo/', 'replacement' => 'bar'],
            ['pattern' => '/bar/', 'replacement' => 'baz'],
        ]);
        $response = $this->invoke($mw, 'text/plain', 'foo');

        // First rule turns "foo" → "bar"; second rule then turns "bar" → "baz".
        $this->assertSame('baz', (string)$response->getBody());
    }

    public function testCaptureGroupReplacement(): void
    {
        $mw = new BodyRewriteMiddleware([
            ['pattern' => '/<title>(.*?)<\/title>/', 'replacement' => '<title>$1 — ZealPHP</title>'],
        ]);
        $response = $this->invoke($mw, 'text/html', '<title>Home</title>');

        $this->assertSame('<title>Home — ZealPHP</title>', (string)$response->getBody());
    }

    public function testSkipsBinaryContentTypes(): void
    {
        $mw = new BodyRewriteMiddleware([
            ['pattern' => '/secret/', 'replacement' => 'PUBLIC'],
        ]);
        $response = $this->invoke($mw, 'image/png', 'secret pixel');

        $this->assertSame('secret pixel', (string)$response->getBody());
    }

    public function testSkipsEmptyBody(): void
    {
        $mw = new BodyRewriteMiddleware([
            ['pattern' => '/anything/', 'replacement' => 'else'],
        ]);
        $response = $this->invoke($mw, 'text/html', '');

        $this->assertSame('', (string)$response->getBody());
    }

    public function testNoChangeKeepsOriginalBody(): void
    {
        $mw = new BodyRewriteMiddleware([
            ['pattern' => '/never matches/', 'replacement' => 'x'],
        ]);
        $response = $this->invoke($mw, 'text/plain', 'hello world');

        $this->assertSame('hello world', (string)$response->getBody());
    }

    public function testInvalidPatternSilentlySkipsRule(): void
    {
        $mw = new BodyRewriteMiddleware([
            ['pattern' => '/unclosed', 'replacement' => 'x'],
            ['pattern' => '/world/', 'replacement' => 'planet'],
        ]);
        $response = $this->invoke($mw, 'text/plain', 'hello world');

        // Bad rule was a no-op but second rule still applies.
        $this->assertSame('hello planet', (string)$response->getBody());
    }

    private function invoke(BodyRewriteMiddleware $mw, string $contentType, string $body): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $handler = new class($body, $contentType) implements RequestHandlerInterface {
            public function __construct(private string $body, private string $ct) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200, '', ['Content-Type' => $this->ct]);
            }
        };
        return $mw->process(new ServerRequest('/', 'GET'), $handler);
    }
}

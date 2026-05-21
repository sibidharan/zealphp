<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\CharsetMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class CharsetMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        App::$default_mimetype = 'text/html';
        RequestContext::instance()->zealphp_response = $this->headerRecorder();
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->zealphp_response = null;
        App::$default_mimetype = 'text/html';
        parent::tearDown();
    }

    public function testAppendsCharsetToTextHtml(): void
    {
        $g = RequestContext::instance();
        $response = $this->process('text/html');

        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('text/html; charset=utf-8', $g->zealphp_response->headers['Content-Type'] ?? null);
    }

    public function testUppercaseTextTypeStillGetsCharset(): void
    {
        // Kills UnwrapStrToLower on isTextish(): without strtolower, 'TEXT/HTML'
        // would not match the lowercase 'text/' prefix and charset would be skipped.
        $response = $this->process('TEXT/HTML');

        $this->assertSame('TEXT/HTML; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testNonTextishWithExistingTypeIsLeftUntouched(): void
    {
        // image/png is non-textish and the response already had a Content-Type
        // → early return, no mutation, raw response never touched.
        // Kills LogicalNot on `!$ctWasEmpty` (mutant would fall through and
        // write the Content-Type onto the raw response recorder).
        $g = RequestContext::instance();
        $response = $this->process('image/png');

        $this->assertSame('image/png', $response->getHeaderLine('Content-Type'));
        $this->assertArrayNotHasKey('Content-Type', $g->zealphp_response->headers);
    }

    public function testExistingCharsetIsNotDuplicated(): void
    {
        $response = $this->process('text/html; charset=iso-8859-1');
        $this->assertSame('text/html; charset=iso-8859-1', $response->getHeaderLine('Content-Type'));
    }

    public function testEmptyContentTypeGetsDefaultMimetypePlusCharset(): void
    {
        // No Content-Type on the response → default_mimetype (text/html) applied,
        // then charset appended (this exercises the ctWasEmpty=true path).
        $response = $this->process(null);
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
    }

    public function testCustomCharset(): void
    {
        $mw = new CharsetMiddleware('iso-8859-1');
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = $this->handlerWith('text/plain');
        $response = $mw->process($request, $handler);
        $this->assertSame('text/plain; charset=iso-8859-1', $response->getHeaderLine('Content-Type'));
    }

    private function process(?string $contentType): ResponseInterface
    {
        $mw = new CharsetMiddleware();
        $request = new ServerRequest('/', 'GET', '', []);
        return $mw->process($request, $this->handlerWith($contentType));
    }

    private function handlerWith(?string $contentType): RequestHandlerInterface
    {
        $headers = $contentType === null ? [] : ['Content-Type' => $contentType];
        return new class($headers) implements RequestHandlerInterface {
            /** @param array<string,string> $headers */
            public function __construct(private array $headers) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('body', 200, '', $this->headers);
            }
        };
    }

    private function headerRecorder(): object
    {
        return new class {
            /** @var array<string,string> */
            public array $headers = [];
            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }
        };
    }
}

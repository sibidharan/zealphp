<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\ETagMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class ETagMiddlewareTest extends TestCase
{
    /** @var object{headers: array<string,string>} */
    private object $resp;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        App::$file_etag = true;
        $this->resp = new class {
            /** @var array<string, string> */
            public array $headers = [];
            public function header(string $k, string $v): void { $this->headers[$k] = $v; }
        };
        $g = RequestContext::instance();
        $g->zealphp_response = $this->resp;
        $g->status = 200;
        $g->_streaming = null;
    }

    protected function tearDown(): void
    {
        App::$file_etag = true;
        parent::tearDown();
    }

    public function testFileETagDisabledSkipsEtagAnd304(): void
    {
        App::$file_etag = false; // Apache FileETag None
        $mw   = new ETagMiddleware();
        $body = 'cacheable content';
        $etag = 'W/"' . hash('xxh3', $body) . '"';
        // Even a matching If-None-Match must NOT 304 when ETags are disabled.
        $req  = (new ServerRequest('/', 'GET'))->withHeader('If-None-Match', $etag);
        $res  = $mw->process($req, $this->handlerReturning($body));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertArrayNotHasKey('ETag', $this->resp->headers);
    }

    private function handlerReturning(string $body): RequestHandlerInterface
    {
        return new class($body) implements RequestHandlerInterface {
            public function __construct(private string $body) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->body, 200, '', ['Content-Type' => 'text/plain']);
            }
        };
    }

    public function testGetWithBodyAddsWeakEtag(): void
    {
        $mw  = new ETagMiddleware();
        $res = $mw->process(new ServerRequest('/', 'GET'), $this->handlerReturning('hello world'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertArrayHasKey('ETag', $this->resp->headers);
        $this->assertStringStartsWith('W/"', $this->resp->headers['ETag']);
    }

    public function testNonGetSkipsEtag(): void
    {
        $mw  = new ETagMiddleware();
        $res = $mw->process(new ServerRequest('/', 'POST'), $this->handlerReturning('body'));
        $this->assertSame(200, $res->getStatusCode());
        $this->assertArrayNotHasKey('ETag', $this->resp->headers);
    }

    public function testEmptyBodySkipsEtag(): void
    {
        $mw  = new ETagMiddleware();
        $mw->process(new ServerRequest('/', 'GET'), $this->handlerReturning(''));
        $this->assertArrayNotHasKey('ETag', $this->resp->headers);
    }

    public function testStreamingResponseSkipsEtag(): void
    {
        RequestContext::instance()->_streaming = true;
        $mw  = new ETagMiddleware();
        $mw->process(new ServerRequest('/', 'GET'), $this->handlerReturning('streamed'));
        $this->assertArrayNotHasKey('ETag', $this->resp->headers);
    }

    public function testMatchingIfNoneMatchReturns304(): void
    {
        $mw   = new ETagMiddleware();
        $body = 'cacheable content';
        $etag = 'W/"' . hash('xxh3', $body) . '"';
        $req  = (new ServerRequest('/', 'GET'))->withHeader('If-None-Match', $etag);
        $res  = $mw->process($req, $this->handlerReturning($body));

        $this->assertSame(304, $res->getStatusCode());
        $this->assertSame('', (string) $res->getBody());
        $this->assertSame($etag, $this->resp->headers['ETag']);
    }

    public function testNonMatchingIfNoneMatchReturns200WithEtag(): void
    {
        $mw  = new ETagMiddleware();
        $req = (new ServerRequest('/', 'GET'))->withHeader('If-None-Match', 'W/"stale"');
        $res = $mw->process($req, $this->handlerReturning('fresh content'));

        $this->assertSame(200, $res->getStatusCode());
        $this->assertArrayHasKey('ETag', $this->resp->headers);
        $this->assertNotSame('W/"stale"', $this->resp->headers['ETag']);
    }
}

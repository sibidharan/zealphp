<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\CorsMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class CorsMiddlewareTest extends TestCase
{
    /** @var object{headers: array<string,string>} */
    private object $resp;

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        // Mock the OpenSwoole response wrapper the middleware writes headers into.
        $this->resp = new class {
            /** @var array<string, string> */
            public array $headers = [];
            public function header(string $k, string $v): void { $this->headers[$k] = $v; }
        };
        $g = RequestContext::instance();
        $g->zealphp_response = $this->resp;
        $g->status = 200;
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

    public function testPreflightOptionsReturns204WithCorsHeaders(): void
    {
        $mw  = new CorsMiddleware(origins: ['https://app.example.com'], credentials: true, maxAge: 600);
        $req = (new ServerRequest('/', 'OPTIONS'))->withHeader('Origin', 'https://app.example.com');
        $res = $mw->process($req, $this->okHandler());

        $this->assertSame(204, $res->getStatusCode());
        $this->assertSame('https://app.example.com', $this->resp->headers['Access-Control-Allow-Origin']);
        $this->assertStringContainsString('GET', $this->resp->headers['Access-Control-Allow-Methods']);
        $this->assertSame('600', $this->resp->headers['Access-Control-Max-Age']);
        $this->assertSame('true', $this->resp->headers['Access-Control-Allow-Credentials']);
        $this->assertSame('Origin', $this->resp->headers['Vary']);
    }

    public function testOptionsWithoutOriginPassesThrough(): void
    {
        $mw  = new CorsMiddleware(origins: ['*']);
        $res = $mw->process(new ServerRequest('/', 'OPTIONS'), $this->okHandler());
        // No Origin header → not treated as preflight → handler runs (200).
        $this->assertSame(200, $res->getStatusCode());
    }

    public function testNormalRequestGetsCorsHeadersAndPassesThrough(): void
    {
        $mw  = new CorsMiddleware(origins: ['*']);
        $res = $mw->process(new ServerRequest('/', 'GET'), $this->okHandler());

        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('*', $this->resp->headers['Access-Control-Allow-Origin']);
        $this->assertSame('false', $this->resp->headers['Access-Control-Allow-Credentials']);
        $this->assertSame('Origin', $this->resp->headers['Vary']);
    }

    public function testExplicitOriginListEchoesMatchingOrigin(): void
    {
        $mw  = new CorsMiddleware(origins: ['https://a.com', 'https://b.com']);
        $req = (new ServerRequest('/', 'GET'))->withHeader('Origin', 'https://b.com');
        $mw->process($req, $this->okHandler());
        $this->assertSame('https://b.com', $this->resp->headers['Access-Control-Allow-Origin']);
    }

    public function testExplicitOriginListFallsBackToFirstForUnknownOrigin(): void
    {
        $mw  = new CorsMiddleware(origins: ['https://a.com', 'https://b.com']);
        $req = (new ServerRequest('/', 'GET'))->withHeader('Origin', 'https://evil.com');
        $mw->process($req, $this->okHandler());
        $this->assertSame('https://a.com', $this->resp->headers['Access-Control-Allow-Origin']);
    }

    public function testWildcardWithCredentialsEchoesRequestOrigin(): void
    {
        $mw  = new CorsMiddleware(origins: ['*'], credentials: true);
        $req = (new ServerRequest('/', 'GET'))->withHeader('Origin', 'https://x.com');
        $mw->process($req, $this->okHandler());
        // credentials=true can't use wildcard — must echo the concrete origin.
        $this->assertSame('https://x.com', $this->resp->headers['Access-Control-Allow-Origin']);
    }

    public function testEnvOriginsAreParsed(): void
    {
        putenv('ZEALPHP_CORS_ORIGINS=https://one.com, https://two.com');
        try {
            $mw  = new CorsMiddleware(); // null → reads env
            $req = (new ServerRequest('/', 'GET'))->withHeader('Origin', 'https://two.com');
            $mw->process($req, $this->okHandler());
            $this->assertSame('https://two.com', $this->resp->headers['Access-Control-Allow-Origin']);
        } finally {
            putenv('ZEALPHP_CORS_ORIGINS');
        }
    }
}

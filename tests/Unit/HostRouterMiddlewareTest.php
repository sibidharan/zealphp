<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\HostRouterMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class HostRouterMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->server = [];
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->server = [];
    }

    /**
     * @param array<string, mixed> $hosts
     * @param array<string, string> $headers
     */
    private function process(array $hosts, string $host, array $headers = []): ResponseInterface
    {
        $mw = new HostRouterMiddleware($hosts);
        $headers = array_merge(['host' => $host], $headers);
        $request = new ServerRequest('/', 'GET', '', $headers);

        // Fallthrough handler — distinguishable from any host-routed response.
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('FALLTHROUGH', 200, '', ['Content-Type' => 'text/plain']);
            }
        };

        return $mw->process($request, $handler);
    }

    public function testExactHostMatchDispatches(): void
    {
        $response = $this->process(
            ['docs.example.com' => fn() => 'docs body'],
            'docs.example.com'
        );

        $this->assertSame('docs body', (string) $response->getBody());
    }

    public function testRegisteredHostKeyIsLowercased(): void
    {
        // Register a MIXED-CASE host key, request all lowercase. The constructor
        // MUST lowercase the key for the match to land. Kills UnwrapStrToLower
        // at L66 (key stored mixed-case -> no match -> fallthrough).
        $response = $this->process(
            ['Docs.Example.COM' => fn() => 'docs body'],
            'docs.example.com'
        );

        $this->assertSame('docs body', (string) $response->getBody());
    }

    public function testNumericHostKeyIsStringCast(): void
    {
        // A purely-numeric host key is coerced by PHP to an int array key.
        // The constructor's (string) cast is load-bearing: strtolower() needs a
        // string, and the lookup compares against the string request host.
        // Kills CastString at L66 (without it, strtolower(int) would error /
        // the key wouldn't normalise to the matchable "127").
        $response = $this->process(
            ['127' => fn() => 'numeric-host'],
            '127'
        );

        $this->assertSame('numeric-host', (string) $response->getBody());
    }

    public function testPortIsStrippedBeforeMatching(): void
    {
        $response = $this->process(
            ['example.com' => fn() => 'site'],
            'example.com:8080'
        );

        $this->assertSame('site', (string) $response->getBody());
    }

    public function testNoMatchFallsThroughToHandler(): void
    {
        $response = $this->process(
            ['a.com' => fn() => 'a'],
            'unknown.com'
        );

        $this->assertSame('FALLTHROUGH', (string) $response->getBody());
    }

    public function testCatchAllRegistrationDoesNotStopFollowingHosts(): void
    {
        // '*' is registered FIRST; a real host follows it. The Continue_ mutant
        // at L69 (continue -> break) would abort the loop after '*', so the
        // 'late.com' handler would never register and the request would hit the
        // catch-all instead of its own handler.
        $response = $this->process(
            [
                '*'        => fn() => 'CATCHALL',
                'late.com' => fn() => 'LATE',
            ],
            'late.com'
        );

        $this->assertSame('LATE', (string) $response->getBody());
    }

    public function testCatchAllUsedWhenNoOtherHostMatches(): void
    {
        $response = $this->process(
            [
                '*'        => fn() => 'CATCHALL',
                'late.com' => fn() => 'LATE',
            ],
            'nobody.com'
        );

        $this->assertSame('CATCHALL', (string) $response->getBody());
    }

    public function testWildcardRegistrationDoesNotStopFollowingHosts(): void
    {
        // Wildcard registered first; a plain host follows. Continue_ mutant at
        // L73 (continue -> break) would abort before registering 'plain.com'.
        $response = $this->process(
            [
                '*.example.com' => fn() => 'WILD',
                'plain.com'     => fn() => 'PLAIN',
            ],
            'plain.com'
        );

        $this->assertSame('PLAIN', (string) $response->getBody());
    }

    public function testWildcardSubdomainMatches(): void
    {
        $response = $this->process(
            ['*.example.com' => fn() => 'WILD'],
            'sub.example.com'
        );

        $this->assertSame('WILD', (string) $response->getBody());
    }

    public function testWildcardDoesNotMatchBareDomain(): void
    {
        // *.example.com must NOT match example.com itself -> falls through.
        $response = $this->process(
            ['*.example.com' => fn() => 'WILD'],
            'example.com'
        );

        $this->assertSame('FALLTHROUGH', (string) $response->getBody());
    }

    public function testHandlerReturningResponseInterfaceIsUsedDirectly(): void
    {
        // Kills InstanceOf_ (instanceof -> false): if the branch is skipped the
        // Response would be re-coerced (object -> JSON) losing the 418 status.
        $response = $this->process(
            ['api.example.com' => fn() => new Response('teapot', 418, '', ['Content-Type' => 'text/plain'])],
            'api.example.com'
        );

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame('teapot', (string) $response->getBody());
    }

    public function testIntResultBecomesStatusCode(): void
    {
        $response = $this->process(
            ['api.example.com' => fn() => 503],
            'api.example.com'
        );

        $this->assertSame(503, $response->getStatusCode());
    }

    public function testArrayResultBecomesJsonWith200(): void
    {
        // Kills CastString on json_encode (would TypeError without cast under
        // Response's typed body) and Inc/DecrementInteger on the 200 literal.
        $response = $this->process(
            ['api.example.com' => fn() => ['status' => 'ok', 'n' => 7]],
            'api.example.com'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"status":"ok","n":7}', (string) $response->getBody());
    }

    public function testObjectResultBecomesJson(): void
    {
        $obj = (object) ['a' => 1];
        $response = $this->process(
            ['api.example.com' => fn() => $obj],
            'api.example.com'
        );

        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"a":1}', (string) $response->getBody());
    }

    public function testNullResultBecomesEmpty200(): void
    {
        $response = $this->process(
            ['api.example.com' => fn() => null],
            'api.example.com'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testScalarResultBecomesHtmlBody(): void
    {
        // Float scalar -> (string) cast + Content-Type text/html. Kills the L135
        // CastString (typed body would TypeError on the raw float) AND the
        // ArrayItemRemoval that drops the Content-Type header.
        $response = $this->process(
            ['api.example.com' => fn() => 3.5],
            'api.example.com'
        );

        $this->assertSame('3.5', (string) $response->getBody());
        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
    }

    /**
     * @param array<string, scalar|null> $server
     * @param array<string, mixed>       $hosts
     */
    private function processNoHostHeader(array $server, array $hosts): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->server = $server;

        $mw = new HostRouterMiddleware($hosts);
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('FALLTHROUGH', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }

    public function testHostFromServerHttpHostMixedCaseLowercased(): void
    {
        // No Host header -> falls back to $g->server['HTTP_HOST'], which must be
        // lowercased. Kills UnwrapStrToLower at L84 (mixed-case server value
        // wouldn't match the lowercase registered host).
        $response = $this->processNoHostHeader(
            ['HTTP_HOST' => 'Fallback.COM'],
            ['fallback.com' => fn() => 'from-server']
        );

        $this->assertSame('from-server', (string) $response->getBody());
    }

    public function testHostFromServerHttpHostNonStringScalarCast(): void
    {
        // HTTP_HOST stored as a non-string scalar (int). Kills CastString at
        // L84: without (string), strtolower() can't handle the int and the
        // numeric host wouldn't normalise to the matchable "8080".
        $response = $this->processNoHostHeader(
            ['HTTP_HOST' => 8080],
            ['8080' => fn() => 'numeric-server-host']
        );

        $this->assertSame('numeric-server-host', (string) $response->getBody());
    }

    public function testNonCallableHandlerThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HostRouterMiddleware(['bad.com' => 'not-callable']);
    }
}

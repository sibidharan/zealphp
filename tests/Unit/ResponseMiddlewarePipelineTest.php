<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\ResponseMiddleware;
use ZealPHP\Tests\TestCase;
use ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse;

/**
 * Drives the request pipeline IN-PROCESS — exactly what the OnRequest handler
 * does (`App::middleware()->handle($req)` → ResponseMiddleware::process). This
 * exercises ResponseMiddleware (route matching, parameter injection, the
 * universal return contract, HEAD/OPTIONS/TRACE handling, traversal rejection)
 * without a live OpenSwoole server, so pcov sees the coverage that the curl-
 * based integration tests can't contribute.
 */
class ResponseMiddlewarePipelineTest extends TestCase
{
    private static bool $routesRegistered = false;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        if (App::instance() === null) {
            App::init('127.0.0.1', 19997, ZEALPHP_ROOT);
        }
        if (!self::$routesRegistered) {
            $app = App::instance();
            \assert($app !== null);
            $app->route('/contract/int', fn() => 404);
            $app->route('/contract/array', fn() => ['ok' => true, 'n' => 7]);
            $app->route('/contract/string', fn() => 'plain-body');
            $app->route('/contract/null', fn() => null);
            $app->route('/contract/object', fn() => (object) ['k' => 'v']);
            $app->route('/inject/{id}', fn($id) => "id=$id");
            $app->route('/inject/multi/{a}/{b}', fn($a, $b) => "$a-$b");
            $app->route('/inject/default', fn($missing = 'fallback') => "v=$missing");
            $app->route('/gen', fn() => (function () { yield 'g1'; yield 'g2'; })());
            $app->route('/postonly', ['methods' => ['POST']], fn() => 'posted');
            // #240 — reserved framework-object params (app / request / req /
            // response / res) bind the injected object BEFORE any same-named URL
            // segment. These routes unit-cover the reordered binding in BOTH the
            // matched (regular) and raw dispatch blocks.
            $app->route('/inject/app', fn($app) => 'app:' . (is_object($app) ? (new \ReflectionClass($app))->getShortName() : gettype($app)));
            $app->route('/inject/res', fn($res) => 'res:' . (is_object($res) ? (new \ReflectionClass($res))->getShortName() : gettype($res)));
            $app->route('/shadow/{request}', fn($request) => is_string($request) ? "url:$request" : 'wrapper');
            $app->route('/raw/inject', ['raw' => true], fn($app, $req, $res) => 'raw:' . (is_object($app) ? 'a' : '-') . (is_object($res) ? 'r' : '-'));
            $app->route('/raw/url/{id}', ['raw' => true], fn($id) => "rawurl:$id");
            $app->route('/raw/default', ['raw' => true], fn($missing = 'def') => "rawdef:$missing");

            // run() builds the method-indexed dispatch table from $this->routes
            // (App.php ~3996). We don't boot the server, so replicate that build
            // via reflection — otherwise process() sees an empty index → 404.
            $ref = new \ReflectionObject($app);
            $routesProp = $ref->getProperty('routes');
            $routesProp->setAccessible(true);
            /** @var array<int, array<string, mixed>> $routes */
            $routes = $routesProp->getValue($app);
            $byMethod = [];
            $byExact  = [];
            foreach ($routes as $route) {
                /** @var array<int|string, string> $methods */
                $methods = $route['methods'];
                $path = is_string($route['path'] ?? null) ? $route['path'] : '';
                foreach ($methods as $m) {
                    $byMethod[$m][] = $route;
                    if ($path !== '' && preg_match('/[\\\\^$.|?*+()[\\]{}]/', $path) === 0) {
                        $byExact[$m][$path] = $route;
                    }
                }
            }
            foreach (['routes_by_method' => $byMethod, 'routes_by_exact_method' => $byExact] as $prop => $val) {
                $p = $ref->getProperty($prop);
                $p->setAccessible(true);
                $p->setValue($app, $val);
            }
            self::$routesRegistered = true;
        }
    }

    public static function tearDownAfterClass(): void
    {
        \OpenSwoole\Runtime::enableCoroutine(0);
        App::superglobals(true);
    }

    protected function setUp(): void
    {
        $fake = new FakeOpenSwooleResponse();
        $g = RequestContext::instance();
        $g->openswoole_response = $fake;
        $g->zealphp_response    = new \ZealPHP\HTTP\Response($fake);
        $g->zealphp_request     = null;
        $g->status              = 200;
        $g->_streaming          = null;
        $g->server              = [];
    }

    private function dispatch(string $uri, string $method = 'GET'): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->server['REQUEST_URI']    = $uri;
        $g->server['REQUEST_METHOD'] = $method;
        $g->server['HTTP_HOST']      = 'localhost';

        $finalHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('unhandled', 500);
            }
        };
        return (new ResponseMiddleware())->process(new ServerRequest($uri, $method), $finalHandler);
    }

    // ── universal return contract ─────────────────────────────────

    public function testIntReturnSetsStatus(): void
    {
        $res = $this->dispatch('/contract/int');
        $this->assertSame(404, $res->getStatusCode());
    }

    public function testArrayReturnEmitsJson(): void
    {
        $res = $this->dispatch('/contract/array');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame(['ok' => true, 'n' => 7], json_decode((string) $res->getBody(), true));
    }

    public function testObjectReturnEmitsJson(): void
    {
        $res = $this->dispatch('/contract/object');
        $this->assertSame(['k' => 'v'], json_decode((string) $res->getBody(), true));
    }

    public function testStringReturnIsBody(): void
    {
        $res = $this->dispatch('/contract/string');
        $this->assertSame('plain-body', (string) $res->getBody());
    }

    public function testNullReturnIsEmptyBody200(): void
    {
        $res = $this->dispatch('/contract/null');
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('', (string) $res->getBody());
    }

    // ── parameter injection ───────────────────────────────────────

    public function testSingleParamInjected(): void
    {
        $this->assertSame('id=42', (string) $this->dispatch('/inject/42')->getBody());
    }

    public function testMultipleParamsInjected(): void
    {
        $this->assertSame('x-y', (string) $this->dispatch('/inject/multi/x/y')->getBody());
    }

    public function testDefaultParamUsedWhenAbsent(): void
    {
        $this->assertSame('v=fallback', (string) $this->dispatch('/inject/default')->getBody());
    }

    // ── #240: reserved framework-object params bind before URL segments ──

    public function testReservedAppParamInjectsResponseMiddleware(): void
    {
        $this->assertSame('app:ResponseMiddleware', (string) $this->dispatch('/inject/app')->getBody());
    }

    public function testReservedResParamInjectsResponseWrapper(): void
    {
        $this->assertSame('res:Response', (string) $this->dispatch('/inject/res')->getBody());
    }

    public function testReservedRequestNameWinsOverUrlSegment(): void
    {
        // The {request} URL segment must NOT shadow the injected request — the
        // reserved name binds first, so function($request) never receives the path
        // string ('pwned'). $g->zealphp_request is null in this harness, so the
        // handler sees a non-string and returns 'wrapper', never 'url:pwned'.
        $body = (string) $this->dispatch('/shadow/pwned')->getBody();
        $this->assertSame('wrapper', $body);
        $this->assertStringNotContainsString('pwned', $body);
    }

    public function testRawRouteReservedParamsInjected(): void
    {
        // The raw dispatch block carries the identical reserved-name precedence.
        $this->assertSame('raw:ar', (string) $this->dispatch('/raw/inject')->getBody());
    }

    public function testRawRouteUrlParamBinds(): void
    {
        // dispatchRawRoute: a non-reserved URL segment binds normally (the
        // isset($params) branch, after the reserved-name checks).
        $this->assertSame('rawurl:7', (string) $this->dispatch('/raw/url/7')->getBody());
    }

    public function testRawRouteDefaultParamUsed(): void
    {
        // dispatchRawRoute: an absent, non-reserved param falls back to its default.
        $this->assertSame('rawdef:def', (string) $this->dispatch('/raw/default')->getBody());
    }

    // ── HTTP method handling ──────────────────────────────────────

    public function testUnknownRouteIs404(): void
    {
        $this->assertSame(404, $this->dispatch('/nope/nowhere')->getStatusCode());
    }

    public function testHeadStripsBodyKeepsStatus(): void
    {
        $res = $this->dispatch('/contract/string', 'HEAD');
        $this->assertSame('', (string) $res->getBody());
    }

    public function testOptionsReturnsAllow(): void
    {
        $g = RequestContext::instance();
        $res = $this->dispatch('/postonly', 'OPTIONS');
        $this->assertSame(204, $res->getStatusCode());
        // Allow is buffered on the response wrapper's headersList (flushed to
        // the socket on emit), readable via response_headers_list().
        $allow = '';
        foreach (\ZealPHP\response_headers_list() as $pair) {
            if (strcasecmp((string) $pair[0], 'Allow') === 0) {
                $allow = (string) $pair[1];
            }
        }
        $this->assertStringContainsString('POST', $allow);
        $this->assertStringContainsString('OPTIONS', $allow);
    }

    public function testTraceRefused(): void
    {
        $res = $this->dispatch('/contract/string', 'TRACE');
        $this->assertSame(405, $res->getStatusCode());
    }

    public function testPostOnlyRouteRejectsGet(): void
    {
        // GET on a POST-only path → resource exists, method not allowed →
        // 405 Method Not Allowed (RFC 9110 §15.5.6), not 404.
        $this->assertSame(405, $this->dispatch('/postonly', 'GET')->getStatusCode());
    }

    public function testPostOnlyRouteAcceptsPost(): void
    {
        $this->assertSame('posted', (string) $this->dispatch('/postonly', 'POST')->getBody());
    }

    // ── security: traversal / null-byte rejection ─────────────────

    public function testTraversalRejected(): void
    {
        $this->assertSame(400, $this->dispatch('/../../etc/passwd')->getStatusCode());
    }

    public function testNullByteRejected(): void
    {
        $this->assertSame(400, $this->dispatch("/inject/%00")->getStatusCode());
    }

    // ── generator streaming ───────────────────────────────────────

    public function testGeneratorStreamsToOpenswooleResponse(): void
    {
        // The Generator dispatch path calls \OpenSwoole\Coroutine::sleep(0)
        // between chunks, which requires a coroutine context. Drive it inside
        // one so the streaming branch is exercised in-process.
        $g = RequestContext::instance();
        $captured = [];
        \OpenSwoole\Coroutine::run(function () use (&$captured) {
            $this->dispatch('/gen');
            $g = RequestContext::instance();
            \assert($g->openswoole_response instanceof FakeOpenSwooleResponse);
            foreach ($g->openswoole_response->log as $e) {
                if (($e[0] ?? null) === 'write') {
                    $captured[] = $e[1];
                }
            }
        });
        $this->assertContains('g1', $captured);
        $this->assertContains('g2', $captured);
    }
}

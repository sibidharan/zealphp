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
 * Extends in-process coverage of src/App.php beyond ResponseMiddlewarePipelineTest.
 *
 * Drives ResponseMiddleware::process(), dispatchRoute(), dispatchRawRoute(),
 * renderError() (handler dispatch + JSON/HTML negotiation + ServerAdmin line),
 * the raw-route contract, the App::include()/serveDirectory()/tryInclude()
 * file-execution paths, App::ws() registration, App::setFallback() dispatch,
 * and emitStatus()/coerceStatusCode()/reasonPhrase() edge codes — all WITHOUT
 * booting an OpenSwoole server, opening a socket, or forking a subprocess.
 *
 * All routes use /xtra/* paths so they never clash with the singleton route
 * table populated by ResponseMiddlewarePipelineTest (App::instance() is a
 * process-wide singleton; registered routes persist across test classes).
 */
class AppPipelineExtraTest extends TestCase
{
    private static bool $routesRegistered = false;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        if (App::instance() === null) {
            App::init('127.0.0.1', 19996, ZEALPHP_ROOT);
        }
        if (self::$routesRegistered) {
            return;
        }
        $app = App::instance();
        \assert($app !== null);

        // Exact-match routes (no regex meta) hit the routes_by_exact_method path.
        $app->route('/xtra/str', fn() => 'xtra-body');
        $app->route('/xtra/arr', fn() => ['x' => 1]);
        $app->route('/xtra/intok', fn() => 201);
        $app->route('/xtra/int404', fn() => 404);
        $app->route('/xtra/intbad', fn() => 99999);
        $app->route('/xtra/psr', fn() => new Response('psr-direct', 222));
        $app->route('/xtra/throw', function () {
            throw new \RuntimeException('boom-xtra');
        });
        $app->route('/xtra/null', fn() => null);

        // Regex / param routes hit the routes_by_method preg_match loop.
        $app->route('/xtra/p/{id}', fn($id) => "p=$id");

        // Raw routes (['raw' => true]) dispatch via dispatchRawRoute().
        $app->route('/xtra/raw/str', ['raw' => true], fn() => 'raw-str');
        $app->route('/xtra/raw/arr', ['raw' => true], fn() => ['r' => true]);
        $app->route('/xtra/raw/int', ['raw' => true], fn() => 404);
        $app->route('/xtra/raw/psr', ['raw' => true], fn() => new Response('raw-psr', 233));
        $app->route('/xtra/raw/gen', ['raw' => true], fn() => (function () {
            yield 'r1';
            yield 'r2';
        })());
        $app->route('/xtra/raw/throw', ['raw' => true], function () {
            throw new \RuntimeException('raw-boom');
        });

        // POST-only — used for OPTIONS Allow + 404-on-GET branches.
        $app->route('/xtra/postonly', ['methods' => ['POST']], fn() => 'posted');

        self::rebuildRouteIndex($app);
        self::$routesRegistered = true;
    }

    /**
     * run() builds the method-indexed dispatch tables from $this->routes. We
     * don't boot the server, so replicate that build via reflection — otherwise
     * process() sees an empty index and everything 404s.
     */
    private static function rebuildRouteIndex(App $app): void
    {
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
    }

    public static function tearDownAfterClass(): void
    {
        \OpenSwoole\Runtime::enableCoroutine(0);
        App::superglobals(true);
    }

    /** @var array<int, mixed> */
    private array $savedExcStack = [];
    /** @var array<int, mixed> */
    private array $savedErrStack = [];

    protected function setUp(): void
    {
        $fake = new FakeOpenSwooleResponse();
        $g = RequestContext::instance();
        $g->openswoole_response      = $fake;
        $g->zealphp_response         = new \ZealPHP\HTTP\Response($fake);
        $g->zealphp_request          = null;
        $g->status                   = 200;
        $g->_streaming               = null;
        $g->server                   = [];
        $g->error_render_depth       = 0;
        $g->memo                     = [];

        // PHPUnit's set_error_handler / set_exception_handler are uopz-overridden
        // (process-wide) to ZealPHP's per-coroutine G stacks, so PHPUnit's handler
        // snapshot (taken in runBare BEFORE setUp) actually reads these G arrays.
        // Capturing here and restoring verbatim in tearDown keeps active == backup
        // so the test isn't flagged risky for "removed handlers".
        $this->savedExcStack = $g->exception_handlers_stack;
        $this->savedErrStack = $g->error_handlers_stack;
    }

    protected function tearDown(): void
    {
        // Undo any per-test config mutation so we never leak into the ~880
        // sibling unit tests (they assume framework defaults).
        App::displayErrors(true);
        App::serverAdmin(null);
        App::stripTrailingSlash(false);
        App::traceEnabled(false);
        App::$path_info = true;
        App::$directory_slash = true;
        $this->resetFallback();
        $this->resetErrorHandlers();

        $g = RequestContext::instance();
        $g->status                   = 200;
        $g->_streaming               = null;
        $g->server                   = [];
        $g->error_render_depth       = 0;
        // Restore the G handler stacks to exactly what they were when this test
        // started (do NOT zero them — see setUp note on the uopz override).
        $g->exception_handlers_stack = $this->savedExcStack;
        $g->error_handlers_stack     = $this->savedErrStack;

        \OpenSwoole\Runtime::enableCoroutine(0);
        App::superglobals(true);
        parent::tearDown();
    }

    private function resetFallback(): void
    {
        $ref = new \ReflectionClass(App::class);
        $p = $ref->getProperty('fallback_handler');
        $p->setAccessible(true);
        $p->setValue(null, null);
    }

    private function resetErrorHandlers(): void
    {
        $ref = new \ReflectionClass(App::class);
        $p = $ref->getProperty('error_handlers');
        $p->setAccessible(true);
        $p->setValue(null, []);
    }

    private function dispatch(string $uri, string $method = 'GET', string $accept = ''): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->server['REQUEST_URI']    = $uri;
        $g->server['REQUEST_METHOD'] = $method;
        $g->server['HTTP_HOST']      = 'localhost';
        if ($accept !== '') {
            $g->server['HTTP_ACCEPT'] = $accept;
        }

        $finalHandler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('unhandled', 500);
            }
        };
        return (new ResponseMiddleware())->process(new ServerRequest($uri, $method), $finalHandler);
    }

    // ── dispatchRoute exact-match + HEAD content-length ───────────────

    public function testExactStringRoute(): void
    {
        $this->assertSame('xtra-body', (string) $this->dispatch('/xtra/str')->getBody());
    }

    public function testExactArrayRouteJson(): void
    {
        $res = $this->dispatch('/xtra/arr');
        $this->assertSame(['x' => 1], json_decode((string) $res->getBody(), true));
    }

    public function testHeadOnStringRouteEmitsContentLength(): void
    {
        $res = $this->dispatch('/xtra/str', 'HEAD');
        $this->assertSame('', (string) $res->getBody());
        $this->assertSame((string) strlen('xtra-body'), $this->headerValue('Content-Length'));
    }

    public function testHeadOnArrayRouteEmitsContentLength(): void
    {
        $res = $this->dispatch('/xtra/arr', 'HEAD');
        $this->assertSame('', (string) $res->getBody());
        $expected = (string) strlen((string) json_encode(['x' => 1]));
        $this->assertSame($expected, $this->headerValue('Content-Length'));
    }

    public function testIntStatusOkNoErrorPage(): void
    {
        $this->assertSame(201, $this->dispatch('/xtra/intok')->getStatusCode());
    }

    public function testIntStatus404RoutesThroughRenderError(): void
    {
        $this->assertSame(404, $this->dispatch('/xtra/int404')->getStatusCode());
    }

    public function testOutOfRangeIntCoercedTo500(): void
    {
        $this->assertSame(500, $this->dispatch('/xtra/intbad')->getStatusCode());
    }

    public function testPsrResponseReturnedDirectly(): void
    {
        $res = $this->dispatch('/xtra/psr');
        $this->assertSame(222, $res->getStatusCode());
        $this->assertSame('psr-direct', (string) $res->getBody());
    }

    public function testParamRouteViaRegexLoop(): void
    {
        $this->assertSame('p=99', (string) $this->dispatch('/xtra/p/99')->getBody());
    }

    // ── handler throw → renderError(500) ──────────────────────────────

    public function testHandlerThrowRenders500(): void
    {
        $this->assertSame(500, $this->dispatch('/xtra/throw')->getStatusCode());
    }

    public function testHandlerThrowWithTraceWhenDisplayErrorsOn(): void
    {
        App::displayErrors(true);
        $res = $this->dispatch('/xtra/throw');
        $this->assertStringContainsString('boom-xtra', (string) $res->getBody());
    }

    public function testHandlerThrowWithoutTraceWhenDisplayErrorsOff(): void
    {
        App::displayErrors(false);
        $res = $this->dispatch('/xtra/throw');
        $this->assertSame(500, $res->getStatusCode());
        $this->assertStringNotContainsString('boom-xtra', (string) $res->getBody());
    }

    public function testUserExceptionHandlerRunsBeforeDefaultErrorPage(): void
    {
        $g = RequestContext::instance();
        $g->exception_handlers_stack = [function (\Throwable $e) {
            echo 'caught:' . $e->getMessage();
        }];
        $res = $this->dispatch('/xtra/throw');
        $this->assertStringContainsString('caught:boom-xtra', (string) $res->getBody());
    }

    // ── dispatchRawRoute ─────────────────────────────────────────────

    public function testRawRouteString(): void
    {
        $this->assertSame('raw-str', (string) $this->dispatch('/xtra/raw/str')->getBody());
    }

    public function testRawRouteArrayJson(): void
    {
        $res = $this->dispatch('/xtra/raw/arr');
        $this->assertSame(['r' => true], json_decode((string) $res->getBody(), true));
    }

    public function testRawRouteIntStatus(): void
    {
        $this->assertSame(404, $this->dispatch('/xtra/raw/int')->getStatusCode());
    }

    public function testRawRoutePsrReturnedDirectly(): void
    {
        $res = $this->dispatch('/xtra/raw/psr');
        $this->assertSame(233, $res->getStatusCode());
        $this->assertSame('raw-psr', (string) $res->getBody());
    }

    public function testRawRouteHeadContentLength(): void
    {
        $this->dispatch('/xtra/raw/str', 'HEAD');
        $this->assertSame((string) strlen('raw-str'), $this->headerValue('Content-Length'));
    }

    public function testRawRouteThrowRenders500(): void
    {
        $this->assertSame(500, $this->dispatch('/xtra/raw/throw')->getStatusCode());
    }

    public function testRawRouteGeneratorStreams(): void
    {
        $g = RequestContext::instance();
        $captured = [];
        \OpenSwoole\Coroutine::run(function () use (&$captured, $g) {
            $this->dispatch('/xtra/raw/gen');
            \assert($g->openswoole_response instanceof FakeOpenSwooleResponse);
            foreach ($g->openswoole_response->log as $e) {
                if (($e[0] ?? null) === 'write') {
                    $captured[] = $e[1];
                }
            }
        });
        $this->assertContains('r1', $captured);
        $this->assertContains('r2', $captured);
    }

    // ── OPTIONS / TRACE / 404 / fallback ─────────────────────────────

    public function testOptionsListsAllowForPostRoute(): void
    {
        $res = $this->dispatch('/xtra/postonly', 'OPTIONS');
        $this->assertSame(204, $res->getStatusCode());
        $this->assertStringContainsString('POST', $this->headerValue('Allow'));
        $this->assertStringContainsString('OPTIONS', $this->headerValue('Allow'));
    }

    public function testGetOnPostOnlyIs405(): void
    {
        // RFC 9110 §15.5.6: the resource exists (POST route) but GET isn't
        // allowed → 405 Method Not Allowed + an Allow header.
        $res = $this->dispatch('/xtra/postonly', 'GET');
        $this->assertSame(405, $res->getStatusCode());
        $this->assertStringContainsString('POST', $this->headerValue('Allow'));
    }

    public function testUnmatchedWithoutFallbackIs404(): void
    {
        $this->assertSame(404, $this->dispatch('/xtra/does-not-exist')->getStatusCode());
    }

    public function testFallbackHandlerDispatchedForUnmatched(): void
    {
        $app = App::instance();
        \assert($app !== null);
        $app->setFallback(fn() => 'fallback-body');
        $res = $this->dispatch('/xtra/no-such-route-here');
        $this->assertSame('fallback-body', (string) $res->getBody());
    }

    public function testFallbackHandlerCanReturnArray(): void
    {
        $app = App::instance();
        \assert($app !== null);
        $app->setFallback(fn() => ['fb' => 1]);
        $res = $this->dispatch('/xtra/unmatched-array');
        $this->assertSame(['fb' => 1], json_decode((string) $res->getBody(), true));
    }

    // ── renderError negotiation + custom handler + ServerAdmin ────────

    public function testRenderErrorHtmlByDefault(): void
    {
        $res = $this->dispatch('/xtra/missing-html', 'GET', 'text/html');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('404', (string) $res->getBody());
    }

    public function testRenderErrorJsonNegotiated(): void
    {
        $res = $this->dispatch('/xtra/missing-json', 'GET', 'application/json');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertSame('application/json', $res->getHeaderLine('Content-Type'));
        $decoded = json_decode((string) $res->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(404, $decoded['error']['status']);
    }

    public function testRenderErrorServerAdminLineHtml(): void
    {
        App::serverAdmin('admin@example.test');
        $res = $this->dispatch('/xtra/missing-admin', 'GET', 'text/html');
        $this->assertStringContainsString('admin@example.test', (string) $res->getBody());
    }

    public function testRenderErrorServerAdminContactJson(): void
    {
        App::serverAdmin('admin@example.test');
        $res = $this->dispatch('/xtra/missing-admin-json', 'GET', 'application/json');
        $decoded = json_decode((string) $res->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame('admin@example.test', $decoded['error']['contact']);
    }

    public function testCustomErrorHandlerDispatched(): void
    {
        $app = App::instance();
        \assert($app !== null);
        $app->setErrorHandler(404, fn($status) => "custom-$status-page");
        $res = $this->dispatch('/xtra/missing-custom');
        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('custom-404-page', (string) $res->getBody());
    }

    public function testCatchAllErrorHandlerDispatched(): void
    {
        $app = App::instance();
        \assert($app !== null);
        $app->setErrorHandler(fn($status) => ['err' => $status]);
        $res = $this->dispatch('/xtra/missing-catchall');
        $decoded = json_decode((string) $res->getBody(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(404, $decoded['err']);
    }

    public function testErrorHandlerThatThrowsFallsBackToDefault(): void
    {
        $app = App::instance();
        \assert($app !== null);
        $app->setErrorHandler(404, function () {
            throw new \RuntimeException('handler-broke');
        });
        $res = $this->dispatch('/xtra/missing-bad-handler', 'GET', 'text/html');
        // Falls back to the framework default body for the original status.
        $this->assertSame(404, $res->getStatusCode());
        $this->assertStringContainsString('404', (string) $res->getBody());
    }

    // ── traversal / null-byte → renderError(400) ──────────────────────

    public function testTraversalRejected400(): void
    {
        $this->assertSame(400, $this->dispatch('/../../etc/passwd')->getStatusCode());
    }

    public function testTraceRefused405(): void
    {
        $this->assertSame(405, $this->dispatch('/xtra/str', 'TRACE')->getStatusCode());
    }

    public function testTraceAllowedWhenEnabled(): void
    {
        App::traceEnabled(true);
        // With TRACE enabled it's no longer short-circuited by the XST guard;
        // the path matches a GET route but has no TRACE handler, so it's now a
        // proper 405 Method Not Allowed (RFC 9110 §15.5.6) rather than a 404.
        $this->assertSame(405, $this->dispatch('/xtra/str', 'TRACE')->getStatusCode());
    }

    // ── strip_trailing_slash redirect ─────────────────────────────────

    public function testStripTrailingSlashRedirects301(): void
    {
        App::stripTrailingSlash(true);
        $res = $this->dispatch('/xtra/some-non-dir-path/', 'GET');
        $this->assertSame(301, $res->getStatusCode());
        $location = '';
        $g = RequestContext::instance();
        \assert($g->openswoole_response instanceof FakeOpenSwooleResponse);
        // redirect() goes through the wrapper → Location header buffered.
        $location = $this->headerValue('Location');
        $this->assertStringContainsString('/xtra/some-non-dir-path', $location);
        $this->assertStringEndsNotWith('/', rtrim($location, '?'));
    }

    // ── App::ws() registration (no server boot) ───────────────────────

    public function testWsRegistersHandlerMap(): void
    {
        $app = App::instance();
        \assert($app !== null);
        $onMsg   = function ($server, $frame, $g) {};
        $onOpen  = function ($server, $request, $g) {};
        $onClose = function ($server, $fd, $g) {};
        $app->ws('/xtra/ws/chat', $onMsg, $onOpen, $onClose);

        $routes = $app->wsRoutes();
        $this->assertArrayHasKey('/xtra/ws/chat', $routes);
        $this->assertSame($onMsg, $routes['/xtra/ws/chat']['message']);
        $this->assertSame($onOpen, $routes['/xtra/ws/chat']['open']);
        $this->assertSame($onClose, $routes['/xtra/ws/chat']['close']);
    }

    public function testWsRegistersWithNullLifecycleCallbacks(): void
    {
        $app = App::instance();
        \assert($app !== null);
        $app->ws('/xtra/ws/notify', fn() => null);
        $routes = $app->wsRoutes();
        $this->assertArrayHasKey('/xtra/ws/notify', $routes);
        $this->assertNull($routes['/xtra/ws/notify']['open']);
        $this->assertNull($routes['/xtra/ws/notify']['close']);
    }

    // ── App::include / tryInclude / serveDirectory (in-process) ───────

    public function testIncludeMissingFileReturns403(): void
    {
        // realpath() fails for a non-existent path → include() returns 403.
        $this->assertSame(403, App::include('/no/such/file/here.php'));
    }

    public function testIncludeTraversalRejected403(): void
    {
        $this->assertSame(403, App::include('/../app.php'));
    }

    public function testTryIncludeMissingReturnsNull(): void
    {
        $this->assertNull(App::tryInclude('/no/such/file.php'));
    }

    public function testIncludeRealPublicFileRunsInProcess(): void
    {
        // public/index.php is a 3-line App::render call site. Running it in
        // process exercises include() → executeFile() and the $_SERVER preamble.
        $g = RequestContext::instance();
        $g->server['REQUEST_URI']    = '/';
        $g->server['REQUEST_METHOD'] = 'GET';
        $result = App::include('/index.php');
        // index.php echoes the rendered master template (no explicit return) →
        // buffered output surfaced as a string body.
        $this->assertIsString($result);
        $this->assertNotSame('', $result);
        // $_SERVER preamble was populated by include().
        $this->assertSame('/index.php', $g->server['PHP_SELF']);
        $this->assertSame('/index.php', $g->server['SCRIPT_NAME']);
    }

    public function testServeDirectoryRedirectsWithoutTrailingSlash(): void
    {
        App::$directory_slash = true;
        $app = App::instance();
        \assert($app !== null);
        $g = RequestContext::instance();
        $g->server['REQUEST_URI'] = '/somedir';
        // No trailing slash + directory_slash on → 301 redirect emitted, returns null.
        $result = $app->serveDirectory('somedir', 'somedir');
        $this->assertNull($result);
        $this->assertTrue($g->_streaming);
    }

    public function testServeDirectoryNoIndexReturnsFalse(): void
    {
        App::$directory_slash = false;
        $app = App::instance();
        \assert($app !== null);
        $g = RequestContext::instance();
        $g->server['REQUEST_URI'] = '/no-index-dir/';
        // directory_slash off → skips redirect; no index file under that dir → false.
        $result = $app->serveDirectory('no-index-dir-xyz', 'no-index-dir-xyz');
        $this->assertFalse($result);
    }

    // ── emitStatus / coerceStatusCode / reasonPhrase edge codes ───────

    public function testCoerceValidStatusUnchanged(): void
    {
        $this->assertSame(451, App::coerceStatusCode(451));
        $this->assertSame(100, App::coerceStatusCode(100));
        $this->assertSame(599, App::coerceStatusCode(599));
    }

    public function testCoerceOutOfRangeBecomes500(): void
    {
        $this->assertSame(500, App::coerceStatusCode(0));
        $this->assertSame(500, App::coerceStatusCode(-1));
        $this->assertSame(500, App::coerceStatusCode(600));
        $this->assertSame(500, App::coerceStatusCode(99999));
    }

    public function testReasonPhraseKnownAndUnknown(): void
    {
        $this->assertSame('Not Found', App::reasonPhrase(404));
        $this->assertSame('', App::reasonPhrase(799));
    }

    public function testEmitStatusKnownCodeUsesTwoArgForm(): void
    {
        $fake = new FakeOpenSwooleResponse();
        App::emitStatus($fake, 451);
        $this->assertSame(['status', 451, 'Unavailable For Legal Reasons'], $fake->log[0]);
    }

    public function testEmitStatusUnknownCodeUsesOneArgForm(): void
    {
        $fake = new FakeOpenSwooleResponse();
        App::emitStatus($fake, 799);
        $this->assertSame(['status', 799, ''], $fake->log[0]);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function headerValue(string $name): string
    {
        foreach (\ZealPHP\response_headers_list() as $pair) {
            if (strcasecmp((string) $pair[0], $name) === 0) {
                return (string) $pair[1];
            }
        }
        return '';
    }
}

<?php
namespace ZealPHP\Tests\Unit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use OpenSwoole\Core\Psr\Response;
use ZealPHP\App;
use ZealPHP\Middleware\Pipeline\MiddlewareFrame;
use ZealPHP\Tests\TestCase;

/**
 * Per-route middleware: named-alias registry, the `middleware:` route option,
 * route groups, the onion (MiddlewareFrame) ordering + short-circuit, and the
 * describeRoutes() introspection that backs the visualizer.
 */
class RouteMiddlewareTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        App::superglobals(true);
        self::$app = App::init('0.0.0.0', 19997, ZEALPHP_ROOT);
    }

    protected function setUp(): void
    {
        App::$middleware_aliases = [];
    }

    /** A no-op middleware that records its tag into a shared log, then continues. */
    private function tagging(string $tag, array &$log): MiddlewareInterface
    {
        return new class($tag, $log) implements MiddlewareInterface {
            /** @param array<int,string> $log */
            public function __construct(private string $tag, private array &$log)
            {
            }
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->log[] = 'enter:' . $this->tag;
                $response = $handler->handle($request);
                $this->log[] = 'leave:' . $this->tag;
                return $response;
            }
        };
    }

    /** A middleware that short-circuits with a fixed status, never calling the handler. */
    private function shortCircuit(int $status): MiddlewareInterface
    {
        return new class($status) implements MiddlewareInterface {
            public function __construct(private int $status)
            {
            }
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response('', $this->status);
            }
        };
    }

    /** @return array<string,mixed> */
    private function lastRoute(): array
    {
        $routes = self::$app->routes();
        $last = end($routes);
        return is_array($last) ? $last : [];
    }

    // ───────────────────────── alias registry ─────────────────────────

    public function testAliasFactoryResolvesToInstance(): void
    {
        $log = [];
        $mw = $this->tagging('a', $log);
        App::middlewareAlias('mw-a', fn() => $mw);

        $chain = App::compileMiddlewareChain(['mw-a']);
        $this->assertCount(1, $chain);
        $this->assertSame($mw, $chain[0]);
    }

    public function testAliasReadyInstancePassesThrough(): void
    {
        $log = [];
        $mw = $this->tagging('b', $log);
        App::middlewareAlias('mw-b', $mw);

        $this->assertSame([$mw], App::compileMiddlewareChain(['mw-b']));
    }

    public function testParameterisedAliasPassesArgsToFactory(): void
    {
        $seen = null;
        App::middlewareAlias('throttle', function ($n = '60') use (&$seen) {
            $seen = $n;
            return new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $r, RequestHandlerInterface $h): ResponseInterface
                {
                    return $h->handle($r);
                }
            };
        });

        App::compileMiddlewareChain(['throttle:120']);
        $this->assertSame('120', $seen);
    }

    public function testUnknownAliasThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::compileMiddlewareChain(['does-not-exist']);
    }

    public function testFactoryReturningNonMiddlewareThrows(): void
    {
        App::middlewareAlias('bad', fn() => 'not a middleware');
        $this->expectException(\InvalidArgumentException::class);
        App::compileMiddlewareChain(['bad']);
    }

    public function testInstanceInSpecPassesThroughCompile(): void
    {
        $log = [];
        $mw = $this->tagging('x', $log);
        $this->assertSame([$mw], App::compileMiddlewareChain([$mw]));
    }

    // ─────────────────────── normalizeMiddlewareSpec ───────────────────────

    public function testNormalizeWrapsSingleInstance(): void
    {
        $log = [];
        $mw = $this->tagging('s', $log);
        $this->assertSame([$mw], App::normalizeMiddlewareSpec($mw));
    }

    public function testNormalizeWrapsSingleString(): void
    {
        $this->assertSame(['auth'], App::normalizeMiddlewareSpec('auth'));
    }

    public function testNormalizeRejectsInvalidEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::normalizeMiddlewareSpec([42]);
    }

    // ─────────────────────── route option storage ───────────────────────

    public function testRouteStoresMiddlewareNamedArg(): void
    {
        self::$app->route('/mw-named-' . uniqid(), middleware: ['auth'], handler: fn() => '');
        $this->assertSame(['auth'], $this->lastRoute()['middleware']);
    }

    public function testRouteStoresMiddlewareArrayOption(): void
    {
        self::$app->route('/mw-array-' . uniqid(), ['middleware' => ['auth', 'admin']], fn() => '');
        $this->assertSame(['auth', 'admin'], $this->lastRoute()['middleware']);
    }

    public function testRouteCombinesOptionAndNamedMiddleware(): void
    {
        self::$app->route(
            '/mw-combo-' . uniqid(),
            ['middleware' => ['from-option']],
            fn() => '',
            middleware: ['from-named']
        );
        $this->assertSame(['from-option', 'from-named'], $this->lastRoute()['middleware']);
    }

    public function testRouteWithoutMiddlewareStoresEmptyList(): void
    {
        self::$app->route('/mw-none-' . uniqid(), fn() => '');
        $this->assertSame([], $this->lastRoute()['middleware']);
    }

    // ───────────────────────────── groups ─────────────────────────────

    public function testGroupAppliesPrefixAndMiddleware(): void
    {
        $token = uniqid();
        self::$app->group("/grp-$token", ['auth'], function ($g) {
            $g->route('/users', fn() => '');
        });
        $route = $this->lastRoute();
        $this->assertSame("/grp-$token/users", $route['path']);
        $this->assertSame(['auth'], $route['middleware']);
    }

    public function testGroupMiddlewareWrapsOutsideRouteMiddleware(): void
    {
        $token = uniqid();
        self::$app->group("/grp2-$token", ['group-mw'], function ($g) {
            $g->route('/x', middleware: ['route-mw'], handler: fn() => '');
        });
        // group middleware first (outermost), then the route's own.
        $this->assertSame(['group-mw', 'route-mw'], $this->lastRoute()['middleware']);
    }

    public function testGroupWithoutMiddlewareShorthand(): void
    {
        $token = uniqid();
        self::$app->group("/grp3-$token", function ($g) {
            $g->route('/y', fn() => '');
        });
        $route = $this->lastRoute();
        $this->assertSame("/grp3-$token/y", $route['path']);
        $this->assertSame([], $route['middleware']);
    }

    public function testNestedGroupComposesPrefixAndMiddleware(): void
    {
        $token = uniqid();
        self::$app->group("/outer-$token", ['a'], function ($g) {
            $g->group('/inner', ['b'], function ($g) {
                $g->route('/z', middleware: ['c'], handler: fn() => '');
            });
        });
        $route = $this->lastRoute();
        $this->assertSame("/outer-$token/inner/z", $route['path']);
        $this->assertSame(['a', 'b', 'c'], $route['middleware']);
    }

    public function testGroupNsRoutePrefixesNamespace(): void
    {
        $token = uniqid();
        self::$app->group("/api-$token", ['auth'], function ($g) {
            $g->nsRoute('v1', '/ping', fn() => '');
        });
        $route = $this->lastRoute();
        $this->assertStringContainsString("api-$token", $route['path']);
        $this->assertStringContainsString('v1/ping', $route['path']);
        $this->assertSame(['auth'], $route['middleware']);
    }

    // ───────────────────── onion ordering + short-circuit ─────────────────────

    public function testMiddlewareFrameRunsOutermostFirst(): void
    {
        $log = [];
        // Build the onion the way dispatchWithMiddleware does: first-listed
        // ends up outermost. terminal records 'handler'.
        $terminal = new class($log) implements RequestHandlerInterface {
            /** @param array<int,string> $log */
            public function __construct(private array &$log)
            {
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->log[] = 'handler';
                return new Response('ok', 200);
            }
        };
        $chain = [$this->tagging('m0', $log), $this->tagging('m1', $log), $this->tagging('m2', $log)];
        $handler = $terminal;
        foreach (array_reverse($chain) as $mw) {
            $handler = new MiddlewareFrame($mw, $handler);
        }
        $response = $handler->handle($this->serverRequest());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['enter:m0', 'enter:m1', 'enter:m2', 'handler', 'leave:m2', 'leave:m1', 'leave:m0'],
            $log
        );
    }

    public function testMiddlewareFrameShortCircuitSkipsHandler(): void
    {
        $log = [];
        $terminal = new class($log) implements RequestHandlerInterface {
            /** @param array<int,string> $log */
            public function __construct(private array &$log)
            {
            }
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->log[] = 'handler';
                return new Response('ok', 200);
            }
        };
        // m0 wraps a short-circuit (m1). m0 should still wrap; the short-circuit
        // returns 403 without ever reaching the terminal.
        $chain = [$this->tagging('m0', $log), $this->shortCircuit(403)];
        $handler = $terminal;
        foreach (array_reverse($chain) as $mw) {
            $handler = new MiddlewareFrame($mw, $handler);
        }
        $response = $handler->handle($this->serverRequest());

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['enter:m0', 'leave:m0'], $log);
        $this->assertNotContains('handler', $log);
    }

    // ───────────────────────── describeRoutes ─────────────────────────

    public function testDescribeRoutesReportsGlobalAndRouteChains(): void
    {
        $token = uniqid();
        $log = [];
        App::middlewareAlias('vis-auth', $this->tagging('auth', $log));
        self::$app->route("/vis-$token", middleware: ['vis-auth'], handler: fn() => '');

        $desc = self::$app->describeRoutes();

        $this->assertArrayHasKey('global', $desc);
        $this->assertArrayHasKey('aliases', $desc);
        $this->assertArrayHasKey('routes', $desc);
        // The router is always the innermost global frame.
        $this->assertSame('ResponseMiddleware (router)', end($desc['global']));
        $this->assertContains('vis-auth', $desc['aliases']);

        $mine = null;
        foreach ($desc['routes'] as $r) {
            if ($r['path'] === "/vis-$token") {
                $mine = $r;
                break;
            }
        }
        $this->assertNotNull($mine);
        $this->assertSame(['vis-auth'], $mine['middleware']);
        $this->assertNotEmpty($mine['methods']);
    }

    private function serverRequest(): ServerRequestInterface
    {
        return new \OpenSwoole\Core\Psr\ServerRequest('/', 'GET');
    }
}

<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\ResponseMiddleware;
use ZealPHP\Tests\TestCase;
use ZealPHP\Tests\Unit\HTTP\FakeOpenSwooleResponse;

/**
 * Per-route CGI backend (`backend:` route option + `App::cgiBackendAlias()`):
 * spec resolution (bare mode / alias / inline config), validation (reject the
 * COROUTINE-SCHEDULER lifecycle family + bad modes + `fcgi` without address),
 * route-struct storage, `describeRoutes()` introspection, the `applyRouteBackend()`
 * override-over-resolve helper, and the `dispatchRoute()` wrap that makes the
 * route's backend the request-scoped override `App::include()` reads.
 */
class PerRouteBackendTest extends TestCase
{
    private static App $app;

    public static function setUpBeforeClass(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        self::$app = App::instance() ?? App::init('127.0.0.1', 19996, ZEALPHP_ROOT);
    }

    protected function setUp(): void
    {
        App::$cgi_backend_aliases = [];
        RequestContext::instance()->cgi_backend_override = null;
    }

    /** The `backend` of the most recently registered route. */
    private function lastRouteBackend(): ?array
    {
        $routes = self::$app->routes();
        /** @var array<string,mixed> $last */
        $last = $routes[array_key_last($routes)];
        $b = $last['backend'] ?? null;
        return is_array($b) ? $b : null;
    }

    // ── bare-mode strings ─────────────────────────────────────────────

    /** @return array<int,array{0:string}> */
    public static function bareModes(): array
    {
        return [['pool'], ['proc'], ['fork']];
    }

    #[DataProvider('bareModes')]
    public function testBareModeStringResolves(string $mode): void
    {
        self::$app->route('/be-bare-' . $mode . '-' . uniqid(), backend: $mode, handler: fn() => '');
        $this->assertSame(['mode' => $mode], $this->lastRouteBackend());
    }

    public function testBareFcgiStringThrowsWithoutAddress(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fcgi.*requires an .address./');
        self::$app->route('/be-fcgi-bare-' . uniqid(), backend: 'fcgi', handler: fn() => '');
    }

    // ── inline config arrays ──────────────────────────────────────────

    public function testInlineProcWithInterpreter(): void
    {
        self::$app->route(
            '/be-py-' . uniqid(),
            backend: ['mode' => 'proc', 'interpreter' => '/usr/bin/python3'],
            handler: fn() => ''
        );
        $this->assertSame(['mode' => 'proc', 'interpreter' => '/usr/bin/python3'], $this->lastRouteBackend());
    }

    public function testInlineFcgiWithAddressAndParams(): void
    {
        self::$app->route(
            '/be-fpm-' . uniqid(),
            backend: ['mode' => 'fcgi', 'address' => 'unix:/run/php-fpm.sock', 'fcgi_params' => ['X' => 'y']],
            handler: fn() => ''
        );
        $this->assertSame(
            ['mode' => 'fcgi', 'address' => 'unix:/run/php-fpm.sock', 'fcgi_params' => ['X' => 'y']],
            $this->lastRouteBackend()
        );
    }

    public function testInlineFcgiWithoutAddressThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/fcgi.*requires an .address./');
        self::$app->route('/be-fpm-bad-' . uniqid(), backend: ['mode' => 'fcgi'], handler: fn() => '');
    }

    public function testInlineUnknownModeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/must be 'pool', 'proc', 'fork', or 'fcgi'/");
        self::$app->route('/be-bad-' . uniqid(), backend: ['mode' => 'cgi'], handler: fn() => '');
    }

    // ── the COROUTINE-SCHEDULER family is rejected (per-process only) ──

    /** @return array<int,array{0:string}> */
    public static function lifecycleModes(): array
    {
        return [['coroutine'], ['coroutine-legacy'], ['legacy-cgi'], ['mixed']];
    }

    #[DataProvider('lifecycleModes')]
    public function testLifecycleModeStringRejected(string $mode): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/process-wide lifecycle mode/');
        self::$app->route('/be-life-' . $mode . '-' . uniqid(), backend: $mode, handler: fn() => '');
    }

    public function testLifecycleModeInArrayRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/process-wide lifecycle mode/');
        self::$app->route('/be-life-arr-' . uniqid(), backend: ['mode' => 'coroutine-legacy'], handler: fn() => '');
    }

    // ── $options['backend'] form + precedence ─────────────────────────

    public function testOptionsArrayBackendForm(): void
    {
        self::$app->route('/be-opt-' . uniqid(), ['backend' => 'fork'], fn() => '');
        $this->assertSame(['mode' => 'fork'], $this->lastRouteBackend());
    }

    public function testNamedArgWinsOverOptions(): void
    {
        self::$app->route('/be-prec-' . uniqid(), ['backend' => 'pool'], handler: fn() => '', backend: 'fork');
        $this->assertSame(['mode' => 'fork'], $this->lastRouteBackend());
    }

    public function testRouteWithoutBackendIsNull(): void
    {
        self::$app->route('/be-none-' . uniqid(), fn() => '');
        $this->assertNull($this->lastRouteBackend());
    }

    public function testEmptyStringBackendResolvesNull(): void
    {
        self::$app->route('/be-empty-' . uniqid(), backend: '', handler: fn() => '');
        $this->assertNull($this->lastRouteBackend());
    }

    // ── all four registrars carry the backend ─────────────────────────

    public function testNsRouteStoresBackend(): void
    {
        self::$app->nsRoute('myns', '/ns-' . uniqid(), backend: 'fork', handler: fn() => '');
        $this->assertSame(['mode' => 'fork'], $this->lastRouteBackend());
    }

    public function testNsPathRouteStoresBackend(): void
    {
        self::$app->nsPathRoute('myns', '/nsp-' . uniqid(), backend: 'pool', handler: fn() => '');
        $this->assertSame(['mode' => 'pool'], $this->lastRouteBackend());
    }

    public function testPatternRouteStoresBackend(): void
    {
        self::$app->patternRoute('#^/be-pat-' . uniqid() . '$#', backend: 'proc', handler: fn() => '');
        $this->assertSame(['mode' => 'proc'], $this->lastRouteBackend());
    }

    // ── route groups thread the backend (named arg + options) ─────────

    public function testGroupRouteBackendNamedArg(): void
    {
        self::$app->group('/grp-' . uniqid(), [], function ($g): void {
            $g->route('/x', backend: 'fork', handler: fn() => '');
        });
        $this->assertSame(['mode' => 'fork'], $this->lastRouteBackend());
    }

    public function testGroupNsPathRouteBackend(): void
    {
        self::$app->group('/grp2-' . uniqid(), [], function ($g): void {
            $g->nsPathRoute('gns', '/y', backend: 'pool', handler: fn() => '');
        });
        $this->assertSame(['mode' => 'pool'], $this->lastRouteBackend());
    }

    public function testGroupPatternRouteBackendViaOptions(): void
    {
        self::$app->group('/grp3-' . uniqid(), [], function ($g): void {
            $g->patternRoute('#^/grp3-z$#', ['backend' => 'proc'], fn() => '');
        });
        $this->assertSame(['mode' => 'proc'], $this->lastRouteBackend());
    }

    public function testResetCgiBackendsClearsAliases(): void
    {
        App::cgiBackendAlias('temp-alias', 'fork');
        $this->assertArrayHasKey('temp-alias', App::$cgi_backend_aliases);
        App::resetCgiBackends();
        $this->assertSame([], App::$cgi_backend_aliases);
    }

    // ── cgiBackendAlias registry ──────────────────────────────────────

    public function testAliasBareModeShorthand(): void
    {
        App::cgiBackendAlias('wp-fork', 'fork');
        self::$app->route('/be-alias-' . uniqid(), backend: 'wp-fork', handler: fn() => '');
        $this->assertSame(['mode' => 'fork'], $this->lastRouteBackend());
    }

    public function testAliasArrayConfig(): void
    {
        App::cgiBackendAlias('py', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3']);
        self::$app->route('/be-alias2-' . uniqid(), backend: 'py', handler: fn() => '');
        $this->assertSame(['mode' => 'proc', 'interpreter' => '/usr/bin/python3'], $this->lastRouteBackend());
    }

    public function testAliasRejectsReservedName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a reserved mode name/');
        App::cgiBackendAlias('fork', ['mode' => 'fork']);
    }

    public function testAliasRejectsEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        App::cgiBackendAlias('  ', 'fork');
    }

    public function testAliasRejectsStringNonMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must be a bare mode/');
        App::cgiBackendAlias('weird', 'not-a-mode');
    }

    public function testAliasRejectsLifecycleMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/process-wide lifecycle mode/');
        App::cgiBackendAlias('legacy', ['mode' => 'legacy-cgi']);
    }

    public function testUnknownAliasThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown route backend alias/');
        self::$app->route('/be-unknown-' . uniqid(), backend: 'nope-not-registered', handler: fn() => '');
    }

    // ── describeRoutes() introspection ────────────────────────────────

    public function testDescribeRoutesIncludesBackend(): void
    {
        $path = '/be-describe-' . uniqid();
        self::$app->route($path, backend: ['mode' => 'fork'], handler: fn() => '');
        $described = self::$app->describeRoutes();
        $match = null;
        foreach ($described['routes'] as $r) {
            if (($r['path'] ?? null) === $path) {
                $match = $r;
                break;
            }
        }
        $this->assertNotNull($match);
        $this->assertSame(['mode' => 'fork'], $match['backend']);
    }

    // ── applyRouteBackend(): override-over-resolve helper ─────────────

    /** @param array<string,mixed> $cgi @return array<string,mixed> */
    private function applyRouteBackend(array $cgi): array
    {
        $m = new \ReflectionMethod(App::class, 'applyRouteBackend');
        $m->setAccessible(true);
        /** @var array<string,mixed> $out */
        $out = $m->invoke(null, $cgi);
        return $out;
    }

    public function testApplyRouteBackendNoOverridePassesThrough(): void
    {
        RequestContext::instance()->cgi_backend_override = null;
        $cgi = ['backend' => ['mode' => 'pool'], 'mayExecute' => false];
        $this->assertSame($cgi, $this->applyRouteBackend($cgi));
    }

    public function testApplyRouteBackendOverrideForcesBackendAndExec(): void
    {
        RequestContext::instance()->cgi_backend_override = ['mode' => 'fork'];
        $cgi = ['backend' => ['mode' => 'pool'], 'mayExecute' => false];
        $out = $this->applyRouteBackend($cgi);
        $this->assertSame(['mode' => 'fork'], $out['backend']);
        $this->assertTrue($out['mayExecute'], 'a route that names a backend authorises execution');
    }

    // ── dispatchRoute(): the route backend becomes the request override ──

    public function testDispatchRouteSetsAndClearsOverride(): void
    {
        $fake = new FakeOpenSwooleResponse();
        $g = RequestContext::instance();
        $g->openswoole_response = $fake;
        $g->zealphp_response    = new \ZealPHP\HTTP\Response($fake);
        $g->zealphp_request     = null;
        $g->status              = 200;
        $g->server              = ['REQUEST_URI' => '/be-dispatch', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost'];
        $g->cgi_backend_override = null;

        $seen = null;
        $route = [
            'path'       => '/be-dispatch',
            'pattern'    => '#^/be-dispatch$#',
            'methods'    => ['GET' => 'GET'],
            'handler'    => function () use (&$seen) {
                $seen = RequestContext::instance()->cgi_backend_override;
                return 'ok';
            },
            'param_map'  => [],
            'raw'        => false,
            'middleware' => [],
            'backend'    => ['mode' => 'fork'],
        ];

        $rm = new ResponseMiddleware();
        $rm->dispatchRoute($route, [], 'GET');

        $this->assertSame(['mode' => 'fork'], $seen, 'the handler must see the route backend as the request override');
        $this->assertNull($g->cgi_backend_override, 'the override must be cleared after dispatch');
    }

    public function testDispatchRouteWithoutBackendLeavesOverrideUntouched(): void
    {
        $fake = new FakeOpenSwooleResponse();
        $g = RequestContext::instance();
        $g->openswoole_response = $fake;
        $g->zealphp_response    = new \ZealPHP\HTTP\Response($fake);
        $g->zealphp_request     = null;
        $g->status              = 200;
        $g->server              = ['REQUEST_URI' => '/be-plain', 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'localhost'];
        $g->cgi_backend_override = null;

        $seen = 'unset';
        $route = [
            'path'       => '/be-plain',
            'pattern'    => '#^/be-plain$#',
            'methods'    => ['GET' => 'GET'],
            'handler'    => function () use (&$seen) {
                $seen = RequestContext::instance()->cgi_backend_override;
                return 'ok';
            },
            'param_map'  => [],
            'raw'        => false,
            'middleware' => [],
        ];

        $rm = new ResponseMiddleware();
        $rm->dispatchRoute($route, [], 'GET');

        $this->assertNull($seen, 'no route backend → handler sees null override (fast path)');
    }
}

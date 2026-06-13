<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\HTTP;
use ZealPHP\HTTP\Response;
use ZealPHP\Middleware\LocationHeaderMiddleware;
use ZealPHP\Middleware\RedirectMiddleware;
use ZealPHP\Middleware\RequestHeaderMiddleware;
use ZealPHP\Middleware\ScopedMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Store;
use ZealPHP\Tests\TestCase;
use ZealPHP\WSRouter;

/**
 * Fixes for the 2026-06-13 issue wave reported by Guruprasanth-M:
 *   - #412 App::displayErrors() secure-by-default (null → ZEALPHP_DEV)
 *   - #432 Response::redirect() same-origin guard is port-aware
 *   - #433 App::cidrContains() collapses IPv4-mapped IPv6
 *   - #409 RedirectMiddleware preserves the query string
 *   - #429 parallel()/HTTP fall back cleanly without a coroutine scheduler
 */
final class GuruWaveJun13FixesTest extends TestCase
{
    // ── #412 — display_errors secure-by-default ──────────────────────────

    public function testDisplayErrorsDefaultsToEnvWhenUnset(): void
    {
        $prev    = App::$display_errors;
        $prevEnv = getenv('ZEALPHP_DEV');
        try {
            // Never explicitly set + no env → secure default OFF.
            App::$display_errors = null;
            putenv('ZEALPHP_DEV');
            $this->assertFalse(App::displayErrors(), 'no env → false (no trace leak)');

            // ZEALPHP_DEV=1 → development trace view on.
            App::$display_errors = null;
            putenv('ZEALPHP_DEV=1');
            $this->assertTrue(App::displayErrors(), 'ZEALPHP_DEV=1 → true');

            // An explicit setter call wins over the env resolution.
            App::displayErrors(false);
            $this->assertFalse(App::displayErrors(), 'explicit false wins over env');
            App::displayErrors(true);
            $this->assertTrue(App::displayErrors(), 'explicit true wins');
        } finally {
            App::$display_errors = $prev;
            if ($prevEnv === false) {
                putenv('ZEALPHP_DEV');
            } else {
                putenv('ZEALPHP_DEV=' . $prevEnv);
            }
        }
    }

    // ── #433 — cidrContains IPv4-mapped IPv6 ─────────────────────────────

    private static function cidr(string $cidr, string $ip): bool
    {
        $m = new \ReflectionMethod(App::class, 'cidrContains');
        $m->setAccessible(true);
        return (bool) $m->invoke(null, $cidr, $ip);
    }

    public function testCidrContainsMatchesMappedIpv4(): void
    {
        // Plain and mapped forms of an in-range IPv4 both match the IPv4 CIDR.
        $this->assertTrue(self::cidr('10.0.0.0/8', '10.0.0.5'));
        $this->assertTrue(self::cidr('10.0.0.0/8', '::ffff:10.0.0.5'), 'mapped v4 must match v4 CIDR (#433)');
        // Out-of-range mapped address still does not match.
        $this->assertFalse(self::cidr('10.0.0.0/8', '::ffff:11.0.0.5'));
        // A genuine IPv6 address never matches an IPv4 CIDR.
        $this->assertFalse(self::cidr('10.0.0.0/8', '2001:db8::1'));
    }

    public function testClientIpTrustsMappedProxyHop(): void
    {
        App::trustedProxies(['10.0.0.0/8']);
        $g = RequestContext::instance();
        // The socket peer arrives in IPv4-mapped form (dual-stack listener).
        $g->server = [
            'REMOTE_ADDR'          => '::ffff:10.0.0.6',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.6',
        ];
        // The mapped 10.0.0.6 hop must be recognised as trusted and skipped,
        // so the real client (198.51.100.7) is returned — not the proxy (#433).
        $this->assertSame('198.51.100.7', App::clientIp());
        App::trustedProxies([]);
    }

    // ── #432 — redirect same-origin guard is port-aware ──────────────────

    private function makeResponse(): Response
    {
        $osResponse = new \OpenSwoole\Http\Response();
        $g = RequestContext::instance();
        $g->status = null;
        $g->server = [];
        $response  = new Response($osResponse);
        $g->zealphp_response = $response;
        return $response;
    }

    public function testSameOriginAbsoluteRedirectOnNonDefaultPortIsAllowed(): void
    {
        $resp = $this->makeResponse();
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => '127.0.0.1:8122'];
        // Previously threw (host-only compare: '127.0.0.1' !== '127.0.0.1:8122').
        $resp->redirect('http://127.0.0.1:8122/dashboard', 302);
        $this->assertSame(302, $g->status);
    }

    public function testCrossHostAbsoluteRedirectStillBlocked(): void
    {
        $resp = $this->makeResponse();
        $g = RequestContext::instance();
        $g->server = ['HTTP_HOST' => '127.0.0.1:8122'];
        $this->expectException(\InvalidArgumentException::class);
        $resp->redirect('http://evil.example/phish', 302);
    }

    public function testIsSameOriginStrictRfc6454(): void
    {
        // #432 strict RFC 6454: same-origin iff (scheme, host, port) all match.
        $resp = $this->makeResponse();
        $g = RequestContext::instance();
        $m = new \ReflectionMethod(Response::class, 'isSameOrigin');
        $m->setAccessible(true);

        // http request on :8081 → only the exact (http, host, 8081) target is same-origin.
        $g->server = ['HTTP_HOST' => 'app.local:8081'];
        $this->assertTrue($m->invoke($resp, 'http://app.local:8081/x'), 'same scheme+host+port');
        $this->assertTrue($m->invoke($resp, '/relative/path'), 'relative → same origin');
        $this->assertFalse($m->invoke($resp, 'http://app.local:8082/x'), 'different PORT (another instance) → cross origin');
        $this->assertFalse($m->invoke($resp, 'https://app.local:8081/x'), 'different SCHEME → cross origin');
        $this->assertFalse($m->invoke($resp, 'http://other.local:8081/x'), 'different HOST → cross origin');

        // https request (HTTPS=on) → https same host on the default 443 is same-origin.
        $g->server = ['HTTP_HOST' => 'secure.local', 'HTTPS' => 'on'];
        $this->assertTrue($m->invoke($resp, 'https://secure.local/x'), 'https request → https target same-origin');
        $this->assertFalse($m->invoke($resp, 'http://secure.local/x'), 'https request → http target cross-origin');
    }

    public function testSplitHostPort(): void
    {
        $resp = $this->makeResponse();
        $m = new \ReflectionMethod(Response::class, 'splitHostPort');
        $m->setAccessible(true);
        $this->assertSame(['127.0.0.1', 8080], $m->invoke($resp, '127.0.0.1:8080'));
        $this->assertSame(['example.com', null], $m->invoke($resp, 'example.com'));
        $this->assertSame(['[::1]', 8080], $m->invoke($resp, '[::1]:8080'));
        $this->assertSame(['[::1]', null], $m->invoke($resp, '[::1]'));
    }

    // ── #423 — keepGlobals boot advisory under coroutine-legacy ──────────

    public function testKeepGlobalsCoroutineLegacyBootCheck(): void
    {
        $prev = [App::$keep_globals, App::$coroutine_globals_isolation, App::$function_isolation];
        try {
            // coroutine-legacy shape: globals-isolation on, function-isolation off.
            App::$keep_globals = true;
            App::$coroutine_globals_isolation = true;
            App::$function_isolation = false;
            $this->assertNotNull(App::keepGlobalsCoroutineLegacyBootCheck());

            // function_isolation path DOES honor keep_globals → no advisory.
            App::$function_isolation = true;
            $this->assertNull(App::keepGlobalsCoroutineLegacyBootCheck());

            // keepGlobals off → never advises.
            App::$keep_globals = false;
            App::$function_isolation = false;
            $this->assertNull(App::keepGlobalsCoroutineLegacyBootCheck());
        } finally {
            [App::$keep_globals, App::$coroutine_globals_isolation, App::$function_isolation] = $prev;
        }
    }

    // ── mutation-kill coverage for the new helpers (#432/#403/#429) ──────

    public function testDefaultPortAllArms(): void
    {
        $resp = $this->makeResponse();
        $m = new \ReflectionMethod(Response::class, 'defaultPort');
        $m->setAccessible(true);
        // Kills MatchArmRemoval on each arm + the default arm.
        $this->assertSame(80, $m->invoke($resp, 'http'));
        $this->assertSame(80, $m->invoke($resp, 'ws'));
        $this->assertSame(443, $m->invoke($resp, 'https'));
        $this->assertSame(443, $m->invoke($resp, 'wss'));
        $this->assertSame(443, $m->invoke($resp, 'HTTPS'), 'case-insensitive (kills UnwrapStrToLower)');
        $this->assertSame(0, $m->invoke($resp, 'ftp'), 'unknown scheme → 0 (kills default-arm change)');
    }

    public function testIsSameOriginSchemeDetectionMatrix(): void
    {
        $resp = $this->makeResponse();
        $g = RequestContext::instance();
        $m = new \ReflectionMethod(Response::class, 'isSameOrigin');
        $m->setAccessible(true);

        // HTTPS flag → request is https (kills the HTTPS coalesce/compare).
        $g->server = ['HTTP_HOST' => 'h.test', 'HTTPS' => 'on'];
        $this->assertTrue($m->invoke($resp, 'https://h.test/x'));
        $this->assertFalse($m->invoke($resp, 'http://h.test/x'));

        // HTTPS=off must NOT count as https (kills the `!== 'off'` arm).
        $g->server = ['HTTP_HOST' => 'h.test', 'HTTPS' => 'off'];
        $this->assertTrue($m->invoke($resp, 'http://h.test/x'));
        $this->assertFalse($m->invoke($resp, 'https://h.test/x'));

        // Uppercase HTTPS=OFF must ALSO not count as https — kills the
        // strtolower-unwrap mutant on the HTTPS flag (without it, 'OFF' !== 'off'
        // would wrongly read as https).
        $g->server = ['HTTP_HOST' => 'h.test', 'HTTPS' => 'OFF'];
        $this->assertFalse($m->invoke($resp, 'https://h.test/x'), 'uppercase OFF → not https');
        $this->assertTrue($m->invoke($resp, 'http://h.test/x'));

        // X-Forwarded-Proto: https → request is https.
        $g->server = ['HTTP_HOST' => 'h.test', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertTrue($m->invoke($resp, 'https://h.test/x'));

        // Uppercase X-Forwarded-Proto must still detect https (kills the
        // strtolower-unwrap mutant on the XFP comparison).
        $g->server = ['HTTP_HOST' => 'h.test', 'HTTP_X_FORWARDED_PROTO' => 'HTTPS'];
        $this->assertTrue($m->invoke($resp, 'https://h.test/x'), 'uppercase XFP → https');

        // SERVER_PORT 443 → request is https even with no flag.
        $g->server = ['HTTP_HOST' => 'h.test', 'SERVER_PORT' => '443'];
        $this->assertTrue($m->invoke($resp, 'https://h.test/x'));

        // Explicit target port compared as int (kills CastInt on target port).
        $g->server = ['HTTP_HOST' => 'h.test:8081'];
        $this->assertTrue($m->invoke($resp, 'http://h.test:8081/x'));
        $this->assertFalse($m->invoke($resp, 'http://h.test:8082/x'));

        // SERVER_NAME fallback + SERVER_PORT when no HTTP_HOST.
        $g->server = ['SERVER_NAME' => 'canon.test', 'SERVER_PORT' => '9000'];
        $this->assertTrue($m->invoke($resp, 'http://canon.test:9000/x'));
        $this->assertFalse($m->invoke($resp, 'http://canon.test:9001/x'));

        // Uppercase target host still matches (kills strcasecmp → strcmp).
        $g->server = ['HTTP_HOST' => 'Host.Test'];
        $this->assertTrue($m->invoke($resp, 'http://host.test/x'));
    }

    public function testCollapseMappedIpArms(): void
    {
        $m = new \ReflectionMethod(App::class, 'collapseMappedIp');
        $m->setAccessible(true);
        $this->assertSame('10.0.0.5', $m->invoke(null, '::ffff:10.0.0.5'), 'mapped → v4');
        $this->assertSame('10.0.0.5', $m->invoke(null, '10.0.0.5'), 'plain v4 unchanged');
        $this->assertSame('2001:db8::1', $m->invoke(null, '2001:db8::1'), 'real v6 unchanged');
        $this->assertSame('not-an-ip', $m->invoke(null, 'not-an-ip'), 'garbage unchanged');
    }

    public function testLocationHeaderBuildUrlComponentMatrix(): void
    {
        $mw = new LocationHeaderMiddleware(8443);
        $m  = new \ReflectionMethod(LocationHeaderMiddleware::class, 'buildUrl');
        $m->setAccessible(true);
        // Each variant changes a distinct concat operand → kills Concat /
        // ConcatOperandRemoval for scheme, userinfo, host, port, path, query, fragment.
        $this->assertSame('http://h/p', $m->invoke($mw, (array) parse_url('http://h/p')));
        $this->assertSame('http://h:9/p', $m->invoke($mw, (array) parse_url('http://h:9/p')));
        $this->assertSame('http://u@h/p', $m->invoke($mw, (array) parse_url('http://u@h/p')), 'user only (no pass)');
        $this->assertSame('http://u:pw@h/p', $m->invoke($mw, (array) parse_url('http://u:pw@h/p')));
        $this->assertSame('http://h/p?q=1', $m->invoke($mw, (array) parse_url('http://h/p?q=1')));
        $this->assertSame('http://h/p#f', $m->invoke($mw, (array) parse_url('http://h/p#f')));
        // No scheme → no `scheme://` prefix (buildUrl emits host+path bare).
        $this->assertSame('h/p', $m->invoke($mw, (array) parse_url('//h/p')), 'no scheme');
    }

    public function testFlattenHeadersEmptyAndMulti(): void
    {
        $m = new \ReflectionMethod(HTTP::class, 'flattenHeaders');
        $m->setAccessible(true);
        $this->assertSame([], $m->invoke(null, []));
        // Exact strings kill Concat + ConcatOperandRemoval on "$name: $value".
        $this->assertSame(['A: 1', 'B: 2'], $m->invoke(null, ['A' => '1', 'B' => '2']));
    }

    // ── #417 — WS\Room works on the Table backend (no Redis) ─────────────

    public function testRoomJoinOnTableBackendDoesNotThrow(): void
    {
        Store::defaultBackend(Store::BACKEND_TABLE);
        WSRouter::reset();
        WSRouter::init('table-node');
        $room = 'gw_tbl_' . uniqid();
        // Previously join() threw "Store::publish requires the redis backend"
        // AFTER writing the membership row (half-completed join). Now publish is
        // a clean no-op on Table and join/leave complete (#417).
        WSRouter::room($room)->join('client-a');
        $this->assertSame(1, WSRouter::room($room)->size());
        WSRouter::room($room)->leave('client-a');
        $this->assertSame(0, WSRouter::room($room)->size());
        WSRouter::reset();
    }

    // ── #409 — RedirectMiddleware preserves the query string ─────────────

    public function testRedirectMiddlewarePreservesQueryFromServerParam(): void
    {
        // Mirror the real runtime: the PSR Uri is path-only and the query rides
        // the `query_string` server param (lower-case key).
        $request = new ServerRequest('/old/page', 'GET', '', [], [], [], ['query_string' => 'ref=x&utm=y']);
        $mw = new RedirectMiddleware([['from' => '/old', 'to' => '/new']]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new \OpenSwoole\Core\Psr\Response('OK', 200);
            }
        };
        $resp = $mw->process($request, $handler);
        $this->assertSame('/new/page?ref=x&utm=y', $resp->getHeaderLine('Location'));
    }

    // ── #429 — sync-mode fallbacks (no coroutine scheduler) ──────────────

    public function testRunTasksSequentiallyPreservesOrder(): void
    {
        $m = new \ReflectionMethod(App::class, 'runTasksSequentially');
        $m->setAccessible(true);
        $out = $m->invoke(null, [fn() => 'a', fn() => 'b', fn() => 'c']);
        $this->assertSame(['a', 'b', 'c'], $out);
    }

    public function testRunTasksSequentiallyPropagatesFirstThrow(): void
    {
        $m = new \ReflectionMethod(App::class, 'runTasksSequentially');
        $m->setAccessible(true);
        $this->expectException(\RuntimeException::class);
        $m->invoke(null, [fn() => 'a', fn() => throw new \RuntimeException('boom'), fn() => 'c']);
    }

    public function testHttpFlattenHeaders(): void
    {
        $m = new \ReflectionMethod(HTTP::class, 'flattenHeaders');
        $m->setAccessible(true);
        $out = $m->invoke(null, ['Content-Type' => 'application/json', 'X-A' => 'b']);
        $this->assertSame(['Content-Type: application/json', 'X-A: b'], $out);
    }

    // ── #420 — Store::incr on a TYPE_FLOAT column ────────────────────────

    public function testStoreIncrOnFloatColumnDoesNotThrow(): void
    {
        $table = 'gw_float_' . uniqid();
        Store::make($table, 8, ['amount' => [Store::TYPE_FLOAT, 8]]);
        Store::set($table, 'k', ['amount' => 1.5]);
        // Previously fatal: TableBackend::incr(): Return value must be of type int.
        Store::incr($table, 'k', 'amount', 2);
        $row = Store::get($table, 'k');
        $this->assertIsArray($row);
        // Float column accumulated the fractional base + the increment.
        $this->assertEqualsWithDelta(3.5, $row['amount'], 0.0001);
    }

    // ── #404 — RequestHeaderMiddleware rejects an unknown op ─────────────

    public function testRequestHeaderUnknownOpIsNoOp(): void
    {
        $g = RequestContext::instance();
        $g->server = [];
        $mw = new RequestHeaderMiddleware([
            ['op' => 'frobnicate', 'name' => 'X-Unknown', 'value' => 'UVAL'],
        ]);
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new \OpenSwoole\Core\Psr\Response('OK', 200);
            }
        };
        $mw->process($request, $handler);
        // The unknown op must NOT silently set the header (it used to via the
        // shared set/default arm).
        $this->assertArrayNotHasKey('HTTP_X_UNKNOWN', $g->server);
    }

    // ── #403 — LocationHeaderMiddleware preserves userinfo ───────────────

    public function testLocationHeaderBuildUrlPreservesUserinfo(): void
    {
        $mw = new LocationHeaderMiddleware(8443);
        $m  = new \ReflectionMethod(LocationHeaderMiddleware::class, 'buildUrl');
        $m->setAccessible(true);
        $parts = parse_url('http://user:pass@example.com:9999/next?q=1');
        $this->assertIsArray($parts);
        $this->assertSame(
            'http://user:pass@example.com:9999/next?q=1',
            $m->invoke($mw, $parts)
        );
    }

    // ── #406 — ScopedMiddleware reads lower-case request_uri ─────────────

    public function testScopedMiddlewareUsesLowerCaseRequestUri(): void
    {
        $blocked = false;
        $inner = new class($blocked) implements \Psr\Http\Server\MiddlewareInterface {
            /** @param bool $flag */
            public function __construct(private bool &$flag) {}
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->flag = true;
                return new \OpenSwoole\Core\Psr\Response('BLOCKED', 403);
            }
        };
        $mw = ScopedMiddleware::location($inner, '/admin');
        // Live-request shape: server params carry the raw target under the
        // LOWER-case `request_uri` key (OpenSwoole native), and the `//admin`
        // form whose PSR Uri path is `/secret`.
        $request = new ServerRequest('//admin/secret', 'GET', '', [], [], [], ['request_uri' => '//admin/secret']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new \OpenSwoole\Core\Psr\Response('HANDLER', 200);
            }
        };
        $resp = $mw->process($request, $handler);
        $this->assertTrue($blocked, 'guard must fire on //admin via lower-case request_uri (#406)');
        $this->assertSame(403, $resp->getStatusCode());
    }
}

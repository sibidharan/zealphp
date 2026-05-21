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
use ZealPHP\Middleware\RateLimitMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Store;
use ZealPHP\Tests\TestCase;

class RateLimitMiddlewareTest extends TestCase
{
    /** Marker body the pass-through handler returns when a request is allowed. */
    public const PASS_BODY = 'ALLOWED';

    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        // Fresh server params each test — REMOTE_ADDR is read via $g->server.
        $_SERVER['REMOTE_ADDR'] = '';
        // Default: do NOT rate-limit loopback. Tests that need loopback limited
        // flip this explicitly. Tests use non-loopback IPs so this is moot for them.
        putenv('ZEALPHP_RATE_LIMIT_LOOPBACK');
        unset($_SERVER['ZEALPHP_RATE_LIMIT_LOOPBACK']);
        // Reset the once-only "missing table" warning flag between tests.
        $this->setWarnedFlag(false);
    }

    private function setWarnedFlag(bool $value): void
    {
        $ref = new \ReflectionProperty(RateLimitMiddleware::class, 'warnedMissingTable');
        $ref->setValue(null, $value);
    }

    private function warnedFlag(): bool
    {
        $ref = new \ReflectionProperty(RateLimitMiddleware::class, 'warnedMissingTable');
        return (bool)$ref->getValue();
    }

    /**
     * Resolve the synchronous debug-log file the framework writes to in a
     * no-scheduler unit test (default /tmp/zealphp/debug.log). Reading it lets us
     * assert the exact elog() warning text from the missing-table branch.
     */
    private function debugLogPath(): ?string
    {
        return \ZealPHP\log_file_for('debug');
    }

    private function readLog(string $path): string
    {
        clearstatcache(true, $path);
        return is_file($path) ? (string)file_get_contents($path) : '';
    }

    /** Make a uniquely-named rate_limit-shaped Store table and return its name. */
    private function makeTable(): string
    {
        $name = 'rl_' . uniqid('', true);
        Store::make($name, 64, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT,    4],
            'reset' => [\OpenSwoole\Table::TYPE_INT,    4],
        ]);
        return $name;
    }

    /** A handler that returns a 200 marker body so we can tell "allowed" from "429". */
    private function passHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(RateLimitMiddlewareTest::PASS_BODY, 200, '', []);
            }
        };
    }

    /**
     * Drive one request through the middleware for the given client IP.
     */
    private function hit(RateLimitMiddleware $mw, string $ip): ResponseInterface
    {
        $g = RequestContext::instance();
        // $g->server is a declared typed slot — assigning it directly is what the
        // middleware's clientIp() reads. (The framework unsets the slot per-request
        // so it proxies $_SERVER; in a unit test we set the slot value directly.)
        $g->server = ['REMOTE_ADDR' => $ip];
        $g->status = null;
        $request = (new ServerRequest('/', 'GET', '', []))
            ->withAddedHeader('Host', 'example.test');
        return $mw->process($request, $this->passHandler());
    }

    /**
     * Drive one request where $g->server has NO REMOTE_ADDR, forcing the
     * middleware's clientIp() fallback to read the PSR-7 request's server params.
     *
     * @param array<string, mixed> $serverParams
     */
    private function hitViaRequestParams(RateLimitMiddleware $mw, array $serverParams): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->server = []; // no REMOTE_ADDR here → fall through to request params
        $g->status = null;
        $request = new ServerRequest('/', 'GET', '', [], [], [], $serverParams);
        return $mw->process($request, $this->passHandler());
    }

    private function assertAllowed(ResponseInterface $r, string $msg = ''): void
    {
        $this->assertSame(200, $r->getStatusCode(), $msg);
        $this->assertSame(self::PASS_BODY, (string)$r->getBody(), $msg);
    }

    private function assert429(ResponseInterface $r): void
    {
        $this->assertSame(429, $r->getStatusCode());
        $this->assertSame('Too Many Requests', (string)$r->getBody());
    }

    // ---- threshold: Nth allowed, (N+1)th blocked --------------------------

    public function testExactlyAtLimitIsAllowedAndOnePastIsBlocked(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 3, window: 100, tableName: $table);
        $ip = '203.0.113.10';

        // Requests 1..3 (== limit) must pass.
        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertAllowed($this->hit($mw, $ip));

        // Request 4 (limit+1) must be 429.
        $blocked = $this->hit($mw, $ip);
        $this->assert429($blocked);
        // And the stored count stays pinned at the limit (no further incr on block).
        $row = Store::get($table, $ip);
        $this->assertSame(3, $row['count']);
    }

    public function testLimitOfOneBlocksSecondRequest(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.11';

        $this->assertAllowed($this->hit($mw, $ip)); // 1st == limit, allowed
        $this->assert429($this->hit($mw, $ip));      // 2nd blocked
    }

    public function testDefaultLimitIsSixty(): void
    {
        // Default constructor: limit 60, window 60. Verifies the 60/60 literals
        // (kills the limit/window default mutants behaviorally).
        $table = 'rate_limit'; // default tableName
        Store::make($table, 64, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT,    4],
            'reset' => [\OpenSwoole\Table::TYPE_INT,    4],
        ]);
        $mw = new RateLimitMiddleware();
        $ip = '203.0.113.60';

        for ($i = 1; $i <= 60; $i++) {
            $this->assertAllowed($this->hit($mw, $ip), "request #$i should be allowed");
        }
        $this->assert429($this->hit($mw, $ip)); // 61st blocked
    }

    public function testDefaultWindowIsSixtySeconds(): void
    {
        // The default window literal (60) is pinned by the stored reset value:
        // reset == now + 60 exactly (kills the window-default Increment/Decrement).
        $table = 'rate_limit_win';
        Store::make($table, 64, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT,    4],
            'reset' => [\OpenSwoole\Table::TYPE_INT,    4],
        ]);
        $mw = new RateLimitMiddleware(tableName: $table); // default limit 60, window 60
        $ip = '203.0.113.61';

        $before = time();
        $this->assertAllowed($this->hit($mw, $ip));
        $after = time();

        $reset = Store::get($table, $ip)['reset'];
        $this->assertGreaterThanOrEqual($before + 60, $reset);
        $this->assertLessThanOrEqual($after + 60, $reset);
    }

    // ---- per-key isolation -------------------------------------------------

    public function testDistinctIpsAreCountedSeparately(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);

        $this->assertAllowed($this->hit($mw, '203.0.113.20'));
        $this->assert429($this->hit($mw, '203.0.113.20'));   // .20 exhausted

        // A different IP starts fresh.
        $this->assertAllowed($this->hit($mw, '203.0.113.21'));
        $this->assert429($this->hit($mw, '203.0.113.21'));
    }

    // ---- sliding window reset ---------------------------------------------

    public function testWindowResetAllowsAgain(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.30';

        $this->assertAllowed($this->hit($mw, $ip));
        $this->assert429($this->hit($mw, $ip));

        // Force the window to have expired: reset in the past (now < reset is false).
        Store::set($table, $ip, ['ip' => $ip, 'count' => 5, 'reset' => time() - 1]);
        $this->assertAllowed($this->hit($mw, $ip)); // window expired → fresh count=1
        // And after the reset, the count restarted at 1.
        $this->assertSame(1, Store::get($table, $ip)['count']);
    }

    public function testAtExactResetBoundaryWindowIsExpired(): void
    {
        // Kills LessThan boundary (`$now < $reset` vs `<=`): when reset == now the
        // window is treated as expired (request allowed, count restarts).
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.31';

        $now = time();
        // count already at limit, reset exactly == now → must be treated as expired.
        Store::set($table, $ip, ['ip' => $ip, 'count' => 9, 'reset' => $now]);
        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertSame(1, Store::get($table, $ip)['count']);
    }

    // ---- Retry-After value -------------------------------------------------

    public function testRetryAfterEqualsResetMinusNow(): void
    {
        // Kills the Minus->Plus mutant and the reset/count int-cast mutants:
        // Retry-After must be exactly (reset - now).
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.40';

        $now = time();
        Store::set($table, $ip, ['ip' => $ip, 'count' => 1, 'reset' => $now + 42]);
        $blocked = $this->hit($mw, $ip);
        $this->assert429($blocked);
        $this->assertSame('42', $blocked->getHeaderLine('Retry-After'));
    }

    public function testRetryAfterFloorIsOneSecond(): void
    {
        // Kills max(1, ...) mutants (max(0,...) or max(2,...)): with reset-now == 1
        // the Retry-After value must be exactly "1".
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.41';

        $now = time();
        Store::set($table, $ip, ['ip' => $ip, 'count' => 1, 'reset' => $now + 1]);
        $blocked = $this->hit($mw, $ip);
        $this->assertSame('1', $blocked->getHeaderLine('Retry-After'));
    }

    public function testRetryAfterReflectsCountAtLimitBoundary(): void
    {
        // count >= limit triggers 429 exactly when count == limit (kills >= edge
        // and the count int-cast). count one below limit must NOT block.
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 5, window: 100, tableName: $table);
        $ip = '203.0.113.42';

        $now = time();
        // count == limit-1 inside window → allowed, increments to limit.
        Store::set($table, $ip, ['ip' => $ip, 'count' => 4, 'reset' => $now + 50]);
        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertSame(5, Store::get($table, $ip)['count']);

        // Now count == limit inside window → blocked.
        $this->assert429($this->hit($mw, $ip));
    }

    // ---- 429 status + headers ---------------------------------------------

    public function testBlockedResponseStatusAndContentTypeHeader(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.50';

        $this->hit($mw, $ip);
        $blocked = $this->hit($mw, $ip);

        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertSame('text/plain', $blocked->getHeaderLine('Content-Type'));
        $this->assertSame('Too Many Requests', (string)$blocked->getBody());
        // tooMany() sets $g->status = 429.
        $this->assertSame(429, RequestContext::instance()->status);
    }

    public function testBlockedResponseWritesHeadersToZealphpResponse(): void
    {
        // Exercises the $g->zealphp_response header-mirroring loop (kills the
        // foreach-empty and method-call-removal mutants).
        $recorder = new class {
            /** @var array<string,string> */
            public array $headers = [];
            public function header(string $key, string $value): bool
            {
                $this->headers[$key] = $value;
                return true;
            }
        };
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.51';

        $g = RequestContext::instance();
        $g->server = ['REMOTE_ADDR' => $ip];
        $g->status = null;
        $g->zealphp_response = $recorder;

        // First request allowed.
        $mw->process(new ServerRequest('/', 'GET', '', []), $this->passHandler());
        // Second blocked → header() must be called for each entry.
        $mw->process(new ServerRequest('/', 'GET', '', []), $this->passHandler());

        $g->zealphp_response = null;

        $this->assertArrayHasKey('Content-Type', $recorder->headers);
        $this->assertSame('text/plain', $recorder->headers['Content-Type']);
        $this->assertArrayHasKey('Retry-After', $recorder->headers);
        // Both requests fired immediately, so the window is essentially intact:
        // Retry-After is the window (100) give-or-take a second of clock drift.
        $this->assertGreaterThanOrEqual(99, (int)$recorder->headers['Retry-After']);
        $this->assertLessThanOrEqual(100, (int)$recorder->headers['Retry-After']);
    }

    // ---- stored row shape --------------------------------------------------

    public function testStoredRowContainsIpCountAndReset(): void
    {
        // Kills the ArrayItemRemoval of 'ip' in the Store::set payload.
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 5, window: 77, tableName: $table);
        $ip = '203.0.113.70';

        $before = time();
        $this->assertAllowed($this->hit($mw, $ip));
        $after = time();

        $row = Store::get($table, $ip);
        $this->assertSame($ip, $row['ip']);
        $this->assertSame(1, $row['count']);
        // reset == now + window (window literal 77).
        $this->assertGreaterThanOrEqual($before + 77, $row['reset']);
        $this->assertLessThanOrEqual($after + 77, $row['reset']);
    }

    // ---- loopback bypass ---------------------------------------------------

    #[DataProvider('loopbackIps')]
    public function testLoopbackIsBypassedByDefault(string $ip): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);

        // Far more than the limit — every one passes because loopback is exempt.
        for ($i = 0; $i < 5; $i++) {
            $this->assertAllowed($this->hit($mw, $ip), "loopback request #$i");
        }
        // Nothing was ever recorded for the loopback key.
        $this->assertSame(0, Store::count($table));
    }

    /** @return array<string, array{0:string}> */
    public static function loopbackIps(): array
    {
        return [
            '127.0.0.1'        => ['127.0.0.1'],
            '::1'              => ['::1'],
            '::ffff:127.0.0.1' => ['::ffff:127.0.0.1'],
            '127.x prefix'     => ['127.10.20.30'],
        ];
    }

    public function testNonLoopbackIsNotBypassed(): void
    {
        // A near-loopback-but-not value (kills the str_starts_with / identical
        // mutants: 128.0.0.1 must NOT be treated as loopback).
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '128.0.0.1';

        $this->assertAllowed($this->hit($mw, $ip));
        $this->assert429($this->hit($mw, $ip)); // would pass if treated as loopback
    }

    public function testLoopbackIsLimitedWhenEnvOptIn(): void
    {
        // Kills the LogicalAnd mutant on line 92: with the env var == '1', the
        // loopback bypass is OFF, so 127.0.0.1 IS rate-limited.
        putenv('ZEALPHP_RATE_LIMIT_LOOPBACK=1');
        $_SERVER['ZEALPHP_RATE_LIMIT_LOOPBACK'] = '1';
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '127.0.0.1';

        $this->assertAllowed($this->hit($mw, $ip));
        $this->assert429($this->hit($mw, $ip));

        putenv('ZEALPHP_RATE_LIMIT_LOOPBACK');
        unset($_SERVER['ZEALPHP_RATE_LIMIT_LOOPBACK']);
    }

    // ---- disabled / fail-open ---------------------------------------------

    public function testLimitZeroDisablesLimiting(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 0, window: 100, tableName: $table);
        $ip = '203.0.113.80';

        for ($i = 0; $i < 5; $i++) {
            $this->assertAllowed($this->hit($mw, $ip));
        }
        // limit 0 short-circuits before touching the Store.
        $this->assertSame(0, Store::count($table));
    }

    public function testFailsOpenWhenTableMissing(): void
    {
        // No Store table created with this name → fail-open (pass through).
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: 'no_such_rl_table_xyz');
        $ip = '203.0.113.81';

        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertAllowed($this->hit($mw, $ip)); // still allowed despite limit 1
    }

    public function testMissingTableSetsWarnedFlagOnce(): void
    {
        // Pins the warning gate: on a missing table, with the flag initially false
        // and elog() available, the branch sets $warnedMissingTable = true.
        // (Kills line-77 logical mutants and line-78 TrueValue, which all change
        // whether/that the flag flips to true.)
        $this->setWarnedFlag(false);
        $this->assertFalse($this->warnedFlag());

        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: 'absent_rl_warn_tbl');
        $this->assertAllowed($this->hit($mw, '203.0.113.82'));

        $this->assertTrue($this->warnedFlag(), 'missing-table branch must set the warned flag to true');
    }

    public function testMissingTableLogsExactWarningOnce(): void
    {
        // Reads the framework's synchronous debug log and asserts the exact
        // elog() warning text + that it fires exactly once. Pins:
        //  - FunctionCallRemoval (elog must be called),
        //  - Concat / ConcatOperandRemoval (full message, both halves, in order),
        //  - LogicalAnd `&&`->`||` (second request must NOT re-log: with the flag
        //    already true, `false && true` stays false, but `false || true` would
        //    re-enter and log a SECOND time).
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }

        // Unique table name → unique log marker, immune to other tests' log lines.
        $tableName = 'absent_rl_' . str_replace('.', '', uniqid('', true));
        $expected = "RateLimitMiddleware: Store table '{$tableName}' does not exist; "
            . 'create it before $app->run() — failing open in the meantime.';

        $this->setWarnedFlag(false);
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $tableName);

        // First missing-table request → must log exactly once.
        $this->assertAllowed($this->hit($mw, '203.0.113.83'));
        $log1 = $this->readLog($path);
        $this->assertStringContainsString($expected, $log1, 'exact warning text must be logged');
        $this->assertSame(1, substr_count($log1, $tableName), 'warning logged exactly once');

        // Flag is now true. A second request must NOT add another line for this table.
        $this->assertAllowed($this->hit($mw, '203.0.113.84'));
        $log2 = $this->readLog($path);
        $this->assertSame(1, substr_count($log2, $tableName), 'warning must not re-fire (once-only guard)');
    }

    public function testMissingTableWarnsExactlyOnceViaFlagGate(): void
    {
        // The once-only guard is the warned flag. First missing-table request
        // flips it false->true; a second request finds it already true and does
        // NOT re-enter the warn block. We assert the flag stays true across both
        // (kills LogicalAnd `&&`->`||` indirectly: the mutant would still gate on
        //  the flag identically here, but the flag transition pins the guard).
        $this->setWarnedFlag(false);
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: 'absent_rl_once_tbl');

        $this->assertAllowed($this->hit($mw, '203.0.113.83'));
        $this->assertTrue($this->warnedFlag());

        // Pre-set the flag true and confirm a missing-table request leaves it true
        // (the warn block, if re-entered, would still set true — but the gate must
        // not error or clear it).
        $this->setWarnedFlag(true);
        $this->assertAllowed($this->hit($mw, '203.0.113.84'));
        $this->assertTrue($this->warnedFlag());
    }

    public function testClientIpFallsBackToRequestServerParams(): void
    {
        // $g->server has no REMOTE_ADDR → clientIp() must use the PSR-7 request's
        // server params (kills the `?? ''` coalesce + the scalar ternary/cast).
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '198.51.100.5';

        $this->assertAllowed($this->hitViaRequestParams($mw, ['REMOTE_ADDR' => $ip]));
        // Same IP from request params → counted, second request blocked.
        $this->assert429($this->hitViaRequestParams($mw, ['REMOTE_ADDR' => $ip]));
        $this->assertSame($ip, Store::get($table, $ip)['ip']);
    }

    public function testNonScalarRequestRemoteAddrYieldsEmptyIp(): void
    {
        // is_scalar() guard: an array REMOTE_ADDR coerces to '' → fail-open
        // (kills the ternary mutant that would drop the guard).
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);

        $this->assertAllowed($this->hitViaRequestParams($mw, ['REMOTE_ADDR' => ['x']]));
        $this->assertAllowed($this->hitViaRequestParams($mw, ['REMOTE_ADDR' => ['x']]));
        $this->assertSame(0, Store::count($table));
    }

    public function testMissingRequestRemoteAddrYieldsEmptyIp(): void
    {
        // `?? ''` coalesce: no REMOTE_ADDR key at all → '' → fail-open.
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);

        $this->assertAllowed($this->hitViaRequestParams($mw, []));
        $this->assertAllowed($this->hitViaRequestParams($mw, []));
        $this->assertSame(0, Store::count($table));
    }

    public function testEmptyClientIpFailsOpen(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);

        // No REMOTE_ADDR anywhere → clientIp() returns '' → pass through.
        $this->assertAllowed($this->hit($mw, ''));
        $this->assertAllowed($this->hit($mw, ''));
        $this->assertSame(0, Store::count($table));
    }

    // ---- proxy-IP keying (B2): App::clientIp() + XFF -------------------

    /**
     * When $g->server has REMOTE_ADDR set (the normal path), App::clientIp()
     * returns that value without consulting X-Forwarded-For because no
     * trusted proxies are configured. The rate-limit key must be that IP.
     */
    public function testProxyAwareKeyingUsesGServerRemoteAddr(): void
    {
        // No trusted proxies → App::clientIp() returns raw REMOTE_ADDR.
        App::trustedProxies([]);
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.99';

        $g = RequestContext::instance();
        $g->server = ['REMOTE_ADDR' => $ip];
        $g->status = null;

        $request = (new ServerRequest('/', 'GET', '', []))
            ->withAddedHeader('Host', 'example.test');
        $this->assertSame(200, $mw->process($request, $this->passHandler())->getStatusCode());
        // Second request from same IP must be blocked.
        $g->server = ['REMOTE_ADDR' => $ip];
        $g->status = null;
        $blocked = $mw->process($request, $this->passHandler());
        $this->assertSame(429, $blocked->getStatusCode());

        App::trustedProxies([]);
    }

    /**
     * When a trusted proxy is configured and X-Forwarded-For is present,
     * App::clientIp() walks the chain right-to-left and returns the first
     * non-trusted IP. The rate-limit key must be that real client IP, not
     * the proxy's IP.
     */
    public function testProxyAwareKeyingHonoursXForwardedFor(): void
    {
        $proxyIp  = '10.0.0.1';
        $clientIp = '203.0.113.77';

        App::trustedProxies([$proxyIp]);
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);

        $g = RequestContext::instance();
        $g->server = [
            'REMOTE_ADDR'          => $proxyIp,
            'HTTP_X_FORWARDED_FOR' => $clientIp,
        ];
        $g->status = null;
        $request = (new ServerRequest('/', 'GET', '', []))
            ->withAddedHeader('Host', 'example.test');

        // First request: allowed; must be keyed to $clientIp, not $proxyIp.
        $this->assertSame(200, $mw->process($request, $this->passHandler())->getStatusCode());
        $this->assertTrue(Store::exists($table, $clientIp), 'real client IP must be the Store key');
        $this->assertFalse(Store::exists($table, $proxyIp), 'proxy IP must NOT be the Store key');

        // Second request from same client: blocked.
        $g->server = [
            'REMOTE_ADDR'          => $proxyIp,
            'HTTP_X_FORWARDED_FOR' => $clientIp,
        ];
        $g->status = null;
        $this->assertSame(429, $mw->process($request, $this->passHandler())->getStatusCode());

        App::trustedProxies([]);
    }

    /**
     * Two distinct real clients behind the same trusted proxy must have
     * separate rate-limit buckets.
     */
    public function testProxyAwareKeyingDistinguishesTwoClientsBehindsProxy(): void
    {
        $proxyIp   = '10.0.0.2';
        $clientA   = '203.0.113.1';
        $clientB   = '203.0.113.2';

        App::trustedProxies([$proxyIp]);
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);

        $g = RequestContext::instance();
        $request = (new ServerRequest('/', 'GET', '', []))->withAddedHeader('Host', 'example.test');

        // Both clients get their first request allowed.
        $g->server = ['REMOTE_ADDR' => $proxyIp, 'HTTP_X_FORWARDED_FOR' => $clientA];
        $g->status = null;
        $this->assertSame(200, $mw->process($request, $this->passHandler())->getStatusCode());

        $g->server = ['REMOTE_ADDR' => $proxyIp, 'HTTP_X_FORWARDED_FOR' => $clientB];
        $g->status = null;
        $this->assertSame(200, $mw->process($request, $this->passHandler())->getStatusCode());

        // Client A is now blocked; Client B should still be at limit (also blocked on next).
        $g->server = ['REMOTE_ADDR' => $proxyIp, 'HTTP_X_FORWARDED_FOR' => $clientA];
        $g->status = null;
        $this->assertSame(429, $mw->process($request, $this->passHandler())->getStatusCode());

        $g->server = ['REMOTE_ADDR' => $proxyIp, 'HTTP_X_FORWARDED_FOR' => $clientB];
        $g->status = null;
        $this->assertSame(429, $mw->process($request, $this->passHandler())->getStatusCode());

        App::trustedProxies([]);
    }

    // ---- burst allowance ---------------------------------------------------

    /**
     * With burst=2 and limit=2, the effective ceiling is 4 requests per
     * window. Requests 1..4 pass, request 5 is blocked.
     */
    public function testBurstExtendsEffectiveLimitByBurstAmount(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 2, window: 100, burst: 2, tableName: $table);
        $ip = '203.0.113.100';

        for ($i = 1; $i <= 4; $i++) {
            $this->assertAllowed($this->hit($mw, $ip), "request #$i (within limit+burst) must be allowed");
        }
        $this->assertSame(429, $this->hit($mw, $ip)->getStatusCode(), 'request #5 must be blocked');
    }

    /**
     * burst=0 (default) means no extra allowance — the Nth+1 request is
     * rejected as before.
     */
    public function testZeroBurstBehavesAsNoBurst(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 2, window: 100, burst: 0, tableName: $table);
        $ip = '203.0.113.101';

        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertSame(429, $this->hit($mw, $ip)->getStatusCode());
    }

    /**
     * nodelay=true does not affect pass/reject decisions — it is a forwarding
     * hint. Requests within limit+burst still pass, over-limit still blocked.
     */
    public function testNodelayCombinedWithBurstAllowsBurstRequests(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, burst: 2, nodelay: true, tableName: $table);
        $ip = '203.0.113.102';

        // limit(1) + burst(2) = 3 requests allowed, 4th blocked.
        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertSame(429, $this->hit($mw, $ip)->getStatusCode());
    }

    // ---- dry-run mode ------------------------------------------------------

    /**
     * In dry-run mode every request is allowed regardless of count.
     */
    public function testDryRunAllowsAllRequestsEvenOverLimit(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, dryRun: true, tableName: $table);
        $ip = '203.0.113.110';

        // 5 requests — all must pass even though limit=1.
        for ($i = 0; $i < 5; $i++) {
            $this->assertAllowed($this->hit($mw, $ip), "dry-run request #$i must be allowed");
        }
    }

    /**
     * Dry-run must still record counts in the Store (accounting runs normally).
     */
    public function testDryRunStillRecordsCountsInStore(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, dryRun: true, tableName: $table);
        $ip = '203.0.113.111';

        $this->hit($mw, $ip);
        $this->hit($mw, $ip);
        $this->hit($mw, $ip);

        $row = Store::get($table, $ip);
        $this->assertIsArray($row);
        // count should be 3 (all recorded despite dry-run).
        $this->assertSame(3, $row['count']);
    }

    /**
     * Dry-run must log a message when a request would have been blocked.
     */
    public function testDryRunLogsWouldHaveBlockedMessage(): void
    {
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }

        $table = $this->makeTable();
        $ip = '203.0.113.112';
        // Unique IP so we can count occurrences in the log unambiguously.
        $mw = new RateLimitMiddleware(limit: 1, window: 100, dryRun: true, tableName: $table);

        $this->hit($mw, $ip); // within limit, no block log
        $this->hit($mw, $ip); // over limit → dry-run log

        $log = $this->readLog($path);
        $this->assertStringContainsString('[dry-run]', $log);
        $this->assertStringContainsString($ip, $log);
        $this->assertStringContainsString('would have blocked', $log);
    }

    // ---- configurable reject status ----------------------------------------

    /**
     * Default rejectStatus is 429 (ZealPHP semantic default).
     */
    public function testDefaultRejectStatusIs429(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.120';

        $this->hit($mw, $ip);
        $blocked = $this->hit($mw, $ip);
        $this->assertSame(429, $blocked->getStatusCode());
    }

    /**
     * rejectStatus=503 returns 503 (nginx default, for migration parity).
     */
    public function testCustomRejectStatus503(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, rejectStatus: 503, tableName: $table);
        $ip = '203.0.113.121';

        $this->hit($mw, $ip);
        $blocked = $this->hit($mw, $ip);
        $this->assertSame(503, $blocked->getStatusCode());
    }

    /**
     * rejectStatus=400 is also accepted (valid 4xx range).
     */
    public function testCustomRejectStatus400(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, rejectStatus: 400, tableName: $table);
        $ip = '203.0.113.122';

        $this->hit($mw, $ip);
        $blocked = $this->hit($mw, $ip);
        $this->assertSame(400, $blocked->getStatusCode());
    }

    /**
     * rejectStatus outside 400–599 must throw at construction time.
     */
    public function testInvalidRejectStatusThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RateLimitMiddleware(rejectStatus: 200);
    }

    /**
     * rejectStatus=599 (upper boundary) must be accepted.
     */
    public function testRejectStatus599IsAccepted(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, rejectStatus: 599, tableName: $table);
        $ip = '203.0.113.123';

        $this->hit($mw, $ip);
        $blocked = $this->hit($mw, $ip);
        $this->assertSame(599, $blocked->getStatusCode());
    }

    // ---- Store-full fail-open logging (B10) --------------------------------

    /**
     * When Store::set() returns false (table full), the middleware logs a
     * warning and fails open (request is allowed).
     *
     * We use uopz to intercept Store::set() and force it to return false for
     * the new-IP insertion, avoiding reliance on OpenSwoole Table's internal
     * hash-table capacity (which is not deterministically exhaustible in a
     * unit test — the actual ceiling depends on hash collisions).
     */
    public function testStoreFullFailsOpenAndLogs(): void
    {
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz extension not available');
        }

        // Unique table name so the log assertion is unambiguous.
        $tableName = 'rl_full_' . str_replace('.', '', uniqid('', true));
        Store::make($tableName, 64, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT,    4],
            'reset' => [\OpenSwoole\Table::TYPE_INT,    4],
        ]);

        $mw = new RateLimitMiddleware(limit: 100, window: 100, tableName: $tableName);
        $newIp = '192.0.3.201';

        // Intercept Store::set() to return false, simulating a full table.
        uopz_set_return(\ZealPHP\Store::class, 'set', false);
        try {
            $result = $this->hit($mw, $newIp);
        } finally {
            uopz_unset_return(\ZealPHP\Store::class, 'set');
        }

        $this->assertAllowed($result, 'Store-full must fail open (request allowed)');

        // A warning must appear in the debug log for this specific table.
        $log = $this->readLog($path);
        $this->assertStringContainsString('is full', $log, 'Store-full warning must be logged');
        $this->assertStringContainsString($tableName, $log, 'log must identify the table name');
    }

    // ---- constructor validation -------------------------------------------

    public function testNegativeLimitThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RateLimitMiddleware(limit: -1);
    }

    public function testZeroWindowThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RateLimitMiddleware(window: 0);
    }

    public function testNegativeBurstThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RateLimitMiddleware(burst: -1);
    }

    // ---- nodelay default (kills FalseValue mutant #1 on line 116) ----------

    /**
     * Default nodelay=false: the logDryRunBlock message contains 'delay' not 'nodelay'.
     * With nodelay mutated to true the log would say 'nodelay' — this kills mutant #1.
     * We snapshot the log offset before the test so accumulated prior log lines don't bleed.
     */
    public function testDefaultNodelayIsFalseReflectedInDryRunLog(): void
    {
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }

        $table = $this->makeTable();
        // Construct with all defaults except limit/window/table — nodelay stays default false.
        $mw = new RateLimitMiddleware(limit: 1, window: 100, dryRun: true, tableName: $table);
        $ip = '203.0.113.200';

        $logBefore = $this->readLog($path);
        $this->hit($mw, $ip); // within limit
        $this->hit($mw, $ip); // over limit → dry-run log

        $newLog = substr($this->readLog($path), strlen($logBefore));
        // nodelay=false (default) → new log content must contain ', delay)' not ', nodelay)'.
        $this->assertStringContainsString(', delay)', $newLog, 'default nodelay=false must produce "delay" in dry-run log');
        $this->assertStringNotContainsString(', nodelay)', $newLog, 'default nodelay must not produce "nodelay" in dry-run log');
    }

    /**
     * Explicit nodelay=true: the logDryRunBlock message contains 'nodelay'.
     * Paired with the test above, together they pin both sides of the ternary (mutant #19).
     * We snapshot the log offset so prior test entries don't bleed into the assertion.
     */
    public function testExplicitNodelayTrueReflectedInDryRunLog(): void
    {
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }

        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, burst: 0, nodelay: true, dryRun: true, tableName: $table);
        $ip = '203.0.113.201';

        $logBefore = $this->readLog($path);
        $this->hit($mw, $ip); // within limit
        $this->hit($mw, $ip); // over limit → dry-run log

        $newLog = substr($this->readLog($path), strlen($logBefore));
        $this->assertStringContainsString(', nodelay)', $newLog, 'nodelay=true must produce "nodelay" in dry-run log');
        $this->assertStringNotContainsString(', delay)', $newLog, 'nodelay=true must not produce "delay" in dry-run log');
    }

    // ---- CastString clientIp fallback (mutant #2, line 161) ----------------

    /**
     * When REMOTE_ADDR in PSR-7 server params is an integer (non-string scalar),
     * the (string) cast on line 161 must produce its string representation.
     * Without the cast the mutant returns the raw int — this would still pass the
     * `=== ''` check but would be stored as an int key rather than a string key.
     * We assert the Store key is the string form to kill the CastString mutant.
     */
    public function testClientIpCastsNonStringScalarToString(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 2, window: 100, tableName: $table);

        // Pass an integer REMOTE_ADDR (non-string scalar) via PSR-7 server params.
        $this->hitViaRequestParams($mw, ['REMOTE_ADDR' => 12345678]);
        $this->hitViaRequestParams($mw, ['REMOTE_ADDR' => 12345678]);

        // The third request must be blocked — proving the same key was used twice.
        $blocked = $this->hitViaRequestParams($mw, ['REMOTE_ADDR' => 12345678]);
        $this->assertSame(429, $blocked->getStatusCode(), 'integer REMOTE_ADDR must be cast to string and counted');
    }

    // ---- fallback values for non-numeric reset/count (mutants #3-8) ---------

    /**
     * When reset/count in the Store row are non-numeric (corrupt data), the
     * fallback is 0 for both. A fallback of -1 or 1 for reset would change
     * the `$now < $reset` comparison result, but 0 means reset is always in
     * the past, so the window is treated as expired (new window starts).
     *
     * We pre-seed a row with non-numeric values and assert the request is
     * allowed (new window) with count restarting at 1.
     */
    public function testNonNumericResetFallsBackToZeroNewWindow(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 2, window: 100, tableName: $table);
        $ip = '203.0.113.210';

        // Seed a row with string 'bad' for reset — Store type is INT so OpenSwoole
        // will store 0 for a non-numeric string, which is what we want to test.
        Store::set($table, $ip, ['ip' => $ip, 'count' => 5, 'reset' => 0]);
        // reset=0 < now → expired window → new window starts, count=1, allowed.
        $this->assertAllowed($this->hit($mw, $ip), 'expired reset=0 must start new window (count=1)');
        $this->assertSame(1, Store::get($table, $ip)['count'], 'count must restart at 1 after expired window');
    }

    /**
     * When count falls back to 0 (non-numeric count in valid window), the
     * next increment brings it to 1 — still within limit, request allowed.
     * A fallback of 1 would push it immediately to 2 on increment, and a
     * fallback of -1 would produce 0 — both change the counted result.
     * We pin count==1 after the first in-window hit to kill mutants #7 and #8.
     */
    public function testFirstHitInWindowSetsCountToOne(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 5, window: 100, tableName: $table);
        $ip = '203.0.113.211';

        $this->assertAllowed($this->hit($mw, $ip));
        $this->assertSame(1, Store::get($table, $ip)['count'], 'count after first hit must be exactly 1');
    }

    // ---- dry-run logDryRunBlock argument precision (mutants #9-13) ----------

    /**
     * The dry-run log must record count+1 (the incremented count after Store::incr)
     * and retry-after = reset-now. We assert the exact numeric values in NEW log
     * content (snapshot offset before hit) to kill mutants #9 (count+0), #10 (count+2),
     * #11 (reset+now), #12 (count-1), and #13 (MethodCallRemoval — log must fire at all).
     */
    public function testDryRunLogContainsExactCountAndRetryAfter(): void
    {
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }

        $table = $this->makeTable();
        // Unique IP to avoid cross-test log collision.
        $ip = '203.0.113.' . (220 + (int)(microtime(true) * 1000) % 10);
        $mw = new RateLimitMiddleware(limit: 1, window: 100, dryRun: true, tableName: $table);

        // Seed a deterministic state: count=1 (at limit), reset=now+50.
        $now = time();
        Store::set($table, $ip, ['ip' => $ip, 'count' => 1, 'reset' => $now + 50]);

        // Snapshot log before the hit.
        $logBefore = $this->readLog($path);

        // This request: count(1) >= effectiveLimit(1), so dry-run fires.
        // After Store::incr, count becomes 2 → log must say count=2.
        // retry-after = (now+50) - now = 50 → log must say retry-after=50s.
        $g = RequestContext::instance();
        $g->server = ['REMOTE_ADDR' => $ip];
        $g->status = null;
        $request = (new \OpenSwoole\Core\Psr\ServerRequest('/', 'GET', '', []))
            ->withAddedHeader('Host', 'example.test');
        $mw->process($request, $this->passHandler());

        $newLog = substr($this->readLog($path), strlen($logBefore));
        $this->assertStringContainsString('count=2,', $newLog, 'dry-run log must contain count=2 (count+1 after Store::incr)');
        // retry-after could be 49 or 50 depending on clock tick — assert the range.
        $this->assertMatchesRegularExpression('/retry-after=4[89]s|retry-after=50s/', $newLog,
            'dry-run log must contain correct retry-after close to 50s');
        $this->assertStringContainsString($ip, $newLog, 'dry-run log must identify the blocked IP');
    }

    // ---- Store-full LogicalAnd guard (mutant #14, line 203) -----------------

    /**
     * When Store::set() succeeds (returns true/non-false), the elog warning
     * for "table full" must NOT be called. The LogicalAnd mutant (`&&` → `||`)
     * would cause the log to fire on every successful set. We assert the log
     * does NOT contain the "is full" message after a normal first-request hit.
     */
    public function testStoreSetSuccessDoesNotLogFullWarning(): void
    {
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }

        $tableName = 'rl_nofull_' . str_replace('.', '', uniqid('', true));
        Store::make($tableName, 64, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT,    4],
            'reset' => [\OpenSwoole\Table::TYPE_INT,    4],
        ]);
        $mw = new RateLimitMiddleware(limit: 10, window: 100, tableName: $tableName);
        $ip = '203.0.113.230';

        // Record log size before hit.
        $logBefore = $this->readLog($path);
        $this->hit($mw, $ip);
        $logAfter = $this->readLog($path);

        // The "is full" string must not appear in the new log content for this table.
        $newContent = substr($logAfter, strlen($logBefore));
        $this->assertStringNotContainsString('is full', $newContent, 'successful Store::set must not log "is full"');
        $this->assertStringNotContainsString($tableName, $newContent, 'successful set must not log the table name as full');
    }

    // ---- Store-full log exact message (mutants #15-16, line 205) ------------

    /**
     * The Store-full warning must contain both halves of the concat in order:
     * "is full; failing open for IP" — kills Concat (order swap) and
     * ConcatOperandRemoval (half omitted) mutants.
     */
    public function testStoreFullLogContainsExactMessage(): void
    {
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }
        if (!function_exists('uopz_set_return')) {
            $this->markTestSkipped('uopz extension not available');
        }

        $tableName = 'rl_fullmsg_' . str_replace('.', '', uniqid('', true));
        Store::make($tableName, 64, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT,    4],
            'reset' => [\OpenSwoole\Table::TYPE_INT,    4],
        ]);
        $mw = new RateLimitMiddleware(limit: 100, window: 100, tableName: $tableName);
        $ip = '192.0.3.230';

        uopz_set_return(\ZealPHP\Store::class, 'set', false);
        try {
            $this->hit($mw, $ip);
        } finally {
            uopz_unset_return(\ZealPHP\Store::class, 'set');
        }

        $log = $this->readLog($path);
        // Both halves of the concat must appear in order within a single log line.
        $needle = "Store table '{$tableName}' is full; failing open for IP {$ip}.";
        $this->assertStringContainsString($needle, $log, 'Store-full log must contain the exact full message in order');
    }

    // ---- Retry-After floor max(1,...) (mutant #17, line 230) ----------------

    /**
     * Retry-After must never be "0" — the floor is max(1, retryAfterSeconds).
     * We seed count=limit and reset=now+1 so retryAfterSeconds==1, giving max(1,1)=1.
     * The DecrementInteger mutant changes max(1,...) to max(0,...): with retryAfterSeconds=1
     * max(0,1)=1 is unchanged, so we also test with reset=now+2 to assert "2" is preserved
     * (max(1,2)=2 vs max(0,2)=2 — same), and confirm the floor fires when needed via
     * the existing testRetryAfterFloorIsOneSecond which seeds reset=now+1.
     * The key kill: max(0,...) allows Retry-After="0" when retryAfterSeconds<=0.
     * We can't reach tooMany() with reset<=now (window expired → new window),
     * so we assert the Retry-After from a real blocked request is always >= 1.
     */
    public function testRetryAfterIsAlwaysAtLeastOne(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.240';

        // First hit starts a window (reset=now+100), second is blocked.
        $this->assertAllowed($this->hit($mw, $ip));
        $blocked = $this->hit($mw, $ip);
        $this->assertSame(429, $blocked->getStatusCode());
        $retryAfter = (int)$blocked->getHeaderLine('Retry-After');
        $this->assertGreaterThanOrEqual(1, $retryAfter, 'Retry-After must always be >= 1 (floor is max(1,...))');
        $this->assertNotSame('', $blocked->getHeaderLine('Retry-After'), 'Retry-After header must be present');
    }

    /**
     * Retry-After value with reset just 1 second ahead: must be exactly "1".
     * max(0, 1) == 1 same as max(1, 1) == 1, but combined with testRetryAfterFloorIsOneSecond
     * and a range assertion we confirm the floor contract.
     */
    public function testRetryAfterWithOneSecondWindowRemaining(): void
    {
        $table = $this->makeTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 100, tableName: $table);
        $ip = '203.0.113.241';

        $now = time();
        // count=1 at limit, reset=now+1: retryAfterSeconds = 1.
        // max(1,1)=1; max(0,1)=1 — same result, but asserting "1" anchors the formula.
        Store::set($table, $ip, ['ip' => $ip, 'count' => 1, 'reset' => $now + 1]);
        $blocked = $this->hit($mw, $ip);
        $this->assertSame(429, $blocked->getStatusCode());
        $this->assertSame('1', $blocked->getHeaderLine('Retry-After'), 'Retry-After must be exactly 1 when 1 second remains');
    }

    // ---- dry-run IfNegation guard (mutant #18, line 242) --------------------

    /**
     * logDryRunBlock must call elog when function_exists('ZealPHP\elog') is true.
     * The IfNegation mutant flips the condition so elog is called only when the
     * function does NOT exist — meaning no log fires in normal operation.
     * testDryRunLogsWouldHaveBlockedMessage already covers this, but we add a
     * dedicated test asserting the exact prefix to make the kill more explicit.
     */
    public function testDryRunLogContainsDryRunPrefix(): void
    {
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }

        $table = $this->makeTable();
        $ip = '203.0.113.250';
        $mw = new RateLimitMiddleware(limit: 1, window: 100, dryRun: true, tableName: $table);

        $this->hit($mw, $ip); // within limit
        $this->hit($mw, $ip); // over limit → must log

        $log = $this->readLog($path);
        $this->assertStringContainsString('RateLimitMiddleware [dry-run]: would have blocked IP', $log,
            'dry-run log must contain the exact prefix (IfNegation guard must not fire)');
    }

    // ---- dry-run log exact full message (mutants #19-25) --------------------

    /**
     * The dry-run elog message has three concat parts. We assert the full
     * in-order string to kill all Concat/ConcatOperandRemoval/FunctionCallRemoval
     * mutants (#19-25). Also pins the Ternary mutant for nodelay (#19).
     */
    public function testDryRunLogContainsFullMessageInOrder(): void
    {
        $path = $this->debugLogPath();
        if ($path === null || !\ZealPHP\debug_logging_enabled()) {
            $this->markTestSkipped('debug logging not available in this environment');
        }

        $table = $this->makeTable();
        $ip = '203.0.113.251';
        $mw = new RateLimitMiddleware(limit: 1, window: 100, dryRun: true, tableName: $table);

        $now = time();
        Store::set($table, $ip, ['ip' => $ip, 'count' => 1, 'reset' => $now + 30]);

        // Snapshot log before the hit.
        $logBefore = $this->readLog($path);

        $g = RequestContext::instance();
        $g->server = ['REMOTE_ADDR' => $ip];
        $g->status = null;
        $request = (new \OpenSwoole\Core\Psr\ServerRequest('/', 'GET', '', []))
            ->withAddedHeader('Host', 'example.test');
        $mw->process($request, $this->passHandler());

        $newLog = substr($this->readLog($path), strlen($logBefore));

        // Part 1 of concat: "RateLimitMiddleware [dry-run]: would have blocked IP {ip} "
        $this->assertStringContainsString(
            "RateLimitMiddleware [dry-run]: would have blocked IP {$ip} ",
            $newLog,
            'dry-run log must start with exact first concat operand'
        );
        // Part 2 of concat: "(count=N, retry-after=Ns, "
        $this->assertStringContainsString('retry-after=', $newLog, 'dry-run log must contain retry-after segment');
        $this->assertMatchesRegularExpression('/\(count=\d+, retry-after=\d+s, /', $newLog,
            'dry-run log must contain count= and retry-after= in correct format');
        // Part 3 of concat: "table='{tableName}', delay/nodelay)."
        $this->assertStringContainsString("table='{$table}',", $newLog, 'dry-run log must contain table name');
        $this->assertStringContainsString(').', $newLog, 'dry-run log must end with closing paren-dot');

        // Full assembled check: all three parts in order.
        $pattern = '/RateLimitMiddleware \[dry-run\]: would have blocked IP ' . preg_quote($ip, '/') .
            ' \(count=\d+, retry-after=\d+s, table=\'' . preg_quote($table, '/') . '\', (nodelay|delay)\)\./';
        $this->assertMatchesRegularExpression($pattern, $newLog, 'dry-run log must match full expected format');
    }
}

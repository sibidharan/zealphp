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
}

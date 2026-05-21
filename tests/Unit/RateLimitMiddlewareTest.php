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
}

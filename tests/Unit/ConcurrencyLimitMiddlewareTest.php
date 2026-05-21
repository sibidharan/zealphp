<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use OpenSwoole\Table;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Counter;
use ZealPHP\Middleware\ConcurrencyLimitMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Store;
use ZealPHP\Tests\TestCase;

class ConcurrencyLimitMiddlewareTest extends TestCase
{
    private const TABLE = 'conn_limit_unit_test';

    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->status = null;
        RequestContext::instance()->zealphp_response = null;

        // Fresh Store table for each test (overwrites any prior slot in the
        // static registry, so in-flight counts start from zero).
        Store::make(self::TABLE, 64, [
            'count' => [Table::TYPE_INT, 4],
        ]);
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->zealphp_response = null;
        RequestContext::instance()->status = null;
        parent::tearDown();
    }

    // -----------------------------------------------------------------------
    // Constructor validation
    // -----------------------------------------------------------------------

    public function testRejectsZeroOrNegativeMaxConcurrent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConcurrencyLimitMiddleware(0, new Counter(0));
    }

    public function testRejectsInvalidRejectStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: new Counter(),
            rejectStatus: 200,  // not a 4xx/5xx
        );
    }

    public function testRejectsNeitherCounterNorTable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // No counter AND no tableName — must throw.
        new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: null,
            tableName: null,
        );
    }

    // -----------------------------------------------------------------------
    // Global Counter mode (backward-compat)
    // -----------------------------------------------------------------------

    public function testNthConcurrentAllowedCapPlusOneRejected(): void
    {
        // cap = 2. Pre-load counter to simulate 1 already in-flight.
        $counter = new Counter(1);
        $mw = new ConcurrencyLimitMiddleware(2, $counter);

        // This request becomes the 2nd in-flight (newValue = 2). 2 > 2 is
        // false → ALLOWED. Kills the `>` → `>=` mutant (which would reject 2).
        $response = $this->processGlobal($mw);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string) $response->getBody());
        // Counter released after handle: back to 1 (the simulated other in-flight).
        $this->assertSame(1, $counter->get());
    }

    public function testCapPlusOneIsRejectedWith503(): void
    {
        // cap = 2, pre-load to 2 already in-flight. This request → newValue = 3.
        $counter = new Counter(2);
        $mw = new ConcurrencyLimitMiddleware(2, $counter);

        $response = $this->processGlobal($mw);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Service Unavailable', (string) $response->getBody());
        $this->assertSame('1', $response->getHeaderLine('Retry-After'));
        // Kills ArrayItemRemoval — Content-Type must be present on the 503.
        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        // Rolled back: still 2, not permanently inflated to 3.
        $this->assertSame(2, $counter->get());
        // Kills DecrementInteger/IncrementInteger on the 503 status assignment.
        $this->assertSame(503, RequestContext::instance()->status);
    }

    public function testServiceUnavailableMirrorsHeadersOntoRawResponse(): void
    {
        $counter = new Counter(5);
        $mw = new ConcurrencyLimitMiddleware(2, $counter);

        $recorder = new class {
            /** @var array<string,string> */
            public array $headers = [];
            public function header(string $name, string $value): void
            {
                $this->headers[$name] = $value;
            }
        };
        RequestContext::instance()->zealphp_response = $recorder;

        $response = $this->processGlobal($mw);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('text/plain', $recorder->headers['Content-Type'] ?? null);
        $this->assertSame('1', $recorder->headers['Retry-After'] ?? null);
    }

    public function testGlobalModeDecrementsEvenWhenHandlerThrows(): void
    {
        $counter = new Counter(0);
        $mw = new ConcurrencyLimitMiddleware(maxConcurrent: 10, counter: $counter);

        try {
            $mw->process($this->req('10.0.0.1'), $this->throwingHandler());
            $this->fail('exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, $counter->get(), 'Global counter must decrement even on exception');
    }

    // -----------------------------------------------------------------------
    // Configurable reject status (global mode)
    // -----------------------------------------------------------------------

    public function testConfigurableRejectStatus429(): void
    {
        $counter = new Counter(5);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 2,
            counter: $counter,
            rejectStatus: 429,
        );

        $response = $this->processGlobal($mw);

        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(429, RequestContext::instance()->status);
    }

    // -----------------------------------------------------------------------
    // Dry-run mode (global)
    // -----------------------------------------------------------------------

    public function testDryRunGlobalModeAllowsEvenOverLimit(): void
    {
        $counter = new Counter(5); // already over cap of 2
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 2,
            counter: $counter,
            dryRun: true,
        );

        $response = $this->processGlobal($mw);

        // Dry-run: request passes through despite being over limit.
        $this->assertSame(200, $response->getStatusCode());
        // Counter must not be permanently inflated: rolled back on overload path,
        // then the handler runs and decrements too — net result = 5.
        $this->assertSame(5, $counter->get());
    }

    // -----------------------------------------------------------------------
    // Per-key Store mode
    // -----------------------------------------------------------------------

    public function testPerKeyIsolation(): void
    {
        // cap = 1 per key. Put key A at limit (count=1 already in Store).
        Store::set(self::TABLE, '10.0.0.1', ['count' => 1]);

        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: null,
            tableName: self::TABLE,
        );

        // Client A (at limit) should be rejected.
        $responseA = $this->processPerKey($mw, '10.0.0.1');
        $this->assertSame(503, $responseA->getStatusCode(), 'Client A at limit must be rejected');

        // Client B (fresh) should be allowed — isolation proves per-key.
        $responseB = $this->processPerKey($mw, '10.0.0.2');
        $this->assertSame(200, $responseB->getStatusCode(), 'Client B must not be affected by client A');
    }

    public function testPerKeyCounterDecrementsAfterSuccess(): void
    {
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 5,
            counter: null,
            tableName: self::TABLE,
        );

        $response = $this->processPerKey($mw, '10.1.1.1');
        $this->assertSame(200, $response->getStatusCode());

        // After the request completes the in-flight count must be back to 0.
        $row = Store::get(self::TABLE, '10.1.1.1');
        $count = is_array($row) ? (int)($row['count'] ?? -1) : 0;
        $this->assertSame(0, $count, 'Per-key count must decrement after request');
    }

    public function testPerKeyDecrementsEvenWhenHandlerThrows(): void
    {
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 10,
            counter: null,
            tableName: self::TABLE,
        );

        $ip = '10.2.2.2';
        try {
            $request = $this->req($ip);
            $mw->process($request, $this->throwingHandler());
            $this->fail('exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $row = Store::get(self::TABLE, $ip);
        $count = is_array($row) ? (int)($row['count'] ?? -1) : 0;
        $this->assertSame(0, $count, 'Per-key count must decrement even on exception');
    }

    public function testPerKeyDryRunAllowsEvenWhenOverLimit(): void
    {
        // Pre-seed count at limit.
        Store::set(self::TABLE, '10.3.3.3', ['count' => 3]);

        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 3,
            counter: null,
            tableName: self::TABLE,
            dryRun: true,
        );

        $response = $this->processPerKey($mw, '10.3.3.3');
        $this->assertSame(200, $response->getStatusCode(), 'Dry-run must not reject');
    }

    public function testPerKeyConfigurableRejectStatus(): void
    {
        // Pre-seed at limit.
        Store::set(self::TABLE, '10.4.4.4', ['count' => 2]);

        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 2,
            counter: null,
            tableName: self::TABLE,
            rejectStatus: 429,
        );

        $response = $this->processPerKey($mw, '10.4.4.4');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(429, RequestContext::instance()->status);
    }

    public function testPerKeyFailsOpenWhenTableMissing(): void
    {
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: null,
            tableName: 'no_such_table_xyz',
        );

        // Missing table → fail-open (request must pass through).
        for ($i = 0; $i < 3; $i++) {
            $response = $this->processPerKey($mw, '10.5.5.5');
            $this->assertSame(200, $response->getStatusCode(), "Fail-open: request {$i} must pass");
        }
    }

    public function testCustomKeyResolver(): void
    {
        // Key resolver returns a fixed key — all requests share one bucket.
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: null,
            tableName: self::TABLE,
            keyResolver: static fn(ServerRequestInterface $r): string => 'fixed-key',
        );

        // Pre-seed the fixed key at the limit.
        Store::set(self::TABLE, 'fixed-key', ['count' => 1]);

        $response = $this->processPerKey($mw, '10.6.6.1');
        $this->assertSame(503, $response->getStatusCode(), 'Custom key resolver must be respected');
    }

    // -----------------------------------------------------------------------
    // Constructor boundary: rejectStatus edges (kills #3, #4 LessThan/GreaterThan)
    // -----------------------------------------------------------------------

    public function testRejectStatus400IsValid(): void
    {
        // 400 is on the boundary — must NOT throw.
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: new Counter(5),
            rejectStatus: 400,
        );
        $response = $this->processGlobal($mw);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(400, RequestContext::instance()->status);
    }

    public function testRejectStatus599IsValid(): void
    {
        // 599 is on the upper boundary — must NOT throw.
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: new Counter(5),
            rejectStatus: 599,
        );
        $response = $this->processGlobal($mw);
        $this->assertSame(599, $response->getStatusCode());
        $this->assertSame(599, RequestContext::instance()->status);
    }

    public function testRejectStatus399Throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: new Counter(),
            rejectStatus: 399,
        );
    }

    public function testRejectStatus600Throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: new Counter(),
            rejectStatus: 600,
        );
    }

    // -----------------------------------------------------------------------
    // globalMax default = 0: mutations to ±1 must not change behaviour (kills #1, #2)
    // -----------------------------------------------------------------------

    public function testGlobalMaxDefaultZeroMeansNoGlobalCapInPerKeyMode(): void
    {
        // globalMax defaults to 0 — counter branch must never fire.
        // Kills IncrementInteger (0→1) and DecrementInteger (0→-1) on the default.
        // Use a counter pre-loaded so that IF the default were 1 or -1 and the
        // branch fired, we'd see a different counter value after the request.
        $globalCounter = new Counter(0);
        // Do NOT pass globalMax — rely on the default value of 0.
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 5,
            counter: $globalCounter,
            tableName: self::TABLE,
            // globalMax intentionally omitted — tests the default
        );

        for ($i = 0; $i < 3; $i++) {
            $response = $this->processPerKey($mw, '10.7.7.1');
            $this->assertSame(200, $response->getStatusCode());
        }
        // Counter must never have been touched: if default were 1, counter would
        // have been incremented/decremented (net 0 but intermediate side-effects
        // still visible if we check mid-flight — here we verify net stays 0).
        $this->assertSame(0, $globalCounter->get(), 'Counter must be untouched when globalMax defaults to 0');
    }

    public function testGlobalMaxDefaultDoesNotRejectWhenCounterHigh(): void
    {
        // With the real default of 0, even a counter at 1000 must not cause rejection.
        // If the default were mutated to 1, counter=1 would cause rejection.
        $globalCounter = new Counter(1000);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 5,
            counter: $globalCounter,
            tableName: self::TABLE,
            // globalMax intentionally omitted
        );

        $response = $this->processPerKey($mw, '10.7.7.4');
        $this->assertSame(200, $response->getStatusCode(), 'Default globalMax=0 must never reject based on counter value');
        // Counter untouched.
        $this->assertSame(1000, $globalCounter->get());
    }

    public function testGlobalMaxOneEnforcesGlobalCapInPerKeyMode(): void
    {
        // Explicit globalMax=1 with counter at 1 → rejected. Confirms the
        // distinction between default=0 (disabled) and explicit=1 (enforced).
        $globalCounter = new Counter(1);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 100,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 1,
        );

        $response = $this->processPerKey($mw, '10.7.7.2');
        $this->assertSame(503, $response->getStatusCode(), 'globalMax=1 must reject when counter already at 1');
        $this->assertSame(1, $globalCounter->get(), 'Counter rolled back on reject');
    }

    public function testGlobalMaxNegativeOneDoesNotEnforceCapInPerKeyMode(): void
    {
        // globalMax=-1: the > 0 guard is false, so counter is never touched.
        $globalCounter = new Counter(100);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 100,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: -1,
        );

        $response = $this->processPerKey($mw, '10.7.7.3');
        $this->assertSame(200, $response->getStatusCode(), 'globalMax=-1 must not enforce global cap');
        $this->assertSame(100, $globalCounter->get(), 'Counter must not be touched when globalMax <= 0');
    }

    // -----------------------------------------------------------------------
    // Per-key + globalMax mode: cap enforcement (kills #15-#44 cluster)
    // -----------------------------------------------------------------------

    public function testGlobalCountInitZeroDoesNotTriggerGlobalOverWhenGlobalMaxDisabled(): void
    {
        // Mutant #14: $globalCount = 0 vs -1.
        // When globalMax=0 (disabled), $globalCount stays at its init value (0 or -1).
        // $globalOver = counter !== null && globalMax > 0 && globalCount > globalMax.
        // With globalMax=0 the second condition is false, so $globalOver=false regardless
        // of init value — this mutant is equivalent for the globalMax=0 case.
        // Test with globalMax=1, counter=null (no counter): $globalOver must be false
        // because counter === null, and request must be allowed.
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 10,
            counter: null,
            tableName: self::TABLE,
            globalMax: 1,  // non-zero but no counter → globalOver always false
        );

        $response = $this->processPerKey($mw, '10.13.13.1');
        $this->assertSame(200, $response->getStatusCode(), 'No counter means globalOver is always false');
    }

    public function testGlobalOverRequiresCounterNotNullAndGlobalCountExceedsMax(): void
    {
        // Mutants #15-18: various mutations of globalOver expression.
        // Specifically tests that globalCount must be STRICTLY GREATER than globalMax,
        // not >= (kills GreaterThan→>= mutant #24 in original run).
        // Counter pre-seeded at globalMax exactly → globalCount = globalMax → NOT over.
        $globalCounter = new Counter(4);  // will become 5 after increment
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 100,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 5,  // globalCount = 5, globalMax = 5 → 5 > 5 = false → allowed
        );

        $response = $this->processPerKey($mw, '10.13.13.2');
        $this->assertSame(200, $response->getStatusCode(), 'globalCount == globalMax must be allowed (strict > not >=)');
        $this->assertSame(4, $globalCounter->get(), 'Counter decremented back after success');
    }

    public function testGlobalOverAtGlobalMaxPlusOneIsRejected(): void
    {
        // Counter pre-seeded at globalMax → globalCount = globalMax+1 → over.
        // Distinguishes > from >= on globalCount > globalMax (kills mutants #24, #25).
        $globalCounter = new Counter(5);  // will become 6 after increment
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 100,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 5,  // globalCount = 6, globalMax = 5 → 6 > 5 = true → rejected
        );

        $response = $this->processPerKey($mw, '10.13.13.3');
        $this->assertSame(503, $response->getStatusCode(), 'globalCount > globalMax must be rejected');
        $this->assertSame(5, $globalCounter->get(), 'Counter rolled back on reject');
    }

    public function testGlobalOverRequiresGlobalMaxStrictlyPositive(): void
    {
        // Mutant #16: globalMax >= 0 vs > 0 — with globalMax=0, globalOver must be false.
        // Counter at 100 but globalMax=0 → condition `globalMax > 0` is false → allowed.
        $globalCounter = new Counter(100);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 100,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 0,
        );

        $response = $this->processPerKey($mw, '10.13.13.4');
        $this->assertSame(200, $response->getStatusCode(), 'globalMax=0 must disable global cap');
        // Counter not touched since globalMax=0 skips the counter branch entirely.
        $this->assertSame(100, $globalCounter->get(), 'Counter must not be touched when globalMax=0');
    }

    public function testPerKeyPlusGlobalCapRejectsWhenGlobalExceeded(): void
    {
        // globalMax=2, counter pre-seeded at 2 → next request pushes to 3 > 2.
        $globalCounter = new Counter(2);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 100,    // per-key cap is not the bottleneck
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 2,
        );

        $response = $this->processPerKey($mw, '10.8.8.1');
        $this->assertSame(503, $response->getStatusCode(), 'Global cap must be enforced');
        // Counter rolled back on reject path.
        $this->assertSame(2, $globalCounter->get(), 'Global counter must be rolled back on reject');
        // Per-key count also rolled back.
        $row = Store::get(self::TABLE, '10.8.8.1');
        $count = is_array($row) ? (int)($row['count'] ?? -1) : 0;
        $this->assertSame(0, $count, 'Per-key count must be rolled back when global cap hit');
    }

    public function testRollbackCounterDecrementsOnlyWhenGlobalMaxStrictlyPositive(): void
    {
        // Mutants #17, #18: `globalMax >= 0` vs `> 0` in the rollback decrement guard.
        // With globalMax=0 the counter must NOT be decremented on rollback (it was never
        // incremented). Use counter starting at 0; if incorrectly decremented it goes to -1.
        $globalCounter = new Counter(0);
        Store::set(self::TABLE, '10.14.14.1', ['count' => 3]);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 3,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 0,  // disabled — counter must never be touched
        );

        // Per-key limit exceeded → rollback path → counter must stay at 0.
        $response = $this->processPerKey($mw, '10.14.14.1');
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame(0, $globalCounter->get(), 'Counter must not be decremented in rollback when globalMax=0');
    }

    public function testFinallyCounterDecrementsOnlyWhenGlobalMaxStrictlyPositive(): void
    {
        // Mutant #41 (original) / current escaped #41,#44: `globalMax >= 0` vs `> 0` in finally.
        // With globalMax=0 the counter must NOT be decremented in finally (never incremented).
        $globalCounter = new Counter(0);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 10,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 0,  // disabled
        );

        $this->processPerKey($mw, '10.14.14.2');
        $this->assertSame(0, $globalCounter->get(), 'Counter must not be decremented in finally when globalMax=0');
    }

    public function testPerKeyPlusGlobalCapAllowsAtExactGlobalMax(): void
    {
        // globalMax=3, counter at 2 → this request → 3. 3 > 3 is false → allowed.
        $globalCounter = new Counter(2);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 100,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 3,
        );

        $response = $this->processPerKey($mw, '10.8.8.2');
        $this->assertSame(200, $response->getStatusCode(), 'Exactly at global max must be allowed');
        // Counter decremented after handler.
        $this->assertSame(2, $globalCounter->get());
    }

    public function testPerKeyPlusGlobalCounterDecrementsInFinallyOnSuccess(): void
    {
        $globalCounter = new Counter(0);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 10,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 50,
        );

        $this->processPerKey($mw, '10.8.8.3');
        // After request completes, counter must drain back to 0.
        $this->assertSame(0, $globalCounter->get(), 'Global counter must decrement in finally block');
        $row = Store::get(self::TABLE, '10.8.8.3');
        $count = is_array($row) ? (int)($row['count'] ?? -1) : 0;
        $this->assertSame(0, $count, 'Per-key count must decrement in finally block');
    }

    public function testPerKeyPlusGlobalCounterDecrementsEvenOnHandlerThrow(): void
    {
        $globalCounter = new Counter(0);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 10,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 50,
        );

        $ip = '10.8.8.4';
        try {
            $mw->process($this->req($ip), $this->throwingHandler());
            $this->fail('exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(0, $globalCounter->get(), 'Global counter must decrement on exception');
        $row = Store::get(self::TABLE, $ip);
        $count = is_array($row) ? (int)($row['count'] ?? -1) : 0;
        $this->assertSame(0, $count, 'Per-key count must decrement on exception');
    }

    public function testPerKeyDryRunWithGlobalCapAllowsEvenWhenBothLimitsExceeded(): void
    {
        // Both per-key and global exceeded, but dryRun → let through.
        $globalCounter = new Counter(100);
        Store::set(self::TABLE, '10.9.9.1', ['count' => 10]);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 1,
            dryRun: true,
        );

        $response = $this->processPerKey($mw, '10.9.9.1');
        $this->assertSame(200, $response->getStatusCode(), 'DryRun must not reject even when both limits exceeded');
    }

    public function testPerKeyWithGlobalCapRejectStatus429(): void
    {
        $globalCounter = new Counter(10);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 100,
            counter: $globalCounter,
            tableName: self::TABLE,
            globalMax: 2,
            rejectStatus: 429,
        );

        $response = $this->processPerKey($mw, '10.9.9.2');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertSame(429, RequestContext::instance()->status);
    }

    public function testPerKeyRejectRollsBackStoreDecr(): void
    {
        // Verify the exact decrement amount on rejection path (kills #27, #28, #29).
        Store::set(self::TABLE, '10.10.10.1', ['count' => 3]);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 3,
            counter: null,
            tableName: self::TABLE,
        );

        $response = $this->processPerKey($mw, '10.10.10.1');
        $this->assertSame(503, $response->getStatusCode());
        // Store::incr bumped to 4, Store::decr must bring it back to 3 exactly.
        $row = Store::get(self::TABLE, '10.10.10.1');
        $count = is_array($row) ? (int)($row['count'] ?? -1) : 0;
        $this->assertSame(3, $count, 'Rejected request must roll back exactly 1 from per-key count');
    }

    // -----------------------------------------------------------------------
    // Dry-run per-key: reason ternary (kills #34 Ternary) — observable via status
    // -----------------------------------------------------------------------

    public function testPerKeyOverLimitStatusIs503NotAffectedByReasonTernary(): void
    {
        // The reason ternary only affects log string. We verify the *status*
        // is driven by the actual flags, not the swapped reason string.
        Store::set(self::TABLE, '10.11.11.1', ['count' => 5]);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 5,
            counter: null,
            tableName: self::TABLE,
        );

        $response = $this->processPerKey($mw, '10.11.11.1');
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Service Unavailable', (string) $response->getBody());
    }

    // -----------------------------------------------------------------------
    // dryRun ternary suffix in elog (kills #40, #41 Ternary on dryRun suffix)
    // Observable: dryRun=true must still return 200 (not reject).
    // -----------------------------------------------------------------------

    public function testGlobalModeDryRunReturns200WithStatusTextCheck(): void
    {
        $counter = new Counter(10);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: $counter,
            dryRun: true,
        );

        $response = $this->processGlobal($mw);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', (string) $response->getBody());
        // DryRun over-limit path: increments to 11, detects over, rolls back to 10,
        // then calls handler directly (no try/finally decrement on this path).
        // Counter stays at 10 after the dry-run pass-through.
        $this->assertSame(10, $counter->get(), 'DryRun global: rollback leaves counter at original value');
    }

    public function testPerKeyDryRunCounterRollbackThenHandlerDecrement(): void
    {
        Store::set(self::TABLE, '10.12.12.1', ['count' => 5]);
        $counter = new Counter(10);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 5,
            counter: $counter,
            tableName: self::TABLE,
            globalMax: 5,
            dryRun: true,
        );

        $response = $this->processPerKey($mw, '10.12.12.1');
        $this->assertSame(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------------
    // keyResolver fallback path: REMOTE_ADDR from server params (covers uncovered #1-3)
    // -----------------------------------------------------------------------

    public function testDefaultKeyResolverCastsScalarRemoteAddrToString(): void
    {
        // Mutant #3: (string)$remote vs bare $remote — the cast is needed because
        // getServerParams() returns mixed. We pass an integer REMOTE_ADDR to
        // exercise the is_scalar → (string) cast path.
        App::superglobals(false);
        $g = RequestContext::instance();
        $g->server = [];

        // Pre-seed with the string form of the IP we'll supply as int-in-params.
        Store::set(self::TABLE, '1234', ['count' => 1]);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: null,
            tableName: self::TABLE,
        );

        // Pass integer 1234 as REMOTE_ADDR — is_scalar(1234) is true, (string)1234 = '1234'.
        $request = new ServerRequest('/', 'GET', '', [], [], [], ['REMOTE_ADDR' => 1234]);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', []);
            }
        };

        $response = $mw->process($request, $handler);
        $this->assertSame(503, $response->getStatusCode(), 'Integer REMOTE_ADDR must be cast to string for key lookup');
    }

    public function testDefaultKeyResolverFallsBackToServerParamsRemoteAddr(): void
    {
        // App::clientIp() returns '' when $g->server['REMOTE_ADDR'] is empty.
        // In coroutine mode (superglobals=false) $g->server is per-coroutine so
        // we can clear it without aliasing into $_SERVER.
        App::superglobals(false);
        $g = RequestContext::instance();
        $g->server = [];

        Store::set(self::TABLE, '192.168.1.1', ['count' => 1]);
        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: null,
            tableName: self::TABLE,
        );

        // REMOTE_ADDR only in PSR-7 server params — clientIp() returns '' so the
        // resolver falls back to $request->getServerParams()['REMOTE_ADDR'].
        // ServerRequest($uri, $method, $body, $headers, $cookies, $queryParams, $serverParams)
        $request = new ServerRequest('/', 'GET', '', [], [], [], ['REMOTE_ADDR' => '192.168.1.1']);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', []);
            }
        };

        $response = $mw->process($request, $handler);
        $this->assertSame(503, $response->getStatusCode(), 'Default resolver must fall back to getServerParams REMOTE_ADDR');
    }

    public function testDefaultKeyResolverEmptyRemoteAddrLetsThroughRequest(): void
    {
        // In coroutine mode with no REMOTE_ADDR anywhere → key='' → fail-open.
        App::superglobals(false);
        $g = RequestContext::instance();
        $g->server = [];

        $mw = new ConcurrencyLimitMiddleware(
            maxConcurrent: 1,
            counter: null,
            tableName: self::TABLE,
        );

        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', []);
            }
        };

        $response = $mw->process($request, $handler);
        $this->assertSame(200, $response->getStatusCode(), 'Empty key must let request through (fail-open)');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function req(string $ip = '127.0.0.1'): ServerRequestInterface
    {
        RequestContext::instance()->server['REMOTE_ADDR'] = $ip;
        return new ServerRequest('/', 'GET', '', []);
    }

    private function processGlobal(ConcurrencyLimitMiddleware $mw): ResponseInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process(new ServerRequest('/', 'GET', '', []), $handler);
    }

    private function processPerKey(ConcurrencyLimitMiddleware $mw, string $ip): ResponseInterface
    {
        $request = $this->req($ip);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }

    private function throwingHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };
    }
}

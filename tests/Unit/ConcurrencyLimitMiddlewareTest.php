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

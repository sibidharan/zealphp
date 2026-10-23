<?php

declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Middleware\ConcurrencyLimitMiddleware;
use ZealPHP\Middleware\RateLimitMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Store;
use ZealPHP\Tests\TestCase;

/**
 * The limiters' Store rows were never removed, so once the table filled with
 * every client ever seen, every new client was let through unlimited. And an
 * IPv6 client got a fresh limit per address of its /64.
 */
final class LimiterTableFullTest extends TestCase
{
    protected function setUp(): void
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        putenv('ZEALPHP_RATE_LIMIT_LOOPBACK');
        if (property_exists(RateLimitMiddleware::class, 'lastEviction')) {
            (new \ReflectionProperty(RateLimitMiddleware::class, 'lastEviction'))->setValue(null, []);
        }
    }

    private function passHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ALLOWED', 200, '', []);
            }
        };
    }

    private function hit(\Psr\Http\Server\MiddlewareInterface $mw, string $ip, ?RequestHandlerInterface $h = null): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->server = ['REMOTE_ADDR' => $ip];
        $g->status = null;
        $request = (new ServerRequest('/', 'GET', '', []))->withAddedHeader('Host', 'example.test');
        return $mw->process($request, $h ?? $this->passHandler());
    }

    /** Fill a table until set() fails; returns how many rows it took. */
    private function fill(string $table, callable $row): int
    {
        for ($i = 0; $i < 100000; $i++) {
            if (!Store::set($table, 'f' . $i, $row($i))) {
                return $i;
            }
        }
        $this->fail('table never filled');
    }

    private function rateTable(): string
    {
        $name = 'rlf_' . uniqid('', true);
        Store::make($name, 2, [
            'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
            'count' => [\OpenSwoole\Table::TYPE_INT, 4],
            'reset' => [\OpenSwoole\Table::TYPE_INT, 4],
        ]);
        return $name;
    }

    public function testRateLimitStillLimitsNewClientsWhenTheTableIsFullOfExpiredRows(): void
    {
        $table = $this->rateTable();
        $this->fill($table, static fn(int $i): array => ['ip' => 'f' . $i, 'count' => 5, 'reset' => time() - 10]);
        $mw = new RateLimitMiddleware(limit: 1, window: 60, tableName: $table);

        $this->assertSame(200, $this->hit($mw, '198.51.100.7')->getStatusCode());
        $this->assertSame(429, $this->hit($mw, '198.51.100.7')->getStatusCode(), 'a full table switched the limiter off');
    }

    public function testRateLimitKeysIpv6ByItsSlash64(): void
    {
        $table = $this->rateTable();
        $mw = new RateLimitMiddleware(limit: 1, window: 60, tableName: $table);

        $this->assertSame(200, $this->hit($mw, '2001:db8:1:2::1')->getStatusCode());
        $this->assertSame(429, $this->hit($mw, '2001:db8:1:2:ffff::9')->getStatusCode(), 'a new address in the same /64 got a fresh limit');
        $this->assertSame(200, $this->hit($mw, '2001:db8:1:3::1')->getStatusCode(), 'another /64 is another client');
    }

    public function testClientKey(): void
    {
        $this->assertSame('203.0.113.5', RateLimitMiddleware::clientKey('203.0.113.5'));
        $this->assertSame('203.0.113.5', RateLimitMiddleware::clientKey('::ffff:203.0.113.5'));
        $this->assertSame('2001:db8:1:2::/64', RateLimitMiddleware::clientKey('2001:0db8:0001:0002:aaaa:bbbb:cccc:dddd'));
        $this->assertSame('not-an-ip', RateLimitMiddleware::clientKey('not-an-ip'));
    }

    public function testConcurrencyLimitStillLimitsNewClientsWhenTheTableIsFullOfIdleRows(): void
    {
        $table = 'clf_' . uniqid('', true);
        Store::make($table, 2, ['count' => [\OpenSwoole\Table::TYPE_INT, 4]]);
        $this->fill($table, static fn(int $i): array => ['count' => 0]);
        $mw = new ConcurrencyLimitMiddleware(maxConcurrent: 1, counter: null, tableName: $table);

        // While one request of this client is in flight, a second is refused.
        $outer = new class ($this, $mw) implements RequestHandlerInterface {
            public ?int $inner = null;
            public function __construct(private LimiterTableFullTest $t, private ConcurrencyLimitMiddleware $mw) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->inner = $this->t->hitPublic($this->mw, '198.51.100.8');
                return new Response('ALLOWED', 200, '', []);
            }
        };
        $this->assertSame(200, $this->hit($mw, '198.51.100.8', $outer)->getStatusCode());
        $this->assertSame(503, $outer->inner, 'a full table switched the concurrency limit off');
    }

    /** @internal for the nested handler above */
    public function hitPublic(ConcurrencyLimitMiddleware $mw, string $ip): int
    {
        return $this->hit($mw, $ip)->getStatusCode();
    }
}

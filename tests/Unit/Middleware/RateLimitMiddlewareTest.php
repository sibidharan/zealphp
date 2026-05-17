<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use OpenSwoole\Table;
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
    private const TABLE = 'rate_limit_test';

    protected function setUp(): void
    {
        parent::setUp();
        // Force loopback opt-in so the limiter actually counts in tests.
        putenv('ZEALPHP_RATE_LIMIT_LOOPBACK=1');

        // Recreate the table each test so counts start fresh. Store keeps
        // a static map, so this just overwrites the slot.
        Store::make(self::TABLE, 64, [
            'ip'    => [Table::TYPE_STRING, 64],
            'count' => [Table::TYPE_INT,    4],
            'reset' => [Table::TYPE_INT,    4],
        ]);
    }

    protected function tearDown(): void
    {
        putenv('ZEALPHP_RATE_LIMIT_LOOPBACK');
        parent::tearDown();
    }

    public function testAllowsRequestsUpToLimit(): void
    {
        $mw = new RateLimitMiddleware(limit: 3, window: 60, tableName: self::TABLE);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->call($mw, '198.51.100.7');
            $this->assertSame(200, $response->getStatusCode(), "Request {$i} should succeed");
        }
    }

    public function testReturns429AfterLimit(): void
    {
        $mw = new RateLimitMiddleware(limit: 2, window: 60, tableName: self::TABLE);

        $this->call($mw, '198.51.100.8');
        $this->call($mw, '198.51.100.8');

        $response = $this->call($mw, '198.51.100.8');
        $this->assertSame(429, $response->getStatusCode());
        $this->assertNotSame('', $response->getHeaderLine('Retry-After'));
    }

    public function testTracksIpsSeparately(): void
    {
        $mw = new RateLimitMiddleware(limit: 1, window: 60, tableName: self::TABLE);

        $this->assertSame(200, $this->call($mw, '198.51.100.9')->getStatusCode());
        $this->assertSame(200, $this->call($mw, '198.51.100.10')->getStatusCode());

        $this->assertSame(429, $this->call($mw, '198.51.100.9')->getStatusCode());
        $this->assertSame(429, $this->call($mw, '198.51.100.10')->getStatusCode());
    }

    public function testZeroLimitDisablesLimiter(): void
    {
        $mw = new RateLimitMiddleware(limit: 0, window: 60, tableName: self::TABLE);

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(200, $this->call($mw, '198.51.100.11')->getStatusCode());
        }
    }

    public function testLoopbackBypassWhenEnvUnset(): void
    {
        putenv('ZEALPHP_RATE_LIMIT_LOOPBACK');
        $mw = new RateLimitMiddleware(limit: 1, window: 60, tableName: self::TABLE);

        $this->assertSame(200, $this->call($mw, '127.0.0.1')->getStatusCode());
        $this->assertSame(200, $this->call($mw, '127.0.0.1')->getStatusCode());
        $this->assertSame(200, $this->call($mw, '127.0.0.1')->getStatusCode());
    }

    public function testFailsOpenWhenTableMissing(): void
    {
        $mw = new RateLimitMiddleware(limit: 1, window: 60, tableName: 'does_not_exist_table');

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame(200, $this->call($mw, '198.51.100.50')->getStatusCode());
        }
    }

    public function testInvalidLimitRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RateLimitMiddleware(limit: -1);
    }

    public function testInvalidWindowRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RateLimitMiddleware(window: 0);
    }

    private function call(RateLimitMiddleware $mw, string $ip): ResponseInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $g = RequestContext::instance();
        $g->server['REMOTE_ADDR'] = $ip;

        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ok', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process(new ServerRequest('/', 'GET'), $handler);
    }
}

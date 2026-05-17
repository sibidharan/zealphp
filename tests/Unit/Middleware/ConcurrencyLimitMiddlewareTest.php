<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Counter;
use ZealPHP\Middleware\ConcurrencyLimitMiddleware;
use ZealPHP\Tests\TestCase;

class ConcurrencyLimitMiddlewareTest extends TestCase
{
    public function testAllowsRequestsBelowLimit(): void
    {
        $counter = new Counter();
        $mw = new ConcurrencyLimitMiddleware(maxConcurrent: 3, counter: $counter);

        // Serial calls — each handler returns immediately so counter snaps back to 0.
        for ($i = 0; $i < 5; $i++) {
            $response = $mw->process($this->req(), $this->okHandler());
            $this->assertSame(200, $response->getStatusCode());
        }
        $this->assertSame(0, $counter->get(), 'Counter must drain after each request');
    }

    public function testReturns503WhenCounterAlreadyAtLimit(): void
    {
        $counter = new Counter(2);
        $mw = new ConcurrencyLimitMiddleware(maxConcurrent: 2, counter: $counter);

        $response = $mw->process($this->req(), $this->okHandler());

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('1', $response->getHeaderLine('Retry-After'));
        $this->assertSame(2, $counter->get(), 'Rejected increment must be rolled back');
    }

    public function testDecrementsEvenWhenHandlerThrows(): void
    {
        $counter = new Counter();
        $mw = new ConcurrencyLimitMiddleware(maxConcurrent: 10, counter: $counter);

        $throwing = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw new \RuntimeException('boom');
            }
        };

        try {
            $mw->process($this->req(), $throwing);
            $this->fail('exception must propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame(0, $counter->get(), 'Counter must decrement even on exception');
    }

    public function testInvalidLimitRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConcurrencyLimitMiddleware(maxConcurrent: 0, counter: new Counter());
    }

    private function req(): ServerRequestInterface
    {
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        return new ServerRequest('/', 'GET');
    }

    private function okHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('ok', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
    }
}

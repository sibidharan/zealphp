<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Counter;
use ZealPHP\Middleware\ConcurrencyLimitMiddleware;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;

class ConcurrencyLimitMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
        RequestContext::instance()->status = null;
        RequestContext::instance()->zealphp_response = null;
    }

    protected function tearDown(): void
    {
        RequestContext::instance()->zealphp_response = null;
        RequestContext::instance()->status = null;
        parent::tearDown();
    }

    public function testRejectsZeroOrNegativeMaxConcurrent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConcurrencyLimitMiddleware(0, new Counter(0));
    }

    public function testNthConcurrentAllowedCapPlusOneRejected(): void
    {
        // cap = 2. Pre-load counter to simulate 1 already in-flight.
        $counter = new Counter(1);
        $mw = new ConcurrencyLimitMiddleware(2, $counter);

        // This request becomes the 2nd in-flight (newValue = 2). 2 > 2 is
        // false → ALLOWED. Kills the `>` → `>=` mutant (which would reject 2).
        $response = $this->process($mw);
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

        $response = $this->process($mw);

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

        $response = $this->process($mw);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('text/plain', $recorder->headers['Content-Type'] ?? null);
        $this->assertSame('1', $recorder->headers['Retry-After'] ?? null);
    }

    private function process(ConcurrencyLimitMiddleware $mw): ResponseInterface
    {
        $request = new ServerRequest('/', 'GET', '', []);
        $handler = new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response('OK', 200, '', ['Content-Type' => 'text/plain']);
            }
        };
        return $mw->process($request, $handler);
    }
}

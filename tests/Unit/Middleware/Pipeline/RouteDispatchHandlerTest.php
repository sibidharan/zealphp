<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware\Pipeline;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use ZealPHP\App;
use ZealPHP\Middleware\Pipeline\RouteDispatchHandler;
use ZealPHP\ResponseMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * A ResponseMiddleware double that records the exact (route, params, method)
 * dispatchMatched() received and returns a sentinel response. Lets us assert
 * the terminal forwards its baked-in args verbatim — killing arg-swap,
 * FunctionCallRemoval and ReturnRemoval mutants on the one-line handle().
 */
class RouteDispatchHandlerRecordingDispatcher extends ResponseMiddleware
{
    /** @var array<string, mixed> */
    public array $seenRoute = [];
    /** @var array<string, mixed> */
    public array $seenParams = [];
    public string $seenMethod = '';
    public int $calls = 0;
    public Response $sentinel;

    public function __construct()
    {
        $this->sentinel = new Response('DISPATCH-MATCHED', 207);
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $params
     */
    public function dispatchMatched(array $route, array $params, string $method): ResponseInterface
    {
        $this->calls++;
        $this->seenRoute = $route;
        $this->seenParams = $params;
        $this->seenMethod = $method;
        return $this->sentinel;
    }
}

class RouteDispatchHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    public function testHandleDelegatesToDispatchMatchedWithBakedArgs(): void
    {
        $dispatcher = new RouteDispatchHandlerRecordingDispatcher();
        $route = ['path' => '/users/{id}', 'handler' => 'h'];
        $params = ['id' => '42'];
        $handler = new RouteDispatchHandler($dispatcher, $route, $params, 'DELETE');

        $result = $handler->handle(new ServerRequest('/users/42', 'DELETE'));

        // Exactly one delegation, returning the dispatcher's sentinel verbatim.
        $this->assertSame(1, $dispatcher->calls);
        $this->assertSame($dispatcher->sentinel, $result);
        $this->assertSame('DISPATCH-MATCHED', (string) $result->getBody());
        $this->assertSame(207, $result->getStatusCode());

        // The three baked-in args reach dispatchMatched() in the right slots —
        // an arg swap (route<->params, or method into either array slot) fails.
        $this->assertSame($route, $dispatcher->seenRoute);
        $this->assertSame($params, $dispatcher->seenParams);
        $this->assertSame('DELETE', $dispatcher->seenMethod);
    }

    public function testEachArgIsForwardedDistinctly(): void
    {
        // Distinct, non-overlapping values so a swap of any pair is detectable.
        $dispatcher = new RouteDispatchHandlerRecordingDispatcher();
        $route = ['marker' => 'ROUTE-BAG'];
        $params = ['marker' => 'PARAMS-BAG'];
        $handler = new RouteDispatchHandler($dispatcher, $route, $params, 'PATCH');

        $handler->handle(new ServerRequest('/', 'PATCH'));

        $this->assertSame('ROUTE-BAG', $dispatcher->seenRoute['marker']);
        $this->assertSame('PARAMS-BAG', $dispatcher->seenParams['marker']);
        $this->assertSame('PATCH', $dispatcher->seenMethod);
    }
}

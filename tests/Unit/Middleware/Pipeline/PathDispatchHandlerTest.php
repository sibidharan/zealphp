<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware\Pipeline;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ZealPHP\App;
use ZealPHP\Middleware\Pipeline\PathDispatchHandler;
use ZealPHP\ResponseMiddleware;
use ZealPHP\Tests\TestCase;

/**
 * A ResponseMiddleware double recording the exact (request, method) that
 * matchAndDispatch() received, returning a sentinel response. Proves the
 * path-scoped terminal forwards the SAME request and its baked-in method —
 * killing arg-swap, FunctionCallRemoval, and ReturnRemoval mutants.
 */
class PathDispatchHandlerRecordingDispatcher extends ResponseMiddleware
{
    public ?ServerRequestInterface $seenRequest = null;
    public string $seenMethod = '';
    public int $calls = 0;
    public Response $sentinel;

    public function __construct()
    {
        $this->sentinel = new Response('MATCH-AND-DISPATCH', 203);
    }

    public function matchAndDispatch(ServerRequestInterface $request, string $method): ResponseInterface
    {
        $this->calls++;
        $this->seenRequest = $request;
        $this->seenMethod = $method;
        return $this->sentinel;
    }
}

class PathDispatchHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);
    }

    public function testHandleDelegatesToMatchAndDispatch(): void
    {
        $dispatcher = new PathDispatchHandlerRecordingDispatcher();
        $handler = new PathDispatchHandler($dispatcher, 'PUT');
        $req = new ServerRequest('/scoped/path', 'PUT');

        $result = $handler->handle($req);

        // Exactly one delegation returning the sentinel verbatim.
        $this->assertSame(1, $dispatcher->calls);
        $this->assertSame($dispatcher->sentinel, $result);
        $this->assertSame('MATCH-AND-DISPATCH', (string) $result->getBody());
        $this->assertSame(203, $result->getStatusCode());

        // Same request object is forwarded, and the baked-in method is used.
        $this->assertSame($req, $dispatcher->seenRequest);
        $this->assertSame('PUT', $dispatcher->seenMethod);
    }

    public function testBakedMethodIsUsedNotRequestMethod(): void
    {
        // The terminal threads the *constructor* method, independent of the
        // request's own method — a hardcoded/removed method arg would diverge.
        $dispatcher = new PathDispatchHandlerRecordingDispatcher();
        $handler = new PathDispatchHandler($dispatcher, 'HEAD');

        $handler->handle(new ServerRequest('/', 'GET'));

        $this->assertSame('HEAD', $dispatcher->seenMethod);
    }
}

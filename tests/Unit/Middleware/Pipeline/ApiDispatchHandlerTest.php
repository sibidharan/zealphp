<?php
declare(strict_types=1);

namespace ZealPHP\Tests\Unit\Middleware\Pipeline;

use OpenSwoole\Core\Psr\Response;
use OpenSwoole\Core\Psr\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Middleware\Pipeline\ApiDispatchHandler;
use ZealPHP\RequestContext;
use ZealPHP\Tests\TestCase;
use ZealPHP\ZealAPI;

/**
 * A ZealAPI double recording the (handler, invokeArgs) passed to
 * runHandlerWithContract() and returning a programmable result so we can
 * exercise each branch of ApiDispatchHandler::handle() deterministically.
 */
class ApiDispatchHandlerRecordingApi extends ZealAPI
{
    public ?\Closure $seenHandler = null;
    /** @var array<int, mixed> */
    public array $seenArgs = [];
    public int $calls = 0;
    public mixed $programmedResult = null;

    public function runHandlerWithContract(\Closure $handler, array $invokeArgs): ResponseInterface|\Generator|null
    {
        $this->calls++;
        $this->seenHandler = $handler;
        $this->seenArgs = $invokeArgs;
        $r = $this->programmedResult;
        if ($r instanceof ResponseInterface || $r instanceof \Generator || $r === null) {
            return $r;
        }
        return null;
    }
}

class ApiDispatchHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        App::$cwd = ZEALPHP_ROOT;
        App::superglobals(true);

        $g = G::instance();
        $g->server = ['REQUEST_METHOD' => 'GET'];
        $g->status = null;
        $g->zealphp_request = new \stdClass();
        $g->zealphp_response = new class {
            public function header(string $name, mixed $value, bool $ucwords = true): void
            {
            }
            public function status(int $status): void
            {
            }
        };
    }

    private function makeApi(): ApiDispatchHandlerRecordingApi
    {
        $g = RequestContext::instance();
        return new ApiDispatchHandlerRecordingApi($g->zealphp_request, $g->zealphp_response, ZEALPHP_ROOT);
    }

    public function testResponseResultPassesStraightThrough(): void
    {
        $api = $this->makeApi();
        $sentinel = new Response('API-BODY', 222);
        $api->programmedResult = $sentinel;

        $handlerClosure = function (): string {
            return 'unused';
        };
        $args = ['a', 'b', 3];
        $terminal = new ApiDispatchHandler($api, $handlerClosure, $args);

        $result = $terminal->handle(new ServerRequest('/api/x', 'GET'));

        // ResponseInterface branch: the exact Response is returned untouched.
        $this->assertSame($sentinel, $result);
        $this->assertSame('API-BODY', (string) $result->getBody());
        $this->assertSame(222, $result->getStatusCode());

        // Delegation happened exactly once with the baked-in handler + args.
        $this->assertSame(1, $api->calls);
        $this->assertSame($handlerClosure, $api->seenHandler);
        $this->assertSame($args, $api->seenArgs);
    }

    public function testNullResultYieldsEmptyPlaceholderWithStatus200ByDefault(): void
    {
        // null → handler already streamed; placeholder = new Response('', $g->status ?? 200).
        $api = $this->makeApi();
        $api->programmedResult = null;
        RequestContext::instance()->status = null;

        $terminal = new ApiDispatchHandler($api, fn () => null, []);
        $result = $terminal->handle(new ServerRequest('/api/x', 'GET'));

        $this->assertInstanceOf(Response::class, $result);
        $this->assertSame('', (string) $result->getBody());
        // status null → 200 (the `?? 200` fallback — kills the literal mutation).
        $this->assertSame(200, $result->getStatusCode());
    }

    public function testNullResultUsesContextStatusWhenSet(): void
    {
        // When $g->status is set the placeholder must carry THAT status, not 200.
        // Kills the `$g->status ?? 200` → `200` ReplacementMutation.
        $api = $this->makeApi();
        $api->programmedResult = null;
        RequestContext::instance()->status = 418;

        $terminal = new ApiDispatchHandler($api, fn () => null, []);
        $result = $terminal->handle(new ServerRequest('/api/x', 'GET'));

        $this->assertSame('', (string) $result->getBody());
        $this->assertSame(418, $result->getStatusCode());
    }

    public function testBakedHandlerAndArgsForwardedVerbatim(): void
    {
        // A non-trivial arg list + closure identity, returned via Response so the
        // recording is observable. Kills arg-removal/swap on the construction.
        $api = $this->makeApi();
        $api->programmedResult = new Response('ok', 200);

        $closure = function (int $x): int {
            return $x;
        };
        $args = [10, 'second', ['nested' => true]];
        $terminal = new ApiDispatchHandler($api, $closure, $args);

        $terminal->handle(new ServerRequest('/', 'GET'));

        $this->assertSame($closure, $api->seenHandler);
        $this->assertSame($args, $api->seenArgs);
    }
}

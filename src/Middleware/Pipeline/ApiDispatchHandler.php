<?php
declare(strict_types=1);

namespace ZealPHP\Middleware\Pipeline;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\ZealAPI;

/**
 * Terminal of a ZealAPI file's in-file `$middleware` onion. Once every in-file
 * middleware has called its `$next`, this invokes the resolved api handler
 * closure (`ZealAPI::runHandlerWithContract`) and turns the universal-return
 * contract result into a PSR-7 `ResponseInterface` for the onion:
 *
 *   - a `Response` passes straight through;
 *   - a `\Generator` (SSR-streaming handler) is streamed to the live response
 *     via `App::emitGeneratorStream()` and an empty placeholder is returned;
 *   - `null` (the handler already streamed via `$this->response()` /
 *     `$response->sse()`) yields an empty placeholder.
 *
 * The handler closure + reflection-injected args are baked in at construction,
 * so the onion holds no shared per-request state.
 */
final class ApiDispatchHandler implements RequestHandlerInterface
{
    /** @param array<int, mixed> $invokeArgs */
    public function __construct(
        private ZealAPI $api,
        private \Closure $handler,
        private array $invokeArgs
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = $this->api->runHandlerWithContract($this->handler, $this->invokeArgs);
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        $g = RequestContext::instance();
        if ($result instanceof \Generator) {
            $method = (string)($g->server['REQUEST_METHOD'] ?? 'GET');
            return App::emitGeneratorStream($result, $method);
        }
        // null → the handler already streamed; return an empty placeholder.
        return new Response('', $g->status ?? 200);
    }
}

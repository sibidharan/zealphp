<?php
declare(strict_types=1);

namespace ZealPHP\Middleware\Pipeline;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\ResponseMiddleware;

/**
 * Terminal of an `App::when()` path-scoped middleware onion. Once every
 * path-scoped middleware has called its `$next`, this hands control to the
 * router's `matchAndDispatch()` — route matching + dispatch (including any
 * per-route `middleware:` and, for `/api/*`, ZealAPI's in-file `$middleware`).
 *
 * Stateless: only the HTTP method is baked in at construction; the rest of the
 * match+dispatch reads coroutine-local request state from `$g`.
 */
final class PathDispatchHandler implements RequestHandlerInterface
{
    public function __construct(
        private ResponseMiddleware $dispatcher,
        private string $method
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->dispatcher->matchAndDispatch($request, $this->method);
    }
}

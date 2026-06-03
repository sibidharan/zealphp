<?php
declare(strict_types=1);

namespace ZealPHP\Middleware\Pipeline;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\ResponseMiddleware;

/**
 * Terminal of a per-route middleware onion. Once every route-level middleware
 * (the `middleware:` option / a route group's chain) has called its `$next`,
 * this hands control back to the router's `dispatchMatched()` with the matched
 * route + params (baked in at construction, so the chain carries no shared
 * per-request state and is safe under coroutine concurrency).
 */
final class RouteDispatchHandler implements RequestHandlerInterface
{
    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $params
     */
    public function __construct(
        private ResponseMiddleware $dispatcher,
        private array $route,
        private array $params,
        private string $method
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->dispatcher->dispatchMatched($this->route, $this->params, $this->method);
    }
}

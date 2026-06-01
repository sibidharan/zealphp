<?php
declare(strict_types=1);

namespace ZealPHP\Middleware\Pipeline;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One frame of a middleware onion: pairs a PSR-15 middleware with the
 * RequestHandler it wraps, so a chain can be assembled as nested handlers
 * (`new MiddlewareFrame($m0, new MiddlewareFrame($m1, $terminal))`). Calling
 * `handle()` runs the middleware's `process()`, handing it the inner handler as
 * `$next`. Stateless and cheap — a couple of object fields, no clone-per-step.
 *
 * Shared by every middleware band in ZealPHP — per-route (`middleware:`),
 * route groups, path-scoped (`App::when`), and api in-file `$middleware`. The
 * terminal differs per band (`RouteDispatchHandler`, `PathDispatchHandler`,
 * `ApiDispatchHandler`); the frame is identical.
 */
final class MiddlewareFrame implements RequestHandlerInterface
{
    public function __construct(
        private MiddlewareInterface $middleware,
        private RequestHandlerInterface $next
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, $this->next);
    }
}

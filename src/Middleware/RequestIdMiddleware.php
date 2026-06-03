<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Request-ID middleware — assign every request a correlation id and echo it on
 * the response, so a single request can be traced across logs, services, and
 * the client. The kind of edge concern you'd add at Traefik/nginx, expressed as
 * an in-process middleware that your handlers can also read.
 *
 * Behaviour:
 *   - If the inbound request already carries the header (set by an upstream
 *     proxy), that value is trusted and propagated — unless `$trustInbound` is
 *     false, in which case a fresh id is always minted.
 *   - Otherwise a new id is generated (`bin2hex(random_bytes(16))` → 32 hex
 *     chars, collision-safe).
 *   - The id is stored in the per-request memo so route handlers can read it
 *     (`RequestContext::once('request_id', fn() => null)` /
 *     `RequestContext::has('request_id')`), and written to the response header.
 *
 * Stateless and coroutine-safe: the per-request id lives in `$g` (coroutine
 * context), never on the middleware instance, so one shared instance serves
 * every concurrent request.
 *
 * Usage — global, per-route, or via an alias:
 *
 * ```php
 * $app->addMiddleware(new RequestIdMiddleware());                  // every request
 * App::middlewareAlias('request-id', fn() => new RequestIdMiddleware());
 * $app->route('/api/job', middleware: ['request-id'], handler: $fn); // one route
 * ```
 */
final class RequestIdMiddleware implements MiddlewareInterface
{
    public function __construct(
        private string $headerName = 'X-Request-Id',
        private bool $trustInbound = true
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = RequestContext::instance();

        $id = '';
        if ($this->trustInbound) {
            $inbound = $request->getHeaderLine($this->headerName);
            if ($inbound !== '') {
                $id = $inbound;
            }
        }
        if ($id === '') {
            $id = bin2hex(random_bytes(16));
        }

        // Expose to handlers (RequestContext::once('request_id', fn() => null))
        // and mirror onto the live OpenSwoole response so streaming / fallback
        // paths see it too.
        $g->memo['request_id'] = $id;
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header($this->headerName, $id);
        }

        $response = $handler->handle($request);

        return $response->withHeader($this->headerName, $id);
    }
}

<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Merge Slashes Middleware — Apache `MergeSlashes On` / nginx `merge_slashes`.
 *
 * Collapses runs of consecutive slashes in the request path to a single slash
 * before routing, so `/a//b///c` matches the same route as `/a/b/c`. This is an
 * internal rewrite (no redirect) — it mutates `$g->server['REQUEST_URI']`, which
 * the router reads. The query string is left untouched (only the path is
 * normalized). Register it ahead of route-dependent middleware.
 *
 * Apache enables this by default; ZealPHP matches the raw path unless this
 * middleware is registered.
 *
 * Usage in `app.php`:
 *   `$app->addMiddleware(new \ZealPHP\Middleware\MergeSlashesMiddleware());`
 */
class MergeSlashesMiddleware implements MiddlewareInterface
{
    /**
     * Collapse consecutive slashes in the request path and pass the request on.
     *
     * Mutates `$g->server['REQUEST_URI']` in place (path portion only; query
     * string is left untouched) so the router sees the normalised path. The
     * PSR-7 `$request` object is passed to the next handler unchanged — routing
     * reads `REQUEST_URI` directly.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = RequestContext::instance();
        $uri = $g->server['REQUEST_URI'] ?? '';
        if (is_string($uri) && $uri !== '') {
            $queryPos = strpos($uri, '?');
            $path = $queryPos === false ? $uri : substr($uri, 0, $queryPos);
            $rest = $queryPos === false ? '' : substr($uri, $queryPos);

            $merged = preg_replace('#/{2,}#', '/', $path);
            if (is_string($merged) && $merged !== $path) {
                $g->server['REQUEST_URI'] = $merged . $rest;
            }
        }
        return $handler->handle($request);
    }
}

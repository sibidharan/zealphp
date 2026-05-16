<?php
/**
 * Demo middleware — used by the OSS website's `ZEALPHP_DEMO_MIDDLEWARE=1` toggle.
 *
 * These are intentionally minimal: they show the PSR-15 middleware shape and
 * trace per-request flow into the access log. They do NOT authenticate or
 * validate anything — the names from the previous draft were misleading and
 * earned the framework a public roast (deservedly).
 *
 * Loaded from app.php only when ZEALPHP_DEMO_MIDDLEWARE=1.
 */

namespace ZealPHP\Demo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use function ZealPHP\elog;

class RequestLogMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        elog(sprintf('demo: %s %s', $request->getMethod(), $request->getUri()->getPath()), 'demo');
        return $handler->handle($request);
    }
}

class QueryDumpMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $params = $request->getQueryParams();
        if ($params !== []) {
            elog('demo query: ' . http_build_query($params), 'demo');
        }
        return $handler->handle($request);
    }
}

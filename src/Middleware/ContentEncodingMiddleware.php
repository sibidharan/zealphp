<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\HTTP\MimeResolver;
use ZealPHP\RequestContext;

/**
 * Content-Encoding Middleware — Apache `mod_mime` `AddEncoding` parity.
 *
 * Sets the response `Content-Encoding` header from the request URL's file
 * suffixes. Apache `find_ct` (`mod_mime.c:947–962`) walks every dot-separated
 * suffix and accumulates an encoding chain — `archive.tar.gz` with
 * `AddEncoding x-gzip .gz` yields `Content-Encoding: x-gzip`, and a
 * doubly-encoded `data.gz.gz` yields `gzip, gzip` (order preserved, duplicates
 * intentionally kept). The multi-suffix walk is delegated to {@see MimeResolver}.
 *
 * This middleware is ADDITIVE and OPT-IN: with the default empty map it never
 * touches the response. It only sets `Content-Encoding` when (a) the map has a
 * matching suffix AND (b) the response doesn't already declare one — an
 * explicit `Content-Encoding` set by the handler (or by a compression
 * middleware that actually encoded the body) always wins.
 *
 * Apache equivalent:
 *   `AddEncoding x-gzip   .gz`
 *   `AddEncoding x-bzip2  .bz2`
 *   `AddEncoding br       .br`
 *
 * Usage in `app.php`:
 *   $app->addMiddleware(new \ZealPHP\Middleware\ContentEncodingMiddleware([
 *       'gz'  => 'gzip',
 *       'br'  => 'br',
 *       'bz2' => 'bzip2',
 *   ]));
 */
class ContentEncodingMiddleware implements MiddlewareInterface
{
    private MimeResolver $resolver;

    /** @param array<string, string|int> $map ext => content-encoding (e.g. gz => gzip) */
    public function __construct(array $map = [])
    {
        // Encoding map lives in the resolver's encoding slot; type/language stay empty.
        $this->resolver = new MimeResolver([], $map);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Content-Encoding')) {
            return $response;
        }

        $encoding = $this->resolver->resolve($request->getUri()->getPath())['encoding'];
        if ($encoding === null) {
            return $response;
        }

        $g = RequestContext::instance();
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header('Content-Encoding', $encoding);
        }

        return $response->withHeader('Content-Encoding', $encoding);
    }
}

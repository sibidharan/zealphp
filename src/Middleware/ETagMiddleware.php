<?php
namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use OpenSwoole\Core\Psr\Response;
use ZealPHP\RequestContext;
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;

/**
 * ETag / 304 Not Modified Middleware
 *
 * Generates a weak ETag from an MD5 hash of the response body.
 * Returns 304 when If-None-Match matches — saves bandwidth for unchanged resources.
 *
 * Usage in app.php:
 *   $app->addMiddleware(new \ZealPHP\Middleware\ETagMiddleware());
 *
 * Only applies to GET responses with a non-empty body.
 * Streaming responses (SSE, stream(), Generator yield) are skipped.
 */
class ETagMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($request->getMethod() !== 'GET') {
            return $response;
        }

        $g = RequestContext::instance();
        if ($g->_streaming ?? false) {
            return $response;
        }

        $body = (string) $response->getBody();
        if ($body === '') {
            return $response;
        }

        $etag        = 'W/"' . hash('xxh3', $body) . '"';
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');

        $resp = $g->zealphp_response;
        assert($resp !== null);
        if ($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
            $g->status = 304;
            $resp->header('ETag', $etag);
            return new Response('', 304);
        }

        $resp->header('ETag', $etag);
        return $response;
    }
}

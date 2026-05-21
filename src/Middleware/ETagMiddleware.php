<?php
namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use OpenSwoole\Core\Psr\Response;
use ZealPHP\HTTP\ConditionalRequest;
use ZealPHP\RequestContext;

/**
 * ETag / Conditional-Request Middleware
 *
 * Generates a weak ETag from a hash of the response body and evaluates the
 * RFC 9110 conditional-request preconditions in Apache's `ap_meets_conditions`
 * order via {@see ConditionalRequest}: If-Match -> If-Unmodified-Since ->
 * If-None-Match -> If-Modified-Since.
 *
 * Outcomes:
 *   - 304 Not Modified      — GET/HEAD whose validators say "unchanged".
 *   - 412 Precondition Failed — failed If-Match / If-Unmodified-Since, or a
 *     matched If-None-Match on a non-GET/HEAD method.
 *   - otherwise the original response, with an `ETag` header on GET/HEAD.
 *
 * Usage in app.php:
 *   $app->addMiddleware(new \ZealPHP\Middleware\ETagMiddleware());
 *
 * Streaming responses (SSE, stream(), Generator yield) are skipped — they have
 * no buffered body to hash.
 */
class ETagMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Apache FileETag None — ETag generation disabled server-wide.
        if (!\ZealPHP\App::$file_etag) {
            return $response;
        }

        $g = RequestContext::instance();
        if ($g->_streaming ?? false) {
            return $response;
        }

        // Apache only evaluates conditionals on successful (2xx) responses.
        $status = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            return $response;
        }

        $method = $request->getMethod();
        $isGetOrHead = $method === 'GET' || $method === 'HEAD';

        $body = (string) $response->getBody();
        // Without a body there is no representation to hash, so no ETag exists.
        // We still must honour wildcard / If-Match preconditions, which Apache
        // evaluates regardless of the GET-only ETag generation. Pass an empty
        // ETag so only '*' wildcards and date headers can match.
        $etag = $body === '' ? '' : 'W/"' . hash('xxh3', $body) . '"';

        $reqHeaders = $this->conditionalHeaders($request);

        $outcome = ConditionalRequest::evaluate($method, $reqHeaders, $etag);

        $resp = $g->zealphp_response;
        \assert($resp !== null);

        if ($etag !== '' && $isGetOrHead) {
            $resp->header('ETag', $etag);
        }

        if ($outcome === 304) {
            $g->status = 304;
            return new Response('', 304);
        }
        if ($outcome === 412) {
            $g->status = 412;
            return new Response('', 412);
        }

        return $response;
    }

    /**
     * Collect the conditional-request headers the evaluator consults into a
     * plain map. PSR-7 stores comma-joined values, which is exactly the list
     * form {@see ConditionalRequest} parses.
     *
     * @return array<string,string>
     */
    private function conditionalHeaders(ServerRequestInterface $request): array
    {
        $names = [
            'if-match',
            'if-unmodified-since',
            'if-none-match',
            'if-modified-since',
            'range',
        ];
        $headers = [];
        foreach ($names as $name) {
            if ($request->hasHeader($name)) {
                $headers[$name] = $request->getHeaderLine($name);
            }
        }
        return $headers;
    }
}

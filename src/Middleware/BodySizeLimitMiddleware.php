<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Body Size Limit Middleware — nginx `client_max_body_size` / Apache
 * `LimitRequestBody` / PHP `post_max_size` parity.
 *
 * Rejects requests whose body exceeds a configured maximum with
 * `413 Content Too Large` (nginx returns 413 here too). OpenSwoole's
 * `package_max_length` is the transport-level hard cap; this is the
 * configurable application-level limit with the standard 413 response, so
 * legacy code that expects oversized uploads to be refused behaves as it would
 * under nginx/Apache.
 *
 * The limit accepts a byte count (`new BodySizeLimitMiddleware(10_485_760)`) or
 * an nginx-style size string (`'10m'`, `'512k'`, `'1g'`).
 *
 * **Content-Length requests:** the declared length is checked before the body
 * is read — a fast, zero-copy path that mirrors Apache's `ap_h1_body_in_filter`
 * pre-read guard.
 *
 * **Chunked / no Content-Length requests:** Apache enforces `LimitRequestBody`
 * against the decoded chunked byte count via the `ctx->limit_used` accumulator
 * (`http_filters.c:671-686`). In the OpenSwoole runtime the body is already
 * fully decoded and buffered by the time the PSR-7 middleware stack runs — the
 * `Transfer-Encoding: chunked` layer is owned by OpenSwoole's C parser and is
 * not re-exposed to PHP. This middleware therefore measures the length of the
 * buffered body string (via `$request->getBody()->getSize()` or a
 * `strlen`-equivalent fallback) and enforces the cap on that decoded size.
 * What this covers: any chunked upload that OpenSwoole accepted and decoded into
 * its internal buffer — `package_max_length` is still the outermost transport
 * guard for bodies so large they never reach PHP at all.
 *
 * Usage in app.php:
 *   $app->addMiddleware(new \ZealPHP\Middleware\BodySizeLimitMiddleware('10m'));
 */
class BodySizeLimitMiddleware implements MiddlewareInterface
{
    private int $maxBytes;

    public function __construct(int|string $max)
    {
        $this->maxBytes = is_int($max) ? $max : self::parseSize($max);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Content-Length');
        if ($header !== '' && ctype_digit($header)) {
            // Fast path: declared Content-Length available — check before reading.
            $length = (int) $header;
            if ($length > $this->maxBytes) {
                return new Response('Content Too Large', 413, '', ['Content-Type' => 'text/plain']);
            }
        } else {
            // Chunked / unknown framing: OpenSwoole decodes the chunked stream
            // before handing control to PHP. Enforce the cap against the already-
            // buffered decoded body length, matching Apache's limit_used counter
            // (http_filters.c:671-686) which accumulates bytes regardless of
            // transfer encoding.
            //
            // What this covers: any chunked upload OpenSwoole accepted and decoded
            // into its internal php://memory buffer before dispatching to PHP.
            // package_max_length remains the outermost transport guard for bodies
            // that never reach PHP at all.
            //
            // Size measurement strategy: getSize() calls fstat() on the underlying
            // php://memory resource and returns the total written bytes regardless
            // of seek position — reliable and zero-copy. When getSize() returns null
            // (non-stat-able stream), we fall back to (string)$body which Stream's
            // __toString() handles safely: it rewinds if seekable, catches exceptions
            // and returns '' otherwise — the same pattern used in Response::end()
            // (vendor/openswoole/core/src/Psr/Response.php:137).
            $body = $request->getBody();
            $size = $body->getSize() ?? strlen((string) $body);
            if ($size > $this->maxBytes) {
                return new Response('Content Too Large', 413, '', ['Content-Type' => 'text/plain']);
            }
        }
        return $handler->handle($request);
    }

    /** Parse an nginx-style size string (`10m`, `512k`, `1g`) to bytes. */
    private static function parseSize(string $size): int
    {
        $size = trim($size);
        if (preg_match('/^(\d+)\s*([kmg]?)$/i', $size, $m) !== 1) {
            return 0;
        }
        $value = (int) $m[1];
        return match (strtolower($m[2])) {
            'k'     => $value * 1024,
            'm'     => $value * 1024 * 1024,
            'g'     => $value * 1024 * 1024 * 1024,
            default => $value,
        };
    }
}

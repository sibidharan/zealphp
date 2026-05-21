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
 * Rejects requests whose `Content-Length` exceeds a configured maximum with
 * `413 Content Too Large` (nginx returns 413 here too). OpenSwoole's
 * `package_max_length` is the transport-level hard cap; this is the
 * configurable application-level limit with the standard 413 response, so
 * legacy code that expects oversized uploads to be refused behaves as it would
 * under nginx/Apache.
 *
 * The limit accepts a byte count (`new BodySizeLimitMiddleware(10_485_760)`) or
 * an nginx-style size string (`'10m'`, `'512k'`, `'1g'`).
 *
 * Requests without a `Content-Length` (e.g. chunked) pass through — the cap is
 * advisory at this layer and the transport limit still applies.
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
            $length = (int) $header;
            if ($length > $this->maxBytes) {
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

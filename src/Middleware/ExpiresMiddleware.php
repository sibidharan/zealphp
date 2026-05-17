<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Expires Middleware (Apache mod_expires equivalent)
 *
 * Stamps an `Expires:` header on the response based on its Content-Type.
 * Modern clients prefer `Cache-Control: max-age` (see CacheControlMiddleware)
 * but many legacy proxies and browsers still honour `Expires`, and the
 * Apache `ExpiresByType image/jpeg "access plus 1 month"` idiom is so
 * common in migrated `.htaccess` files that the name parity matters.
 *
 * Apache equivalent:
 *   ExpiresActive On
 *   ExpiresDefault                    "access plus 5 minutes"
 *   ExpiresByType image/jpeg          "access plus 1 month"
 *   ExpiresByType text/css            "access plus 1 year"
 *
 * Constructor takes a Content-Type prefix => relative-date map. Values are
 * parsed by `strtotime()` so any of these forms work:
 *   '+1 year', '+30 days', '+5 minutes', '+86400 seconds'
 *
 * Usage in app.php:
 *
 *   $app->addMiddleware(new \ZealPHP\Middleware\ExpiresMiddleware(
 *       byType: [
 *           'image/'           => '+30 days',
 *           'text/css'         => '+1 year',
 *           'text/javascript'  => '+1 year',
 *           'font/'            => '+1 year',
 *       ],
 *       default: '+5 minutes',
 *   ));
 *
 * Match is by Content-Type prefix (first match wins, longest-prefix-first
 * for determinism). `default` applies when no prefix matches; pass `null`
 * to skip stamping when nothing matches (Apache `ExpiresDefault` unset).
 */
class ExpiresMiddleware implements MiddlewareInterface
{
    /** @var array<string, string> sorted longest-prefix-first */
    private array $byType;

    /**
     * @param array<string, string> $byType  CT-prefix => relative-date
     * @param string|null           $default Relative-date for unmatched CTs (null = skip)
     */
    public function __construct(array $byType = [], private ?string $default = null)
    {
        // Normalise to lowercase and sort longest-prefix-first so
        // ['image/jpeg' => x, 'image/' => y] picks the more specific entry.
        $lowered = [];
        foreach ($byType as $prefix => $rel) {
            $lowered[strtolower((string)$prefix)] = $rel;
        }
        uksort($lowered, fn ($a, $b): int => strlen((string)$b) <=> strlen((string)$a));
        $this->byType = $lowered;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Expires')) {
            return $response;
        }

        $ct = strtolower($response->getHeaderLine('Content-Type'));
        $relative = $this->resolveRelative($ct);
        if ($relative === null) {
            return $response;
        }

        $ts = strtotime($relative);
        if ($ts === false) {
            return $response;
        }

        $value = gmdate('D, d M Y H:i:s', $ts) . ' GMT';

        $g = RequestContext::instance();
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header('Expires', $value);
        }

        return $response->withHeader('Expires', $value);
    }

    private function resolveRelative(string $ct): ?string
    {
        if ($ct === '') {
            return $this->default;
        }
        foreach ($this->byType as $prefix => $rel) {
            if (str_starts_with($ct, $prefix)) {
                return $rel;
            }
        }
        return $this->default;
    }
}

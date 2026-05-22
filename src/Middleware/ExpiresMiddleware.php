<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Expires Middleware (Apache `mod_expires` equivalent)
 *
 * Stamps an `Expires:` header on the response based on its `Content-Type`.
 * Modern clients prefer `Cache-Control: max-age` (see `CacheControlMiddleware`)
 * but many legacy proxies and browsers still honour `Expires`, and the
 * Apache `ExpiresByType image/jpeg "access plus 1 month"` idiom is so
 * common in migrated `.htaccess` files that the name parity matters.
 *
 * Apache equivalent:
 *
 * ```
 * ExpiresActive On
 * ExpiresDefault                    "access plus 5 minutes"
 * ExpiresByType image/jpeg          "access plus 1 month"
 * ExpiresByType text/css            "access plus 1 year"
 * ```
 *
 * Constructor takes a `Content-Type` prefix => relative-date map. Values are
 * parsed by `strtotime()` so any of these forms work:
 *   `'+1 year'`, `'+30 days'`, `'+5 minutes'`, `'+86400 seconds'`
 *
 * **Base time (`'A'` vs `'M'`):**
 * Pass `base: 'A'` (default) to compute expiry relative to the current
 * request time — Apache `ExpiresDefault "access plus N"` / `A` base.
 * Pass `base: 'M'` to compute expiry relative to the `Last-Modified` header
 * value on the response (file modification time) — Apache `M` base. If no
 * `Last-Modified` header is present on the response, the middleware falls
 * back to access-time (the current request time) and the behaviour is
 * identical to `base: 'A'`.
 *
 * **Dual-header emission (`emitCacheControl: true`):**
 * Apache's `set_expiration_fields()` always emits *both* `Expires:` and
 * `Cache-Control: max-age=N` from a single config rule, where `max-age` is
 * derived as `expires - request_time` (`mod_expires.c:432–437`). Set
 * `$emitCacheControl = true` to replicate this atomicity: when `Expires` is
 * stamped, a matching `Cache-Control: max-age=N, public` (or `private`) is
 * also emitted with the same delta, keeping both headers in sync to the
 * second. Existing `Cache-Control` headers are **not** overwritten (same
 * skip-if-present guard as `CacheControlMiddleware`).
 *
 * **Error-response suppression:**
 * Apache `mod_expires` never stamps headers on 4xx/5xx responses
 * (`mod_expires.c:455–458`). This middleware follows the same rule: responses
 * with status >= `400` are returned unchanged.
 *
 * **Negative/past expiry clamping:**
 * Apache clamps past expiry to `request_time` (`mod_expires.c:429–431`), which
 * results in `max-age=0`. This middleware does the same: if the computed
 * expiry timestamp is in the past, `Expires` is set to the current time and
 * `Cache-Control: max-age=0` is emitted (when `$emitCacheControl = true`).
 *
 * Usage in `app.php`:
 *
 * ```php
 * // Access-time base, Expires header only (legacy compat):
 * $app->addMiddleware(new \ZealPHP\Middleware\ExpiresMiddleware(
 *     byType: [
 *         'image/'           => '+30 days',
 *         'text/css'         => '+1 year',
 *         'text/javascript'  => '+1 year',
 *         'font/'            => '+1 year',
 *     ],
 *     default: '+5 minutes',
 * ));
 *
 * // Apache-parity: both Expires + Cache-Control: max-age from one rule:
 * $app->addMiddleware(new \ZealPHP\Middleware\ExpiresMiddleware(
 *     byType: ['image/' => '+30 days', 'text/css' => '+1 year'],
 *     default: '+5 minutes',
 *     emitCacheControl: true,
 * ));
 *
 * // M (modification-time) base — expiry relative to Last-Modified:
 * $app->addMiddleware(new \ZealPHP\Middleware\ExpiresMiddleware(
 *     byType: ['text/html' => '+5 minutes'],
 *     base: 'M',
 *     emitCacheControl: true,
 * ));
 * ```
 *
 * Match is by `Content-Type` prefix (first match wins, longest-prefix-first
 * for determinism). `default` applies when no prefix matches; pass `null`
 * to skip stamping when nothing matches (Apache `ExpiresDefault` unset).
 */
class ExpiresMiddleware implements MiddlewareInterface
{
    /** @var array<string, string> sorted longest-prefix-first */
    private array $byType;

    /**
     * @param array<string, string> $byType          CT-prefix => relative-date
     * @param string|null           $default          Relative-date for unmatched CTs (`null` = skip)
     * @param string                $base             `'A'` = access/request time (default); `'M'` = `Last-Modified` mtime
     * @param bool                  $emitCacheControl Also emit `Cache-Control: max-age=N` (Apache dual-header parity)
     * @param bool                  $publicCache      Whether emitted `Cache-Control` uses `'public'` (true) or `'private'`
     */
    public function __construct(
        array $byType = [],
        private ?string $default = null,
        private string $base = 'A',
        private bool $emitCacheControl = false,
        private bool $publicCache = true,
    ) {
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

        // Apache mod_expires.c:455–458: never stamp headers on error responses.
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        if ($response->hasHeader('Expires')) {
            return $response;
        }

        $ct = strtolower($response->getHeaderLine('Content-Type'));
        $relative = $this->resolveRelative($ct);
        if ($relative === null) {
            return $response;
        }

        // Determine base time: 'M' uses Last-Modified mtime, falls back to now.
        $now = time();
        $baseTime = $now;
        if ($this->base === 'M') {
            $lastModified = $response->getHeaderLine('Last-Modified');
            if ($lastModified !== '') {
                $mtime = strtotime($lastModified);
                if ($mtime !== false) {
                    $baseTime = $mtime;
                }
            }
        }

        // Compute expiry timestamp relative to the chosen base time.
        $ts = strtotime($relative, $baseTime);
        if ($ts === false) {
            return $response;
        }

        // Apache mod_expires.c:429–431: clamp past expiry to request time
        // which results in max-age=0 rather than a negative value.
        if ($ts < $now) {
            $ts = $now;
        }

        $value = gmdate('D, d M Y H:i:s', $ts) . ' GMT';

        $g = RequestContext::instance();
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header('Expires', $value);
        }

        $response = $response->withHeader('Expires', $value);

        // Dual-header emission: Apache set_expiration_fields() always emits
        // both Expires and Cache-Control: max-age=N from one rule
        // (mod_expires.c:432–437). Opt-in via $emitCacheControl = true.
        if ($this->emitCacheControl && !$response->hasHeader('Cache-Control')) {
            $maxAge = max(0, $ts - $now);
            $visibility = $this->publicCache ? 'public' : 'private';
            $ccValue = sprintf('max-age=%d, %s', $maxAge, $visibility);

            if ($g->zealphp_response !== null) {
                $g->zealphp_response->header('Cache-Control', $ccValue);
            }

            $response = $response->withHeader('Cache-Control', $ccValue);
        }

        return $response;
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

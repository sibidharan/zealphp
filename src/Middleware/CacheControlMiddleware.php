<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Cache-Control Middleware
 *
 * Stamps `Cache-Control: max-age=N, public` on responses whose URL path ends
 * in a recognised static-asset extension. Mirrors the most common Apache
 * `.htaccess` pattern for "tell the browser to cache static assets for 30 days".
 *
 * Apache equivalent:
 *
 * ```
 * <FilesMatch "\.(css|js|jpe?g|png|gif|svg|ico|woff2?)$">
 *     Header set Cache-Control "max-age=2628000, public"
 * </FilesMatch>
 * ```
 *
 * nginx equivalent:
 *
 * ```
 * location ~* \.(css|js|jpe?g|png|gif|svg|ico|woff2?)$ {
 *     expires 30d;
 *     add_header Cache-Control "public, max-age=2628000";
 * }
 * ```
 *
 * Constructor accepts a map of `extension => max-age-seconds`. Defaults
 * cover the common static-asset extensions at 30 days (`2_628_000`s).
 *
 * Pass `$publicCache = false` to emit `private` instead of `public` — useful
 * when you serve per-user assets that intermediate caches must not store.
 *
 * **Error-response suppression:**
 * Apache `mod_expires` (and the `FilesMatch` + `Header set` equivalent) never
 * stamps caching headers on 4xx/5xx responses. Responses with status >= `400`
 * are returned unchanged, matching Apache behaviour (`mod_expires.c:455–458`).
 *
 * Usage in `app.php`:
 *
 * ```php
 * // defaults — 30d for css/js/images/fonts
 * $app->addMiddleware(new \ZealPHP\Middleware\CacheControlMiddleware());
 *
 * // custom: 1y for fingerprinted hashes, 5m for HTML
 * $app->addMiddleware(new \ZealPHP\Middleware\CacheControlMiddleware([
 *     'css' => 31536000, 'js' => 31536000, 'woff2' => 31536000,
 *     'html' => 300,
 * ]));
 * ```
 */
class CacheControlMiddleware implements MiddlewareInterface
{
    /** ~30 days in seconds — Apache's classic "ExpiresDefault A2628000" value. */
    private const DEFAULT_MAX_AGE = 2_628_000;

    /** @var array<string, int> */
    private const DEFAULT_MAP = [
        'css'   => self::DEFAULT_MAX_AGE,
        'js'    => self::DEFAULT_MAX_AGE,
        'mjs'   => self::DEFAULT_MAX_AGE,
        'jpg'   => self::DEFAULT_MAX_AGE,
        'jpeg'  => self::DEFAULT_MAX_AGE,
        'png'   => self::DEFAULT_MAX_AGE,
        'gif'   => self::DEFAULT_MAX_AGE,
        'webp'  => self::DEFAULT_MAX_AGE,
        'avif'  => self::DEFAULT_MAX_AGE,
        'svg'   => self::DEFAULT_MAX_AGE,
        'ico'   => self::DEFAULT_MAX_AGE,
        'woff'  => self::DEFAULT_MAX_AGE,
        'woff2' => self::DEFAULT_MAX_AGE,
        'ttf'   => self::DEFAULT_MAX_AGE,
        'eot'   => self::DEFAULT_MAX_AGE,
        'otf'   => self::DEFAULT_MAX_AGE,
        'wasm'  => self::DEFAULT_MAX_AGE,
    ];

    /** @var array<string, int> */
    private array $map;

    /**
     * @param array<string, int>|null $map  ext => seconds; null uses defaults
     */
    public function __construct(?array $map = null, private bool $publicCache = true)
    {
        $this->map = $this->normaliseMap($map ?? self::DEFAULT_MAP);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        // Apache mod_expires.c:455–458: never stamp caching headers on error responses.
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        if ($response->hasHeader('Cache-Control')) {
            return $response;
        }

        $path = $request->getUri()->getPath();
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === '' || !isset($this->map[$ext])) {
            return $response;
        }

        $value = sprintf('max-age=%d, %s', $this->map[$ext], $this->publicCache ? 'public' : 'private');

        $g = RequestContext::instance();
        if ($g->zealphp_response !== null) {
            $g->zealphp_response->header('Cache-Control', $value);
        }

        return $response->withHeader('Cache-Control', $value);
    }

    /**
     * @param array<string, int> $map
     * @return array<string, int>
     */
    private function normaliseMap(array $map): array
    {
        $out = [];
        foreach ($map as $ext => $seconds) {
            $out[strtolower(ltrim((string)$ext, '.'))] = (int)$seconds;
        }
        return $out;
    }
}

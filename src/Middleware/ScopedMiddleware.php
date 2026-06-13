<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Scoped Middleware — apply another middleware only to matching request paths.
 *
 * Apache scopes directives with containers; this is the middleware equivalent.
 * Wrap any middleware so it runs only when the request path matches:
 *
 *   - `ScopedMiddleware::location($inner, '/admin')` — `<Location "/admin">`:
 *     a **literal URL-path prefix** (matches `/admin`, `/admin/x`, and — like
 *     Apache — `/administrator`; use a trailing slash or a regex for segment
 *     precision).
 *   - `ScopedMiddleware::match($inner, '#^/api/#')` — `<LocationMatch>` /
 *     `<FilesMatch>`: a PCRE pattern against the path.
 *
 * Outside the scope the inner middleware is skipped entirely and the request
 * passes straight through to the rest of the stack. Inside the scope the inner
 * middleware runs normally — free to short-circuit (e.g. a 403/redirect) or
 * continue via the handler.
 *
 * Usage in app.php:
 *
 * ```php
 * $app->addMiddleware(ScopedMiddleware::location(new BasicAuthMiddleware(...), '/admin'));
 * $app->addMiddleware(ScopedMiddleware::match(new BlockPhpExtMiddleware(), '#\.php$#'));
 * ```
 */
final class ScopedMiddleware implements MiddlewareInterface
{
    public function __construct(
        private MiddlewareInterface $inner,
        private string $pattern,
        private bool $regex = false
    ) {
    }

    /** `<Location prefix>` — literal URL-path prefix scope. */
    public static function location(MiddlewareInterface $inner, string $prefix): self
    {
        return new self($inner, $prefix, false);
    }

    /** `<LocationMatch>` / `<FilesMatch>` — PCRE path scope. */
    public static function match(MiddlewareInterface $inner, string $regex): self
    {
        return new self($inner, $regex, true);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // #232 — match on the SAME path the router dispatches against. The router
        // normalizes `$g->server['REQUEST_URI']` via App::normalizeRequestPath
        // (collapse `//`, drop `/./`, unwind `..`) in ResponseMiddleware, AFTER this
        // global ScopedMiddleware runs — so we must read the raw REQUEST_URI and
        // normalize it identically here. Using `$request->getUri()->getPath()` is
        // NOT equivalent: the PSR Uri parser treats a leading `//admin` as the URI
        // authority, dropping it to `/secret`, so `//admin/secret` would skip a
        // `/admin` guard while still routing to `/admin/secret` (auth/IP/php-block
        // bypass). REQUEST_URI preserves the raw target. Fall back to the PSR path
        // for pure-PSR contexts (tests) where REQUEST_URI isn't populated on $g.
        $serverParams = $request->getServerParams();
        // #406 — the live request's server params come from OpenSwoole's native
        // `$request->server`, whose keys are LOWER-case (`request_uri`). Reading
        // only the upper-case `REQUEST_URI` made the #232 raw-target guard inert
        // on every real request (it always fell back to the authority-dropping
        // PSR path, so `//admin/secret` bypassed a `/admin` scope). Accept both
        // casings.
        $reqUri = $serverParams['REQUEST_URI'] ?? $serverParams['request_uri'] ?? null;
        $target = (is_string($reqUri) && $reqUri !== '')
            ? $reqUri
            : $request->getUri()->getPath();
        $rawPath = explode('?', $target, 2)[0];
        $path = \ZealPHP\App::normalizeRequestPath($rawPath);
        $inScope = $this->regex
            ? preg_match($this->pattern, $path) === 1
            : str_starts_with($path, $this->pattern);

        if ($inScope) {
            return $this->inner->process($request, $handler);
        }
        return $handler->handle($request);
    }
}

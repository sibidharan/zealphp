<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * CSRF Protection Middleware.
 *
 * Generates a per-session CSRF token on safe requests (`GET`/`HEAD`/`OPTIONS`)
 * and validates it on state-changing requests (`POST`/`PUT`/`PATCH`/`DELETE`).
 *
 * Token sources checked (first match wins):
 *   1. `$_POST['_csrf_token']` (form hidden field)
 *   2. `X-CSRF-Token` request header (AJAX/htmx)
 *
 * The token is stored in `$g->session['_csrf_token']` and exposed via
 * `$g->memo['csrf_token']` for templates to read.
 *
 * Usage in `app.php`:
 * ```php
 * $app->addMiddleware(new CsrfMiddleware());
 * ```
 *
 * In templates:
 * ```php
 * <input type="hidden" name="_csrf_token" value="<?= $g->memo['csrf_token'] ?>">
 * ```
 *
 * With htmx:
 * ```php
 * <body hx-headers='{"X-CSRF-Token": "<?= $g->memo['csrf_token'] ?>"}'>
 * ```
 *
 * To exempt paths (e.g. webhooks):
 * ```php
 * $app->addMiddleware(new CsrfMiddleware(exempt: ['/api/webhook', '/api/stripe']));
 * ```
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const TOKEN_LENGTH = 32;
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const FIELD_NAME = '_csrf_token';
    private const HEADER_NAME = 'X-CSRF-Token';

    /** @var list<string> URL path prefixes that skip CSRF validation. */
    private array $exempt;

    /**
     * @param list<string> $exempt URL prefixes to skip validation (e.g. `['/api/webhook']`).
     */
    public function __construct(array $exempt = [])
    {
        $this->exempt = $exempt;
    }

    /**
     * Generate or validate the CSRF token for the current request.
     *
     * Safe methods (`GET`/`HEAD`/`OPTIONS`) and exempt path prefixes pass through
     * without validation. All other methods require a matching token submitted
     * via the `_csrf_token` POST field or the `X-CSRF-Token` header; a mismatch
     * or missing token returns a `403` response immediately.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = RequestContext::instance();
        $method = strtoupper((string) $request->getMethod());
        $path = $request->getUri()->getPath();

        if (!isset($g->session['_csrf_token']) || !is_string($g->session['_csrf_token'])) {
            $g->session['_csrf_token'] = bin2hex(random_bytes(self::TOKEN_LENGTH));
        }

        $g->memo['csrf_token'] = $g->session['_csrf_token'];

        if (in_array($method, self::SAFE_METHODS, true)) {
            return $handler->handle($request);
        }

        foreach ($this->exempt as $prefix) {
            if (self::pathMatchesExempt($path, $prefix)) {
                return $handler->handle($request);
            }
        }

        $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper(self::HEADER_NAME));
        $submitted = $g->post[self::FIELD_NAME]
            ?? $g->server[$headerKey]
            ?? null;

        if (!is_string($submitted) || !hash_equals($g->session['_csrf_token'], $submitted)) {
            return new Response('', 403, '', ['Content-Type' => 'text/plain']);
        }

        return $handler->handle($request);
    }

    /**
     * Segment-boundary exemption match (#342).
     *
     * An exempt entry exempts the request path only on an exact match or a
     * true path-segment boundary — NOT a loose `str_starts_with` prefix. So
     * `'/api/stripe'` exempts `/api/stripe` and `/api/stripe/charge`, but
     * NEVER `/api/stripeKeyUpdate` (the historical CSRF-bypass: an attacker
     * appended text to an exempt prefix to skip validation on a sibling
     * state-changing route). Same class of fix as the #232 `ScopedMiddleware`
     * segment-safe scope. A trailing-slash entry (`'/api/stripe/'`) keeps its
     * prefix semantics for the subtree below it (the next char after the
     * prefix is already the `/` boundary).
     */
    private static function pathMatchesExempt(string $path, string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }
        if ($path === $prefix) {
            return true;
        }
        if (!str_starts_with($path, $prefix)) {
            return false;
        }
        // Only a match when the prefix ends at a segment boundary: either the
        // prefix already ends in '/', or the next char in the path is '/'.
        return str_ends_with($prefix, '/') || $path[strlen($prefix)] === '/';
    }
}

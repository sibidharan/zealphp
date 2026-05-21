<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Referer Middleware — nginx `valid_referers` / `$invalid_referer` parity.
 *
 * Hotlink protection: refuses requests whose `Referer` header isn't in the
 * allowed set with `403 Forbidden` (the canonical `if ($invalid_referer) { return 403; }`
 * pattern). Like nginx, this guards against casual mass-linking, not a
 * determined attacker (Referer is trivially forged).
 *
 * Allowed-referer specs mirror nginx:
 *   - `allowNone`    (nginx `none`)    — a missing Referer is allowed (default true)
 *   - `allowBlocked` (nginx `blocked`) — a Referer not starting with http(s):// is
 *                                        allowed (proxy-stripped) (default true)
 *   - host string — exact host, or wildcard `*.example.com` / `example.*`, with an
 *     optional URI prefix (`example.org/galleries/`); port is ignored
 *   - regex — prefixed with `~`, matched case-insensitively (nginx NGX_REGEX_CASELESS
 *     parity) against the text after the scheme; malformed patterns are treated as
 *     misconfigurations — logged and the spec is skipped (fail-closed)
 *   - `server_names` token — pass `serverNames: ['myapp.example.com']` to auto-allow
 *     requests originating from your own host(s); mirrors nginx `server_names` token
 *     (which auto-populates from the virtual host's `server_name` directives)
 *
 * NOTE on empty-specs behaviour: unlike nginx (which with no `valid_referers`
 * directive passes ALL requests), an empty `$referers` array here means no
 * host/regex spec is in the allow-list, so any http(s):// Referer is blocked.
 * This is intentional for a middleware (opt-in allow-listing); use `allowNone`
 * and `allowBlocked` to tune the none/blocked token behaviour.
 *
 * Usage in app.php:
 *   $app->addMiddleware(new \ZealPHP\Middleware\RefererMiddleware(
 *       ['example.com', '*.example.com', '~\.google\.'],
 *       serverNames: ['myapp.example.com'],
 *   ));
 */
class RefererMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $specs;

    /**
     * @param list<string> $referers    Allowed host/wildcard/regex specs.
     * @param list<string> $serverNames Own host(s) to auto-allow (nginx `server_names` token).
     */
    public function __construct(
        array $referers,
        private bool $allowNone = true,
        private bool $allowBlocked = true,
        private array $serverNames = []
    ) {
        $this->specs = array_values(array_filter($referers, 'is_string'));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->isInvalid($request->getHeaderLine('Referer'))) {
            return new Response('Forbidden', 403, '', ['Content-Type' => 'text/plain']);
        }
        return $handler->handle($request);
    }

    /** Mirror nginx's $invalid_referer: true ⇒ block. */
    private function isInvalid(string $referer): bool
    {
        if ($referer === '') {
            return !$this->allowNone;
        }
        if (!preg_match('#^https?://#i', $referer)) {
            return !$this->allowBlocked; // "blocked" — present but scheme-less
        }

        $afterScheme = preg_replace('#^https?://#i', '', $referer) ?? '';
        $slash = strpos($afterScheme, '/');
        $host = $slash === false ? $afterScheme : substr($afterScheme, 0, $slash);
        $path = $slash === false ? '' : substr($afterScheme, $slash);
        $host = strtolower((string) (explode(':', $host)[0])); // drop port

        // server_names token: auto-allow own hosts (nginx `server_names` parity).
        foreach ($this->serverNames as $ownHost) {
            if (strtolower($ownHost) === $host) {
                return false; // valid — own server
            }
        }

        foreach ($this->specs as $spec) {
            if ($this->matchSpec($spec, $host, $path, $afterScheme)) {
                return false; // valid
            }
        }
        return true; // no spec matched ⇒ invalid
    }

    private function matchSpec(string $spec, string $host, string $path, string $afterScheme): bool
    {
        if (str_starts_with($spec, '~')) {
            // nginx compiles all ~regex specs with NGX_REGEX_CASELESS; use `i` flag.
            // Use @ to suppress the PHP warning on malformed patterns (matches
            // BodyRewriteMiddleware pattern); check return value for false to detect
            // misconfiguration — log via elog() and skip (fail-closed, not fail-open).
            $pattern = '#' . substr($spec, 1) . '#i';
            $result = @preg_match($pattern, $afterScheme);
            if ($result === false) {
                if (function_exists('ZealPHP\\elog')) {
                    \ZealPHP\elog('[RefererMiddleware] malformed regex spec: ' . $spec);
                }
                return false; // skip — deny by omission
            }
            return $result === 1;
        }
        $slash = strpos($spec, '/');
        $specHost = strtolower($slash === false ? $spec : substr($spec, 0, $slash));
        $specPath = $slash === false ? '' : substr($spec, $slash);
        if (!$this->hostMatches($specHost, $host)) {
            return false;
        }
        return $specPath === '' || str_starts_with($path, $specPath);
    }

    private function hostMatches(string $spec, string $host): bool
    {
        if ($spec === $host) {
            return true;
        }
        if (str_starts_with($spec, '*.')) {           // *.example.com
            return str_ends_with($host, substr($spec, 1)) || $host === substr($spec, 2);
        }
        if (str_ends_with($spec, '.*')) {
            // example.* — nginx dns_wc_head: matches only a SINGLE trailing DNS label
            // after the base (example.com, example.org) — NOT example.evil.com.
            // nginx uses label-aware wildcard hashing (ngx_hash_wildcard_init), which
            // walks the DNS label graph and stops at one label boundary; str_starts_with
            // alone would allow example.evil.com (over-match). Fix: verify the host is
            // exactly "base" + "." + one DNS label (no further dots after the base).
            $base = substr($spec, 0, -2); // "example" from "example.*"
            if (!str_starts_with($host, $base . '.')) {
                return false;
            }
            $remainder = substr($host, strlen($base) + 1); // everything after "example."
            return $remainder !== '' && strpos($remainder, '.') === false;
        }
        return false;
    }
}

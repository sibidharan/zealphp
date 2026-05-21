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
 *   - regex — prefixed with `~`, matched against the text after the scheme
 *
 * Usage in app.php:
 *   $app->addMiddleware(new \ZealPHP\Middleware\RefererMiddleware(
 *       ['example.com', '*.example.com', '~\.google\.'],
 *   ));
 */
class RefererMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $specs;

    /** @param list<string> $referers Allowed host/wildcard/regex specs. */
    public function __construct(
        array $referers,
        private bool $allowNone = true,
        private bool $allowBlocked = true
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
            return @preg_match('#' . substr($spec, 1) . '#', $afterScheme) === 1;
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
        if (str_ends_with($spec, '.*')) {             // example.*
            return str_starts_with($host, substr($spec, 0, -1));
        }
        return false;
    }
}

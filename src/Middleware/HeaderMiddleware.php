<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

/**
 * Header Middleware
 *
 * Declarative response-header manipulation. The most common use case is
 * stamping security headers (X-Frame-Options, CSP, Strict-Transport-Security,
 * X-Content-Type-Options, Referrer-Policy, Permissions-Policy) onto every
 * response without sprinkling `$response->header(...)` calls through handlers.
 *
 * Apache equivalent (mod_headers):
 *   Header set X-Frame-Options "DENY"
 *   Header append Vary "Accept-Encoding"
 *   Header unset Server
 *   Header add Set-Cookie "..."
 *
 * nginx equivalent:
 *   add_header X-Frame-Options "DENY" always;
 *   more_clear_headers Server;   # ngx_headers_more module
 *
 * Constructor accepts a config array with four operations:
 *   - `set`:    overwrite the header value (replaces existing)
 *   - `add`:    append a value (like Apache `Header add` — emits multiple lines)
 *   - `append`: append to existing value comma-separated (like `Header append Vary "X"`)
 *   - `unset`:  list of headers to strip from the response
 *
 * Usage in app.php:
 *
 *   $app->addMiddleware(new \ZealPHP\Middleware\HeaderMiddleware([
 *       'set' => [
 *           'X-Frame-Options'            => 'DENY',
 *           'X-Content-Type-Options'     => 'nosniff',
 *           'Referrer-Policy'            => 'strict-origin-when-cross-origin',
 *           'Strict-Transport-Security'  => 'max-age=31536000; includeSubDomains',
 *           'Content-Security-Policy'    => "default-src 'self'",
 *       ],
 *       'append' => ['Vary' => 'Accept-Encoding'],
 *       'unset'  => ['Server', 'X-Powered-By'],
 *   ]));
 */
class HeaderMiddleware implements MiddlewareInterface
{
    /** @var array<string, string> */
    private array $set;
    /** @var array<string, string|string[]> */
    private array $add;
    /** @var array<string, string> */
    private array $append;
    /** @var string[] */
    private array $unset;

    /**
     * @param array{
     *     set?: array<string, string>,
     *     add?: array<string, string|string[]>,
     *     append?: array<string, string>,
     *     unset?: string[],
     * } $config
     */
    public function __construct(array $config = [])
    {
        $this->set    = $config['set']    ?? [];
        $this->add    = $config['add']    ?? [];
        $this->append = $config['append'] ?? [];
        $this->unset  = $config['unset']  ?? [];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $g = RequestContext::instance();
        $resp = $g->zealphp_response;

        foreach ($this->set as $name => $value) {
            $response = $response->withHeader($name, $value);
            if ($resp !== null) {
                $resp->header($name, $value);
            }
        }

        foreach ($this->add as $name => $value) {
            $values = is_array($value) ? $value : [$value];
            foreach ($values as $v) {
                $response = $response->withAddedHeader($name, $v);
                if ($resp !== null) {
                    // OpenSwoole's header() replaces by default — explicitly
                    // disable replace so multiple Set-Cookie / Link entries
                    // accumulate (mod_headers `Header add` semantics).
                    $resp->header($name, $v, false);
                }
            }
        }

        foreach ($this->append as $name => $value) {
            $existing = $response->getHeaderLine($name);
            $merged   = $existing === '' ? $value : $existing . ', ' . $value;
            $response = $response->withHeader($name, $merged);
            if ($resp !== null) {
                $resp->header($name, $merged);
            }
        }

        foreach ($this->unset as $name) {
            $response = $response->withoutHeader($name);
            // OpenSwoole exposes no direct "remove header" hook on the
            // wrapper; setting to '' is the conventional drop.
            if ($resp !== null) {
                $resp->header($name, '');
            }
        }

        return $response;
    }
}

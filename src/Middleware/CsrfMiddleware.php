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
 * CSRF Protection Middleware
 *
 * Generates a per-session CSRF token on safe requests (GET/HEAD/OPTIONS)
 * and validates it on state-changing requests (POST/PUT/PATCH/DELETE).
 *
 * Token sources checked (first match wins):
 *   1. `$_POST['_csrf_token']` (form hidden field)
 *   2. `X-CSRF-Token` request header (AJAX/htmx)
 *
 * Token is stored in `$g->session['_csrf_token']` and exposed via
 * `$g->memo['csrf_token']` for templates to read.
 *
 * Usage:
 *   $app->addMiddleware(new CsrfMiddleware());
 *
 * In templates:
 *   <input type="hidden" name="_csrf_token" value="<?= $g->memo['csrf_token'] ?>">
 *
 * With htmx:
 *   <body hx-headers='{"X-CSRF-Token": "<?= $g->memo['csrf_token'] ?>"}'>
 *
 * To exempt paths (e.g. webhooks):
 *   $app->addMiddleware(new CsrfMiddleware(exempt: ['/api/webhook', '/api/stripe']));
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    private const TOKEN_LENGTH = 32;
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];
    private const FIELD_NAME = '_csrf_token';
    private const HEADER_NAME = 'X-CSRF-Token';

    /** @var list<string> */
    private array $exempt;

    /** @param list<string> $exempt URL prefixes to skip validation */
    public function __construct(array $exempt = [])
    {
        $this->exempt = $exempt;
    }

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
            if (str_starts_with($path, $prefix)) {
                return $handler->handle($request);
            }
        }

        $submitted = $g->post[self::FIELD_NAME]
            ?? $g->server['HTTP_X_CSRF_TOKEN']
            ?? null;

        if (!is_string($submitted) || !hash_equals($g->session['_csrf_token'], $submitted)) {
            return new Response('', 403, '', ['Content-Type' => 'text/plain']);
        }

        return $handler->handle($request);
    }
}

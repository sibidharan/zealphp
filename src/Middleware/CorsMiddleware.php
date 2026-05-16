<?php
namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use OpenSwoole\Core\Psr\Response;
use ZealPHP\RequestContext;
use function ZealPHP\response_add_header;

/**
 * CORS Middleware
 *
 * Handles Cross-Origin Resource Sharing headers and OPTIONS preflight requests.
 *
 * Origin resolution order:
 *   1. Constructor `$origins` argument (if not null)
 *   2. ZEALPHP_CORS_ORIGINS env var (comma-separated)
 *   3. Falls back to ['*'] with a one-time warning logged via elog()
 *
 * Wildcard (`*`) is a security foot-gun for any API serving credentials or
 * user-scoped data; the warning surfaces this without breaking existing apps.
 * Lock down origins explicitly in production:
 *
 *   $app->addMiddleware(new \ZealPHP\Middleware\CorsMiddleware(
 *       origins:     ['https://myapp.com'],
 *       methods:     ['GET', 'POST', 'PUT', 'DELETE'],
 *       headers:     ['Content-Type', 'Authorization'],
 *       credentials: true,
 *       maxAge:      3600,
 *   ));
 *
 * Or, to lock down without touching code:
 *
 *   ZEALPHP_CORS_ORIGINS="https://myapp.com,https://admin.myapp.com" php app.php
 */
class CorsMiddleware implements MiddlewareInterface
{
    private array $origins;
    private array $methods;
    private array $headers;
    private bool  $credentials;
    private int   $maxAge;

    private static bool $warnedWildcard = false;

    public function __construct(
        ?array $origins     = null,
        array  $methods     = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array  $headers     = ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
        bool   $credentials = false,
        int    $maxAge      = 86400
    ) {
        $this->origins     = $this->resolveOriginsList($origins);
        $this->methods     = $methods;
        $this->headers     = $headers;
        $this->credentials = $credentials;
        $this->maxAge      = $maxAge;
    }

    private function resolveOriginsList(?array $explicit): array
    {
        if ($explicit !== null) {
            return $explicit;
        }
        $env = getenv('ZEALPHP_CORS_ORIGINS');
        if ($env !== false && trim($env) !== '') {
            return array_values(array_filter(
                array_map('trim', explode(',', $env)),
                fn (string $s): bool => $s !== ''
            ));
        }
        if (!self::$warnedWildcard) {
            self::$warnedWildcard = true;
            if (function_exists('ZealPHP\\elog')) {
                \ZealPHP\elog(
                    'CorsMiddleware: no origins configured; defaulting to "*". '
                    . 'Set ZEALPHP_CORS_ORIGINS or pass origins explicitly for production use.',
                    'cors'
                );
            }
        }
        return ['*'];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin        = $request->getHeaderLine('Origin');
        $allowedOrigin = $this->resolveOrigin($origin);
        $g = RequestContext::instance();
        $resp = $g->zealphp_response;

        if ($request->getMethod() === 'OPTIONS' && $origin !== '') {
            $g->status = 204;
            $resp->header('Access-Control-Allow-Origin',      $allowedOrigin);
            $resp->header('Access-Control-Allow-Methods',     implode(', ', $this->methods));
            $resp->header('Access-Control-Allow-Headers',     implode(', ', $this->headers));
            $resp->header('Access-Control-Max-Age',           (string)$this->maxAge);
            $resp->header('Access-Control-Allow-Credentials', $this->credentials ? 'true' : 'false');
            $resp->header('Vary',                             'Origin');
            return new Response('', 204);
        }

        $response = $handler->handle($request);

        $resp->header('Access-Control-Allow-Origin',      $allowedOrigin);
        $resp->header('Access-Control-Allow-Credentials', $this->credentials ? 'true' : 'false');
        $resp->header('Vary',                             'Origin');

        return $response;
    }

    private function resolveOrigin(string $requestOrigin): string
    {
        if (in_array('*', $this->origins, true)) {
            // credentials=true requires explicit origin, not wildcard
            return ($this->credentials && $requestOrigin !== '') ? $requestOrigin : '*';
        }
        return in_array($requestOrigin, $this->origins, true) ? $requestOrigin : $this->origins[0];
    }
}

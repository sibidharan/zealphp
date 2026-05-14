<?php
namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use OpenSwoole\Core\Psr\Response;
use ZealPHP\G;
use function ZealPHP\response_add_header;

/**
 * CORS Middleware
 *
 * Handles Cross-Origin Resource Sharing headers and OPTIONS preflight requests.
 *
 * Usage in app.php (add first so it runs outermost):
 *   $app->addMiddleware(new \ZealPHP\Middleware\CorsMiddleware());
 *
 *   // Custom origins / settings:
 *   $app->addMiddleware(new \ZealPHP\Middleware\CorsMiddleware(
 *       origins:     ['https://myapp.com'],
 *       methods:     ['GET', 'POST', 'PUT', 'DELETE'],
 *       headers:     ['Content-Type', 'Authorization'],
 *       credentials: true,
 *       maxAge:      3600,
 *   ));
 */
class CorsMiddleware implements MiddlewareInterface
{
    private array $origins;
    private array $methods;
    private array $headers;
    private bool  $credentials;
    private int   $maxAge;

    public function __construct(
        array  $origins     = ['*'],
        array  $methods     = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        array  $headers     = ['Content-Type', 'Authorization', 'X-Requested-With', 'Accept'],
        bool   $credentials = false,
        int    $maxAge      = 86400
    ) {
        $this->origins     = $origins;
        $this->methods     = $methods;
        $this->headers     = $headers;
        $this->credentials = $credentials;
        $this->maxAge      = $maxAge;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin        = $request->getHeaderLine('Origin');
        $allowedOrigin = $this->resolveOrigin($origin);
        $g = G::instance();
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

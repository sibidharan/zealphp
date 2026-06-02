<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;

/**
 * Health Check Middleware — short-circuits on configured paths and returns
 * `App::stats()` as JSON. Designed for load-balancer probes, Kubernetes
 * liveness/readiness, and monitoring agents.
 *
 * Basic usage (intercepts `/healthz`):
 *
 * ```php
 * $app->addMiddleware(new HealthCheckMiddleware());
 * ```
 *
 * Custom paths:
 *
 * ```php
 * $app->addMiddleware(new HealthCheckMiddleware(
 *     paths: ['/healthz', '/readyz', '/_health']
 * ));
 * ```
 *
 * With a custom readiness check (e.g., verify Redis/DB reachable):
 *
 * ```php
 * $app->addMiddleware(new HealthCheckMiddleware(
 *     check: function(): ?string {
 *         try { \ZealPHP\Store::get('_ping', '_ping'); return null; }
 *         catch (\Throwable $e) { return 'store unreachable'; }
 *     }
 * ));
 * ```
 *
 * Response shapes:
 * - `200` `{"status":"ok", "uptime_sec":123, ...}` — healthy
 * - `503` `{"status":"unhealthy", "reason":"store unreachable", ...}` — unhealthy
 */
final class HealthCheckMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $paths;

    /** @var (callable(): ?string)|null */
    private $check;

    /**
     * @param list<string>               $paths URL paths to intercept (default `['/healthz']`).
     * @param (callable(): ?string)|null $check Returns `null` if healthy, an error string if not.
     */
    public function __construct(array $paths = ['/healthz'], ?callable $check = null)
    {
        $this->paths = $paths;
        $this->check = $check;
    }

    /**
     * Intercept health-check paths and return a JSON status payload.
     *
     * Non-matching paths are forwarded to `$handler` unchanged. When the
     * optional `$check` callable returns a non-null string, the response
     * status is `503` and the string is included as `"reason"` in the body.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (!in_array($path, $this->paths, true)) {
            return $handler->handle($request);
        }

        $stats = App::stats();
        $reason = null;

        if ($this->check !== null) {
            $reason = ($this->check)();
        }

        $healthy = $reason === null;
        $body = array_merge(
            ['status' => $healthy ? 'ok' : 'unhealthy'],
            $reason !== null ? ['reason' => $reason] : [],
            $stats
        );

        $json = (string) json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $code = $healthy ? 200 : 503;

        return new Response($json, $code, '', ['Content-Type' => 'application/json']);
    }
}

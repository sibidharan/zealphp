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
 * Host-Router Middleware (nginx `server_name` virtual-host equivalent)
 *
 * Dispatches the request to a per-host handler based on the `Host:` request
 * header. The handler is a normal callable returning any of ZealPHP's
 * supported return shapes (string body, PSR-7 Response, Generator,
 * array → JSON, int → status code).
 *
 * nginx equivalent:
 *   server { server_name a.com; location / { ... } }
 *   server { server_name b.com; location / { ... } }
 *
 * If no host matches and no `'*'` (catch-all) handler is registered, the
 * middleware **passes through** to the next handler. This lets you mix
 * host-routed and host-agnostic apps inside one ZealPHP instance:
 *
 *   $app->addMiddleware(new \ZealPHP\Middleware\HostRouterMiddleware([
 *       'docs.example.com'  => fn() => 'docs landing page',
 *       'api.example.com'   => fn() => ['status' => 'ok'],
 *       '*'                 => fn() => 'default site',
 *   ]));
 *
 * Host matching is case-insensitive and ignores port (`example.com:8080`
 * matches the rule `example.com`).
 *
 * Wildcard subdomain matches use a leading `*.` — e.g. `*.example.com`
 * matches `foo.example.com` and `bar.example.com` but not `example.com`.
 */
class HostRouterMiddleware implements MiddlewareInterface
{
    /** @var array<string, callable> normalised host => handler */
    private array $handlers;
    /** @var callable|null */
    private $catchAll;
    /** @var array<int, array{host: string, handler: callable}> wildcard rules in declaration order */
    private array $wildcards;

    /**
     * @param array<string, callable> $hosts host => callable, plus optional '*' catch-all
     */
    public function __construct(array $hosts)
    {
        $this->handlers  = [];
        $this->wildcards = [];
        $this->catchAll  = null;

        foreach ($hosts as $host => $handler) {
            if (!is_callable($handler)) {
                throw new \InvalidArgumentException("Handler for '{$host}' must be callable");
            }
            $key = strtolower((string)$host);
            if ($key === '*') {
                $this->catchAll = $handler;
                continue;
            }
            if (str_starts_with($key, '*.')) {
                $this->wildcards[] = ['host' => substr($key, 2), 'handler' => $handler];
                continue;
            }
            $this->handlers[$key] = $handler;
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $host = strtolower($request->getHeaderLine('Host'));
        if ($host === '') {
            $g = RequestContext::instance();
            $host = strtolower((string)($g->server['HTTP_HOST'] ?? ''));
        }
        // strip port
        if (($pos = strpos($host, ':')) !== false) {
            $host = substr($host, 0, $pos);
        }

        $matched = $this->matchHandler($host);
        if ($matched === null) {
            return $handler->handle($request);
        }

        $result = $matched($request);
        return $this->coerceResponse($result);
    }

    private function matchHandler(string $host): ?callable
    {
        if ($host !== '' && isset($this->handlers[$host])) {
            return $this->handlers[$host];
        }
        foreach ($this->wildcards as $rule) {
            // *.example.com matches sub.example.com but not example.com itself
            if (str_ends_with($host, '.' . $rule['host'])) {
                return $rule['handler'];
            }
        }
        return $this->catchAll;
    }

    private function coerceResponse(mixed $result): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if (is_int($result)) {
            return new Response('', $result);
        }
        if (is_array($result) || is_object($result)) {
            return new Response(
                (string)json_encode($result),
                200,
                '',
                ['Content-Type' => 'application/json']
            );
        }
        return new Response((string)$result, 200, '', ['Content-Type' => 'text/html']);
    }
}

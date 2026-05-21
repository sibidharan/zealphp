<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Redirect Middleware — declarative URL redirects, Apache mod_alias parity.
 *
 * Two rule shapes, matched in order; the first match short-circuits with a
 * redirect response (the route handler never runs):
 *
 *   - Prefix (Apache `Redirect /old /new`): matches the path exactly or as a
 *     `/old/...` prefix; the remainder after the prefix is appended to the
 *     target — `/old/x` → `/new/x`.
 *   - Regex (Apache `RedirectMatch ^/old/(.*) /new/$1`): PCRE pattern with
 *     `$n` backreferences in the target.
 *
 * The request query string is preserved (appended to the target when the
 * target doesn't carry its own), matching mod_alias.
 *
 * Usage in app.php:
 *   $app->addMiddleware(new \ZealPHP\Middleware\RedirectMiddleware([
 *       ['from' => '/old', 'to' => '/new', 'status' => 301],
 *       ['match' => '#^/blog/(\d+)$#', 'to' => '/posts/$1', 'status' => 302],
 *   ]));
 */
class RedirectMiddleware implements MiddlewareInterface
{
    /** @var list<array{prefix: ?string, regex: ?string, to: string, status: int}> */
    private array $rules = [];

    /**
     * @param list<array<string, mixed>> $rules Each rule needs a `to` plus
     *        either `from` (prefix) or `match` (regex); optional `status`
     *        (default 302).
     */
    public function __construct(array $rules)
    {
        foreach ($rules as $r) {
            $to = (isset($r['to']) && is_string($r['to'])) ? $r['to'] : null;
            if ($to === null) {
                continue;
            }
            $prefix = (isset($r['from']) && is_string($r['from'])) ? $r['from'] : null;
            $regex  = (isset($r['match']) && is_string($r['match'])) ? $r['match'] : null;
            if ($prefix === null && $regex === null) {
                continue;
            }
            $status = (isset($r['status']) && is_int($r['status'])) ? $r['status'] : 302;
            $this->rules[] = ['prefix' => $prefix, 'regex' => $regex, 'to' => $to, 'status' => $status];
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        foreach ($this->rules as $rule) {
            $target = $this->resolve($rule, $path);
            if ($target === null) {
                continue;
            }
            $query = $request->getUri()->getQuery();
            if ($query !== '' && !str_contains($target, '?')) {
                $target .= '?' . $query;
            }
            return new Response('', $rule['status'], '', ['Location' => $target]);
        }
        return $handler->handle($request);
    }

    /**
     * @param array{prefix: ?string, regex: ?string, to: string, status: int} $rule
     */
    private function resolve(array $rule, string $path): ?string
    {
        if ($rule['regex'] !== null) {
            if (preg_match($rule['regex'], $path) !== 1) {
                return null;
            }
            $result = preg_replace($rule['regex'], $rule['to'], $path);
            return is_string($result) ? $result : null;
        }

        $prefix = $rule['prefix'];
        if ($prefix === null) {
            return null;
        }
        if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
            return $rule['to'] . substr($path, strlen($prefix));
        }
        return null;
    }
}

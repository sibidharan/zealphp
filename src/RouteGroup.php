<?php
declare(strict_types=1);

namespace ZealPHP;

use Psr\Http\Server\MiddlewareInterface;

/**
 * Route group — the object handed to an `App::group()` callback. It mirrors the
 * `App` route registrars (`route` / `nsRoute` / `nsPathRoute` / `patternRoute`)
 * and nested `group()`, transparently:
 *
 *   1. prepending the group's URL prefix to each route's path, and
 *   2. prepending the group's shared middleware chain (outermost) to each
 *      route's own middleware.
 *
 * ```php
 * $app->group('/admin', ['auth', 'admin-only'], function ($g) {
 *     $g->route('/users',    fn() => User::all());
 *     $g->route('/settings', fn() => Settings::get());
 *     $g->group('/audit', ['audit-log'], function ($g) {     // nests: /admin/audit/*
 *         $g->route('/recent', fn() => Audit::recent());     // auth → admin-only → audit-log → handler
 *     });
 * });
 * ```
 *
 * Ordering: group middleware wraps **outside** a route's own middleware, which
 * wraps outside the handler — same first-listed-is-outermost rule as the global
 * stack. Groups nest: an inner group composes its prefix and middleware onto the
 * outer group's.
 *
 * Stateless-middleware contract still applies: a middleware instance (or alias)
 * named here is shared across every concurrent request that route serves, so it
 * must keep per-request state in `$g` (`RequestContext`), never on itself.
 */
final class RouteGroup
{
    /**
     * @param list<MiddlewareInterface|string> $middleware Group-level chain, already normalized.
     */
    public function __construct(
        private App $app,
        private string $prefix,
        private array $middleware = []
    ) {
    }

    /**
     * @param array<string, mixed>|callable $options
     * @param callable|null $handler
     * @param list<string> $methods
     * @param array<int, MiddlewareInterface|string> $middleware
     * @param array<string,mixed>|string|null $backend Per-route CGI backend (bare mode / `App::cgiBackendAlias()` name / inline config); delegated to `App::route()`.
     */
    public function route(string $path, $options = [], $handler = null, array $methods = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        [$options, $handler] = $this->normalizeShorthand($options, $handler);
        $this->app->route(
            $this->prefix . $path,
            $this->stripMiddleware($options),
            $handler,
            $methods,
            $raw,
            $this->combine($options, $middleware),
            $backend
        );
    }

    /**
     * @param array<string, mixed>|callable $options
     * @param callable|null $handler
     * @param list<string> $methods
     * @param array<int, MiddlewareInterface|string> $middleware
     * @param array<string,mixed>|string|null $backend Per-route CGI backend (bare mode / `App::cgiBackendAlias()` name / inline config); delegated to `App::nsRoute()`.
     */
    public function nsRoute(string $namespace, string $path, $options = [], $handler = null, array $methods = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        [$options, $handler] = $this->normalizeShorthand($options, $handler);
        $this->app->nsRoute(
            $this->prefixNamespace($namespace),
            $path,
            $this->stripMiddleware($options),
            $handler,
            $methods,
            $raw,
            $this->combine($options, $middleware),
            $backend
        );
    }

    /**
     * @param array<string, mixed>|callable $options
     * @param callable|null $handler
     * @param list<string> $methods
     * @param array<int, MiddlewareInterface|string> $middleware
     * @param array<string,mixed>|string|null $backend Per-route CGI backend (bare mode / `App::cgiBackendAlias()` name / inline config); delegated to `App::nsPathRoute()`.
     */
    public function nsPathRoute(string $namespace, string $path, $options = [], $handler = null, array $methods = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        [$options, $handler] = $this->normalizeShorthand($options, $handler);
        $this->app->nsPathRoute(
            $this->prefixNamespace($namespace),
            $path,
            $this->stripMiddleware($options),
            $handler,
            $methods,
            $raw,
            $this->combine($options, $middleware),
            $backend
        );
    }

    /**
     * Pattern routes carry a raw, user-authored regex, so the group **prefix is
     * NOT auto-applied** (prefixing an arbitrary regex is ambiguous — bake the
     * prefix into your pattern). The group **middleware IS** still applied.
     *
     * @param array<string, mixed>|callable $options
     * @param callable|null $handler
     * @param list<string> $methods
     * @param array<int, MiddlewareInterface|string> $middleware
     * @param array<string,mixed>|string|null $backend Per-route CGI backend (bare mode / `App::cgiBackendAlias()` name / inline config); delegated to `App::patternRoute()`.
     */
    public function patternRoute(string $regex, $options = [], $handler = null, array $methods = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        [$options, $handler] = $this->normalizeShorthand($options, $handler);
        $this->app->patternRoute(
            $regex,
            $this->stripMiddleware($options),
            $handler,
            $methods,
            $raw,
            $this->combine($options, $middleware),
            $backend
        );
    }

    /**
     * Nested group — composes this group's prefix + middleware onto a child.
     *
     * @param array<int, MiddlewareInterface|string>|callable $middleware
     */
    public function group(string $prefix, array|callable $middleware = [], ?callable $registrar = null): void
    {
        if (is_callable($middleware) && $registrar === null) {
            $registrar = $middleware;
            $middleware = [];
        }
        if ($registrar === null) {
            throw new \InvalidArgumentException('RouteGroup::group() requires a registrar callback.');
        }
        $child = new self(
            $this->app,
            $this->prefix . $prefix,
            array_merge($this->middleware, App::normalizeMiddlewareSpec($middleware))
        );
        $registrar($child);
    }

    /**
     * Apply the "second arg is the handler" shorthand (`$g->route('/x', $fn)`).
     * Options are rebuilt with string keys — an options array is always
     * string-keyed (`methods`/`raw`/`middleware`); the rebuild also discards the
     * `int|string` key ambiguity PHPStan infers from the callable-array union.
     *
     * @param array<string, mixed>|callable $options
     * @param callable|null $handler
     * @return array{0: array<string, mixed>, 1: callable|null}
     */
    private function normalizeShorthand($options, $handler): array
    {
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
        $opts = [];
        if (is_array($options)) {
            foreach ($options as $key => $value) {
                $opts[(string)$key] = $value;
            }
        }
        return [$opts, $handler];
    }

    /**
     * Group prefix + middleware ++ the route's own (options then named arg).
     * Group middleware ends up outermost.
     *
     * @param array<string, mixed> $options
     * @param array<int, MiddlewareInterface|string> $middleware
     * @return list<MiddlewareInterface|string>
     */
    private function combine(array $options, array $middleware): array
    {
        $own = App::normalizeMiddlewareSpec($options['middleware'] ?? []);
        $named = App::normalizeMiddlewareSpec($middleware);
        return array_merge($this->middleware, $own, $named);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function stripMiddleware(array $options): array
    {
        unset($options['middleware']);
        return $options;
    }

    private function prefixNamespace(string $namespace): string
    {
        $base = trim($this->prefix, '/');
        $ns = trim($namespace, '/');
        if ($base === '') {
            return $ns;
        }
        return $ns === '' ? $base : $base . '/' . $ns;
    }
}

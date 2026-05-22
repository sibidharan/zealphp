<?php
declare(strict_types=1);

namespace ZealPHP\Input;

use ZealPHP\RequestContext;
use Throwable;

/**
 * Backs the mod_php-parity `filter_input()` / `filter_input_array()` overrides.
 *
 * Under the CLI SAPI, PHP's native `filter_input()` reads the internal SAPI request
 * tables, which OpenSwoole never populates — so legacy code using `INPUT_GET` /
 * `INPUT_POST` / `INPUT_COOKIE` / `INPUT_SERVER` silently gets `null`. ZealPHP routes
 * request input through `RequestContext` (`$g`) instead; this class resolves the
 * right bag and applies the same filters PHP's filter extension would.
 *
 * The filtering methods are pure (bag in, result out) so they unit-test without
 * a server. `bagFor()` is the only context-dependent method and is fully guarded —
 * a diagnostics/compat shim must never fatal.
 */
final class RequestInput
{
    /**
     * Resolve a PHP `INPUT_*` type to the matching request bag from `RequestContext`.
     *
     * @return array<string, mixed>
     */
    public static function bagFor(int $type): array
    {
        try {
            $g = RequestContext::instance();
            $bag = match ($type) {
                INPUT_GET    => $g->get,
                INPUT_POST   => $g->post,
                INPUT_COOKIE => $g->cookie,
                INPUT_SERVER => $g->server,
                INPUT_ENV    => $_ENV,
                default      => [],
            };
        } catch (Throwable) {
            // No request/coroutine context (CLI, unit test) — empty bag matches
            // native filter_input()'s "input type unavailable" behavior.
            return [];
        }
        return self::stringKeyed($bag);
    }

    /**
     * Filter a single value from a bag, mirroring `filter_input()` semantics:
     * missing key → `null`; otherwise `filter_var()` (`false` on failed validation).
     *
     * @param array<string, mixed> $bag
     * @param array<string, mixed>|int $options
     */
    public static function filterValue(array $bag, string $name, int $filter, array|int $options): mixed
    {
        if (!array_key_exists($name, $bag)) {
            return null;
        }
        return filter_var($bag[$name], $filter, $options);
    }

    /**
     * Filter a whole bag, mirroring `filter_input_array()` — delegates to the
     * native `filter_var_array()` so per-key definitions and `add_empty` behave
     * identically to PHP's filter extension.
     *
     * @param array<string, mixed> $bag
     * @param array<string, mixed>|int $definition
     * @return array<string, mixed>
     */
    public static function filterArray(array $bag, array|int $definition, bool $addEmpty): array
    {
        return filter_var_array($bag, $definition, $addEmpty);
    }

    /**
     * Normalize a bag to string-keyed entries (`filter_input` addresses by name).
     *
     * @param array<array-key, mixed> $bag
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $bag): array
    {
        $out = [];
        foreach ($bag as $k => $v) {
            $out[(string) $k] = $v;
        }
        return $out;
    }
}

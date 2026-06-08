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
 * stamping security headers (`X-Frame-Options`, `CSP`, `Strict-Transport-Security`,
 * `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`) onto every
 * response without sprinkling `$response->header(...)` calls through handlers.
 *
 * Apache equivalent (`mod_headers`):
 *
 * ```
 * Header set X-Frame-Options "DENY"
 * Header append Vary "Accept-Encoding"
 * Header unset Server
 * Header add Set-Cookie "..."
 * ```
 *
 * nginx equivalent:
 *
 * ```
 * add_header X-Frame-Options "DENY" always;
 * more_clear_headers Server;   # ngx_headers_more module
 * ```
 *
 * ## Status-conditional application (nginx parity)
 *
 * nginx `add_header` applies headers **only on safe-status responses**
 * (`200`, `201`, `204`, `206`, `301`, `302`, `303`, `304`, `307`, `308`) unless the `always`
 * keyword is present. ZealPHP mirrors this behaviour through two knobs:
 *
 * 1. **Constructor `$alwaysByDefault`** (default `true`): when `true` every
 *    rule behaves like nginx `add_header ... always` — headers are applied to
 *    ALL responses regardless of status. Set to `false` to switch the
 *    middleware into nginx-default mode where `set`/`add`/`append` rules are
 *    skipped on non-safe-status responses unless the per-rule `always` flag
 *    overrides them.
 *
 *    **BC note:** the default is `true` (apply to all statuses) to preserve
 *    the behaviour of existing ZealPHP apps. nginx migrants who want exact
 *    `add_header`-without-`always` parity should pass `false`.
 *
 * 2. **Per-rule `always` flag** inside `set`/`add`/`append` entries: wrap a
 *    value in `['value' => '...', 'always' => true]` to force a specific rule
 *    to apply on all statuses even when `$alwaysByDefault = false`. `unset`
 *    rules are always unconditional — they run on every response status.
 *
 * Safe statuses (matching nginx `ngx_http_headers_filter_module.c:217-233`):
 * `200`, `201`, `204`, `206`, `301`, `302`, `303`, `304`, `307`, `308`.
 *
 * Constructor accepts a config array with four operations:
 *   - `set`:    overwrite the header value (replaces existing)
 *   - `add`:    append a value (like Apache `Header add` — emits multiple lines)
 *   - `append`: append to existing value comma-separated (like `Header append Vary "X"`)
 *   - `unset`:  list of headers to strip from the response (always unconditional)
 *
 * Each rule value may be a plain string (or array for `add`) or an associative
 * array with `'value'` and optional `'always'` keys:
 *   `'set' => ['X-Frame-Options' => ['value' => 'DENY', 'always' => true]]`
 *
 * Usage in `app.php` — current default (all-status, BC-safe):
 *
 * ```php
 * $app->addMiddleware(new \ZealPHP\Middleware\HeaderMiddleware([
 *     'set' => [
 *         'X-Frame-Options'            => 'DENY',
 *         'X-Content-Type-Options'     => 'nosniff',
 *         'Referrer-Policy'            => 'strict-origin-when-cross-origin',
 *         'Strict-Transport-Security'  => 'max-age=31536000; includeSubDomains',
 *         'Content-Security-Policy'    => "default-src 'self'",
 *     ],
 *     'append' => ['Vary' => 'Accept-Encoding'],
 *     'unset'  => ['Server', 'X-Powered-By'],
 * ]));
 * ```
 *
 * Usage with nginx semantics (skip on error responses unless `always=true`):
 *
 * ```php
 * $app->addMiddleware(new \ZealPHP\Middleware\HeaderMiddleware([
 *     'set' => [
 *         // Skipped on 4xx/5xx (nginx default behaviour).
 *         'Cache-Control' => 'no-cache',
 *         // Always applied even on error responses.
 *         'X-Frame-Options' => ['value' => 'DENY', 'always' => true],
 *     ],
 *     'unset' => ['Server'],   // unset is always unconditional
 * ], alwaysByDefault: false));
 * ```
 */
class HeaderMiddleware implements MiddlewareInterface
{
    /**
     * nginx safe-status set: responses on which `add_header` fires without `always`.
     * Source: `ngx_http_headers_filter_module.c:221-232`.
     *
     * @var int[]
     */
    private const SAFE_STATUSES = [200, 201, 204, 206, 301, 302, 303, 304, 307, 308];

    /**
     * Normalised `set` rules: name => ['value' => string, 'always' => bool].
     *
     * @var array<string, array{value: string, always: bool}>
     */
    private array $set;

    /**
     * Normalised `add` rules: name => list<array{value: string, always: bool}>.
     *
     * @var array<string, list<array{value: string, always: bool}>>
     */
    private array $add;

    /**
     * Normalised `append` rules: name => ['value' => string, 'always' => bool].
     *
     * @var array<string, array{value: string, always: bool}>
     */
    private array $append;

    /** @var string[] */
    private array $unset;

    /**
     * @param array{
     *     set?: array<string, string|array{value: string, always?: bool}>,
     *     add?: array<string, string|string[]|array{value: string|string[], always?: bool}>,
     *     append?: array<string, string|array{value: string, always?: bool}>,
     *     unset?: string[],
     * } $config
     * @param bool $alwaysByDefault When true (the default), every rule applies
     *   to ALL responses regardless of HTTP status — this is ZealPHP's historical
     *   behaviour (equivalent to nginx `always` on every rule). Set to false for
     *   nginx-default mode: `set`/`add`/`append` rules are skipped on non-safe-status
     *   responses unless the per-rule `always` flag is explicitly set. `unset` rules
     *   are always unconditional in both modes.
     */
    public function __construct(array $config = [], bool $alwaysByDefault = true)
    {
        $this->set    = $this->normaliseScalarRules($config['set'] ?? [], $alwaysByDefault, 'set');
        $this->add    = $this->normaliseAddRules($config['add'] ?? [], $alwaysByDefault);
        $this->append = $this->normaliseScalarRules($config['append'] ?? [], $alwaysByDefault, 'append');
        $this->unset  = $config['unset'] ?? [];
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $g = RequestContext::instance();
        $resp = $g->zealphp_response;

        $status = $response->getStatusCode();
        $safe   = in_array($status, self::SAFE_STATUSES, true);

        foreach ($this->set as $name => $rule) {
            if (!$safe && !$rule['always']) {
                continue;
            }
            $value    = $rule['value'];
            $response = $response->withHeader($name, $value);
            if ($resp !== null) {
                $resp->header($name, $value);
            }
        }

        foreach ($this->add as $name => $entries) {
            foreach ($entries as $entry) {
                if (!$safe && !$entry['always']) {
                    continue;
                }
                $v        = $entry['value'];
                $response = $response->withAddedHeader($name, $v);
                if ($resp !== null) {
                    // OpenSwoole's header() replaces by default — explicitly
                    // disable replace so multiple Set-Cookie / Link entries
                    // accumulate (mod_headers `Header add` semantics).
                    // The ZealPHP\HTTP\Response wrapper only exposes a 2-arg
                    // header(), so bypass it via ->parent for the 3-arg form.
                    $resp->parent->header($name, $v, false);
                }
            }
        }

        foreach ($this->append as $name => $rule) {
            if (!$safe && !$rule['always']) {
                continue;
            }
            $value    = $rule['value'];
            $existing = $response->getHeaderLine($name);
            $merged   = $existing === '' ? $value : $existing . ', ' . $value;
            $response = $response->withHeader($name, $merged);
            if ($resp !== null) {
                $resp->header($name, $merged);
            }
        }

        // `unset` is unconditional — applies on every response status.
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

    // -------------------------------------------------------------------------
    // Normalisation helpers
    // -------------------------------------------------------------------------

    /**
     * Normalise `set` and `append` config entries into a uniform shape.
     *
     * Input: array<string, string|array{value: string, always?: bool}>
     * Output: array<string, array{value: string, always: bool}>
     *
     * @param array<string, string|array{value?: string, always?: bool}> $rules
     * @return array<string, array{value: string, always: bool}>
     */
    private function normaliseScalarRules(array $rules, bool $alwaysByDefault, string $op = 'set'): array
    {
        $out = [];
        foreach ($rules as $name => $spec) {
            if (is_array($spec)) {
                if (!array_key_exists('value', $spec)) {
                    // Structured array form must carry a 'value'. Fail fast at
                    // construction with an actionable message instead of crashing
                    // later inside PSR-7 withHeader() on a null value (#310).
                    throw new \InvalidArgumentException(sprintf(
                        "HeaderMiddleware: '%s' rule for header '%s' is missing the required 'value' key "
                        . "(use ['value' => '...', 'always' => bool] or a plain string).",
                        $op,
                        $name
                    ));
                }
                $out[$name] = [
                    'value'  => $spec['value'],
                    'always' => $spec['always'] ?? $alwaysByDefault,
                ];
            } else {
                $out[$name] = [
                    'value'  => $spec,
                    'always' => $alwaysByDefault,
                ];
            }
        }
        return $out;
    }

    /**
     * Normalise `add` config entries into a uniform shape.
     *
     * Input allows: string, string[], or array{value: string|string[], always?: bool}
     * Output: array<string, list<array{value: string, always: bool}>>
     *
     * @param array<string, string|string[]|array{value?: string|string[], always?: bool}> $rules
     * @return array<string, list<array{value: string, always: bool}>>
     */
    private function normaliseAddRules(array $rules, bool $alwaysByDefault): array
    {
        $out = [];
        foreach ($rules as $name => $spec) {
            $always = $alwaysByDefault;
            $values = [];

            if (is_array($spec) && array_key_exists('value', $spec)) {
                // Structured form: ['value' => '...' | [...], 'always' => bool]
                /** @var array{value: string|string[], always?: bool} $spec */
                $always = $spec['always'] ?? $alwaysByDefault;
                $raw    = $spec['value'];
                $values = is_array($raw) ? $raw : [$raw];
            } elseif (is_array($spec) && array_key_exists('always', $spec)) {
                // Structured form missing the required 'value' — fail fast with an
                // actionable message rather than emitting a bogus header (#310).
                throw new \InvalidArgumentException(sprintf(
                    "HeaderMiddleware: 'add' rule for header '%s' is missing the required 'value' key "
                    . "(use ['value' => '...', 'always' => bool], a plain string, or a list of strings).",
                    $name
                ));
            } elseif (is_array($spec)) {
                // Plain array of strings: ['val1', 'val2']
                /** @var string[] $spec */
                $values = $spec;
            } else {
                // Plain string
                $values = [$spec];
            }

            $entries = [];
            foreach ($values as $v) {
                $entries[] = ['value' => $v, 'always' => $always];
            }
            $out[$name] = $entries;
        }
        return $out;
    }
}

<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Snapshot / restore selected php.ini values around each request.
 *
 * Long-running PHP workers don't reset `ini_set()` changes between requests.
 * `ini_set('date.timezone', 'Asia/Tokyo')` in request N stays in effect for
 * request N+1 on the same worker — a footgun that only surfaces under load
 * when requests with different settings interleave.
 *
 * This middleware snapshots a curated list of commonly-mutated INI keys at
 * request start and restores any changes when the request completes. Defaults
 * cover the values most apps mutate per-request; pass a custom list to the
 * constructor to extend or replace.
 *
 * **Opt-in.** Not registered by default — register it explicitly:
 *
 * ```
 * use ZealPHP\Middleware\IniIsolationMiddleware;
 * $app->addMiddleware(new IniIsolationMiddleware());
 * ```
 *
 * Or enable via env var:
 *
 * ```
 * ZEALPHP_INI_ISOLATE=1 php app.php
 * ```
 *
 * (when `ZEALPHP_INI_ISOLATE=1`, `App::run()` registers this middleware
 * automatically; see App.php for the registration check.)
 */
class IniIsolationMiddleware implements MiddlewareInterface
{
    /**
     * INI keys that are common per-request mutation targets. These are what
     * we snapshot+restore by default. Anything outside this list is left
     * alone — partly to keep the per-request overhead bounded, partly
     * because keys outside this list are usually intentional process-wide
     * settings (max_execution_time has no meaning under coroutines anyway,
     * and changing extension-loaded settings per-request is incoherent).
     */
    public const DEFAULT_KEYS = [
        'date.timezone',
        'date.default_latitude',
        'date.default_longitude',
        'default_charset',
        'default_mimetype',
        'display_errors',
        'display_startup_errors',
        'error_reporting',
        'html_errors',
        'log_errors',
        'memory_limit',
        'precision',
        'serialize_precision',
    ];

    /** @var string[] */
    private array $keys;

    /**
     * @param string[]|null $keys INI keys to snapshot+restore. Defaults to
     *                            self::DEFAULT_KEYS. Pass an extended list
     *                            to add app-specific keys; pass an empty
     *                            array to disable (effectively a no-op).
     */
    public function __construct(?array $keys = null)
    {
        $this->keys = $keys ?? self::DEFAULT_KEYS;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->keys === []) {
            return $handler->handle($request);
        }

        $snapshot = [];
        foreach ($this->keys as $key) {
            $value = ini_get($key);
            // ini_get returns false for unknown keys; skip those so we don't
            // try to ini_set('') on restore (which is a no-op but noisy).
            if ($value !== false) {
                $snapshot[$key] = $value;
            }
        }

        try {
            return $handler->handle($request);
        } finally {
            foreach ($snapshot as $key => $original) {
                $current = ini_get($key);
                if ($current !== $original) {
                    @ini_set($key, $original);
                }
            }
        }
    }
}

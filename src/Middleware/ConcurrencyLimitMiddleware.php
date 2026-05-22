<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\Counter;
use ZealPHP\RequestContext;
use ZealPHP\Store;

/**
 * Concurrency-Limit Middleware
 *
 * Bounds the number of in-flight requests. Supports two modes:
 *
 * **Global mode** (backward-compatible, original behaviour)
 * A single shared `Counter` (`OpenSwoole\Atomic`) caps total in-flight
 * requests across all clients. Simple, zero-allocation, but one slow
 * client can exhaust the global cap and `503` everyone else.
 *
 * **Per-key mode** (nginx `limit_conn` parity)
 * A `Store` table (`OpenSwoole\Table`) tracks in-flight counts per key.
 * The default key is the client IP (proxy-aware via `App::clientIp()`),
 * matching nginx `$binary_remote_addr`. A custom key resolver can be
 * supplied for other partitioning schemes (e.g. API key, tenant ID).
 *
 * nginx equivalent:
 *   `limit_conn_zone $binary_remote_addr zone=addr:10m;`
 *   `limit_conn addr 100;`
 *   `limit_conn_status 503;`
 *   `limit_conn_dry_run off;`
 *
 * The counter/store entry is incremented on entry and decremented on exit
 * via `try/finally`, so handlers that throw still decrement correctly.
 *
 * **Pre-fork footgun** — both the `Counter` and the `Store` table MUST be
 * instantiated/created BEFORE `$app->run()` so all forked workers share
 * the same shared-memory segment. Creating them after `run()` gives each
 * worker its own isolated counter — the limit will be `maxConcurrent ×
 * numWorkers` in practice, with no cross-worker enforcement.
 *
 * Usage — global mode (existing API, no change):
 *
 * ```php
 * $inflight = new \ZealPHP\Counter();
 * $app->addMiddleware(new \ZealPHP\Middleware\ConcurrencyLimitMiddleware(
 *     maxConcurrent: 100,
 *     counter:       $inflight,
 * ));
 * ```
 *
 * Usage — per-key mode (nginx limit_conn parity):
 *
 * ```php
 * Store::make('conn_limit', 4096, [
 *     'count' => [\OpenSwoole\Table::TYPE_INT, 4],
 * ]);
 *
 * $app->addMiddleware(new \ZealPHP\Middleware\ConcurrencyLimitMiddleware(
 *     maxConcurrent: 20,
 *     counter:       null,          // no global counter
 *     tableName:     'conn_limit',  // per-key Store table
 * ));
 * ```
 *
 * Usage — per-key + global cap together:
 *
 * ```php
 * $global = new \ZealPHP\Counter();
 * $app->addMiddleware(new \ZealPHP\Middleware\ConcurrencyLimitMiddleware(
 *     maxConcurrent: 20,     // per-key cap
 *     counter:       $global, // also enforce global cap (separate Counter)
 *     tableName:     'conn_limit',
 *     globalMax:     500,    // global ceiling across all clients
 * ));
 * ```
 *
 * Options:
 *   `rejectStatus`  int           HTTP status on rejection (`400`–`599`, default `503`)
 *   `dryRun`        bool          Observe + log, never enforce (default `false`)
 *   `keyResolver`   callable|null `fn(ServerRequestInterface): string` — override key;
 *                               default uses `App::clientIp()`
 *   `globalMax`     int           When > 0 and a `$counter` is provided, also enforce
 *                               this global ceiling alongside the per-key limit
 */
class ConcurrencyLimitMiddleware implements MiddlewareInterface
{
    private static bool $warnedMissingTable = false;

    /** @var callable(ServerRequestInterface): string */
    private $keyResolver;

    /**
     * @param int                                    $maxConcurrent Per-key (or global) in-flight cap.
     * @param Counter|null                           $counter       Optional global `Counter` (global mode
     *                                                              when `$tableName` is null; optional
     *                                                              additional global cap in per-key mode).
     * @param string|null                            $tableName     `Store` table for per-key limiting.
     *                                                              Must be created before `$app->run()`.
     * @param int                                    $globalMax     Global cap enforced via `$counter` when
     *                                                              > 0 and `$tableName` is set. Ignored
     *                                                              when `$tableName` is null (legacy mode
     *                                                              uses `$maxConcurrent` as the global cap).
     * @param int                                    $rejectStatus  HTTP status on rejection (`400`–`599`).
     * @param bool                                   $dryRun        Log rejections without enforcing.
     * @param callable(ServerRequestInterface):string|null $keyResolver Override key; default = `clientIp()`.
     */
    public function __construct(
        private int      $maxConcurrent,
        private ?Counter $counter    = null,
        private ?string  $tableName  = null,
        private int      $globalMax  = 0,
        private int      $rejectStatus = 503,
        private bool     $dryRun     = false,
        ?callable        $keyResolver = null,
    ) {
        if ($maxConcurrent <= 0) {
            throw new \InvalidArgumentException('maxConcurrent must be > 0');
        }
        if ($rejectStatus < 400 || $rejectStatus > 599) {
            throw new \InvalidArgumentException('rejectStatus must be in 400–599');
        }
        if ($tableName === null && $counter === null) {
            throw new \InvalidArgumentException(
                'Provide a Counter (global mode) or a tableName (per-key mode), or both.'
            );
        }

        $this->keyResolver = $keyResolver ?? static function (ServerRequestInterface $request): string {
            // App::clientIp() is proxy-aware (walks X-Forwarded-For when the
            // direct peer is in App::$trusted_proxies). Fall back to the PSR-7
            // server params when $g->server is not populated (unit tests).
            $ip = App::clientIp();
            if ($ip !== '') {
                return $ip;
            }
            $params = $request->getServerParams();
            $remote = $params['REMOTE_ADDR'] ?? '';
            return is_scalar($remote) ? (string)$remote : '';
        };
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Per-key mode: Store-backed per-client in-flight count.
        if ($this->tableName !== null) {
            return $this->processPerKey($request, $handler);
        }

        // Global mode: original Counter-based behaviour (backward-compat).
        return $this->processGlobal($request, $handler);
    }

    // -----------------------------------------------------------------------
    // Per-key mode
    // -----------------------------------------------------------------------

    private function processPerKey(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // $tableName is non-null here: process() only dispatches to this method
        // when $this->tableName !== null. Narrow the type for Store calls below.
        $tableName = $this->tableName;
        if ($tableName === null) {
            // Should be unreachable; guard satisfies PHPStan level 10.
            return $handler->handle($request);
        }

        // Fail-open if the Store table was not created before $app->run().
        if (Store::table($tableName) === null) {
            if (!self::$warnedMissingTable && function_exists('ZealPHP\\elog')) {
                self::$warnedMissingTable = true;
                \ZealPHP\elog(
                    "ConcurrencyLimitMiddleware: Store table '{$tableName}' does not exist; "
                    . 'create it before $app->run() (pre-fork footgun) — failing open.',
                    'conn_limit'
                );
            }
            return $handler->handle($request);
        }

        $key = ($this->keyResolver)($request);
        if ($key === '') {
            // Unknown key — let through; don't penalise anonymous/internal callers.
            return $handler->handle($request);
        }

        // Atomically increment the per-key in-flight count.
        $perKeyCount = Store::incr($tableName, $key, 'count', 1);

        // Also enforce optional global cap via Counter.
        $globalCount = 0;
        if ($this->counter !== null && $this->globalMax > 0) {
            $globalCount = $this->counter->increment(1);
        }

        $perKeyOver  = $perKeyCount > $this->maxConcurrent;
        $globalOver  = $this->counter !== null && $this->globalMax > 0 && $globalCount > $this->globalMax;

        if ($perKeyOver || $globalOver) {
            // Roll back both increments before rejecting.
            Store::decr($tableName, $key, 'count', 1);
            if ($this->counter !== null && $this->globalMax > 0) {
                $this->counter->decrement(1);
            }

            $reason = $perKeyOver
                ? "per-key limit {$this->maxConcurrent} for key={$key}"
                : "global limit {$this->globalMax}";

            if (function_exists('ZealPHP\\elog')) {
                \ZealPHP\elog(
                    "ConcurrencyLimitMiddleware: rejected ({$reason})"
                    . ($this->dryRun ? ' [dry-run]' : ''),
                    'conn_limit'
                );
            }

            if ($this->dryRun) {
                return $handler->handle($request);
            }

            return $this->reject();
        }

        // Request is within limits — run the handler, decrement on all paths.
        try {
            return $handler->handle($request);
        } finally {
            Store::decr($tableName, $key, 'count', 1);
            if ($this->counter !== null && $this->globalMax > 0) {
                $this->counter->decrement(1);
            }
        }
    }

    // -----------------------------------------------------------------------
    // Global mode (original Counter-based behaviour, backward-compat)
    // -----------------------------------------------------------------------

    private function processGlobal(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        /** @var Counter $counter — guaranteed non-null: constructor enforces counter|tableName */
        $counter  = $this->counter;
        $newValue = $counter->increment(1);

        if ($newValue > $this->maxConcurrent) {
            // Roll back the increment so we don't permanently inflate the
            // counter when overload sheds requests.
            $counter->decrement(1);

            if (function_exists('ZealPHP\\elog')) {
                \ZealPHP\elog(
                    "ConcurrencyLimitMiddleware: rejected (global limit {$this->maxConcurrent})"
                    . ($this->dryRun ? ' [dry-run]' : ''),
                    'conn_limit'
                );
            }

            if ($this->dryRun) {
                return $handler->handle($request);
            }

            return $this->reject();
        }

        try {
            return $handler->handle($request);
        } finally {
            $counter->decrement(1);
        }
    }

    // -----------------------------------------------------------------------
    // Shared helpers
    // -----------------------------------------------------------------------

    private function reject(): ResponseInterface
    {
        $statusText = $this->rejectStatus === 503 ? 'Service Unavailable' : 'Too Many Requests';
        $g = RequestContext::instance();
        $g->status = $this->rejectStatus;
        $headers = [
            'Content-Type' => 'text/plain',
            'Retry-After'  => '1',
        ];
        if ($g->zealphp_response !== null) {
            foreach ($headers as $name => $value) {
                $g->zealphp_response->header($name, $value);
            }
        }
        return new Response($statusText, $this->rejectStatus, '', $headers);
    }
}

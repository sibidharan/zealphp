<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\App;
use ZealPHP\RequestContext;
use ZealPHP\Store;

/**
 * Rate-Limit Middleware (sliding window, per-IP, shared across workers)
 *
 * Tracks request counts per client IP in a shared `Store` table. When a
 * single IP exceeds `$limit` requests inside `$window` seconds, further
 * requests receive a configurable HTTP error (default `429 Too Many Requests`)
 * with a `Retry-After` header.
 *
 * ## nginx parity
 *
 *   `limit_req_zone $binary_remote_addr zone=one:10m rate=60r/m;`
 *   `limit_req zone=one burst=5 nodelay;`
 *
 * ZealPHP equivalent:
 *
 *   `$app->addMiddleware(new RateLimitMiddleware(`
 *       `limit:        60,`
 *       `window:       60,`
 *       `burst:        5,`
 *       `nodelay:      true,`
 *       `tableName:    'rate_limit',`
 *   `));`
 *
 * ## Algorithm note — fixed window vs leaky bucket
 *
 * nginx's `limit_req` uses a leaky-bucket (token-drain) algorithm with
 * millisecond precision. ZealPHP uses a **fixed window** that resets every
 * `$window` seconds. The practical difference is the "thundering-herd at
 * boundary" problem: a fixed window allows up to `$limit` requests at the
 * tail of one window **and** another `$limit` at the head of the next window —
 * a worst-case 2× burst. A leaky bucket smooths this out. For spam/abuse
 * defence the fixed window is sufficient; for billing-grade fairness or tight
 * concurrency control, consider implementing a leaky-bucket variant.
 *
 * ## `burst=` / `nodelay=` / `delay=`
 *
 * - `burst=N` — allow up to N extra requests above the `limit` per window
 *   before rejecting. Requests within the burst quota are forwarded.
 * - `nodelay=true` — burst requests are forwarded immediately (no artificial
 *   delay). This matches nginx `limit_req ... nodelay`.
 * - Without burst (default `burst=0`), any request over the limit is rejected.
 *
 * ## Trusted-proxy / `X-Forwarded-For` integration (B2 fix)
 *
 * The zone key is now resolved via `App::clientIp()` which honours
 * `App::$trusted_proxies`. Operators deploying behind Traefik/nginx must
 * configure `App::trustedProxies()` so the rate-limit key is the real client
 * IP rather than the proxy's IP.
 *
 * ## Store-full failure policy (B10 fix)
 *
 * When the OpenSwoole `Table` is full, `Store::set()` returns `false`. The
 * middleware detects this, logs a warning via `elog()`, and **fails open**
 * (passes the request through). This is an explicit policy choice: rejecting
 * an unknown IP because the table is full would be overly aggressive. Operators
 * should size their table generously and monitor the log for `Store table full`
 * warnings.
 *
 * ## Dry-run mode
 *
 * `dryRun=true` runs all accounting and logs what would have been blocked, but
 * forwards every request regardless. Use this to calibrate rate-limit settings
 * on production traffic without impacting availability.
 *
 * ## Configurable reject status
 *
 * `rejectStatus` defaults to `429`. Pass `503` for nginx parity (nginx historically
 * uses `503` for rate-limit rejections). Any 4xx/5xx code is accepted.
 *
 * ## Loopback bypass
 *
 * By default, requests from `127.0.0.1` / `::1` are not rate-limited so the
 * integration test suite can run repeatedly without `php app.php restart`. Set
 * `ZEALPHP_RATE_LIMIT_LOOPBACK=1` to opt in (useful when testing the limiter).
 *
 * ## `Store` table schema (create before `$app->run()`)
 *
 *   `Store::make('rate_limit', 16384, [`
 *       `'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],`
 *       `'count' => [\OpenSwoole\Table::TYPE_INT,    4],`
 *       `'reset' => [\OpenSwoole\Table::TYPE_INT,    4],`
 *   `]);`
 *
 *   `$app->addMiddleware(new \ZealPHP\Middleware\RateLimitMiddleware(`
 *       `limit:     60,`
 *       `window:    60,`
 *       `tableName: 'rate_limit',`
 *   `));`
 *
 * If the table doesn't exist when the request arrives the middleware
 * fails-open (passes the request through) and logs once via `elog()`.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private static bool $warnedMissingTable = false;

    public function __construct(
        private int    $limit        = 60,
        private int    $window       = 60,
        private string $tableName    = 'rate_limit',
        private int    $burst        = 0,
        private bool   $nodelay      = false,
        private int    $rejectStatus = 429,
        private bool   $dryRun       = false,
    ) {
        if ($limit < 0) {
            throw new \InvalidArgumentException('limit must be >= 0');
        }
        if ($window <= 0) {
            throw new \InvalidArgumentException('window must be > 0');
        }
        if ($burst < 0) {
            throw new \InvalidArgumentException('burst must be >= 0');
        }
        if ($rejectStatus < 400 || $rejectStatus > 599) {
            throw new \InvalidArgumentException('rejectStatus must be in range 400–599');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->limit === 0) {
            // Disabled — explicit 0 means "no limit".
            return $handler->handle($request);
        }

        // Fail-open if the Store table wasn't created at boot.
        if (Store::table($this->tableName) === null) {
            if (!self::$warnedMissingTable && function_exists('ZealPHP\\elog')) {
                self::$warnedMissingTable = true;
                \ZealPHP\elog(
                    "RateLimitMiddleware: Store table '{$this->tableName}' does not exist; "
                    . 'create it before $app->run() — failing open in the meantime.',
                    'rate_limit'
                );
            }
            return $handler->handle($request);
        }

        // B2 fix: use App::clientIp() which honours X-Forwarded-For + trusted proxies.
        $ip = App::clientIp();
        if ($ip === '') {
            // Fallback: read from PSR-7 server params if $g has no REMOTE_ADDR
            // (can happen in unit tests that don't populate $g->server).
            $params = $request->getServerParams();
            $remote = $params['REMOTE_ADDR'] ?? '';
            $ip = is_scalar($remote) ? (string)$remote : '';
        }

        if ($ip === '') {
            return $handler->handle($request);
        }
        if ($this->isLoopback($ip) && getenv('ZEALPHP_RATE_LIMIT_LOOPBACK') !== '1') {
            return $handler->handle($request);
        }

        $now = time();
        $effectiveLimit = $this->limit + $this->burst;

        $existing = Store::get($this->tableName, $ip);
        if (is_array($existing)) {
            $reset = is_numeric($existing['reset'] ?? null) ? (int)$existing['reset'] : 0;
            $count = is_numeric($existing['count'] ?? null) ? (int)$existing['count'] : 0;
            if ($now < $reset) {
                // Atomic increment-THEN-check closes the check-then-act race
                // (#408): the previous code read $count, compared, then incr'd —
                // so K concurrent coroutines could all read the same sub-limit
                // value and pass before any incremented (12 admitted at limit 10
                // under a 40-way burst). OpenSwoole\Table::incr is atomic and
                // returns the post-increment value; comparing on THAT means
                // exactly the (limit+1)th concurrent arrival is the first to
                // exceed — no over-admission.
                $newCount = Store::incr($this->tableName, $ip, 'count', 1);
                if ($newCount > $effectiveLimit) {
                    if ($this->dryRun) {
                        // Dry-run: the excess is already recorded by the incr
                        // above; log and forward.
                        $this->logDryRunBlock($ip, $newCount, $reset - $now);
                        return $handler->handle($request);
                    }
                    return $this->tooMany($reset - $now);
                }
                return $handler->handle($request);
            }
        }

        // New window (or first request for this IP).
        $ok = Store::set($this->tableName, $ip, [
            'ip'    => $ip,
            'count' => 1,
            'reset' => $now + $this->window,
        ]);

        // B10 fix: log when the Store table is full (Store::set returns false).
        if ($ok === false && function_exists('ZealPHP\\elog')) {
            \ZealPHP\elog(
                "RateLimitMiddleware: Store table '{$this->tableName}' is full; "
                . "failing open for IP {$ip}.",
                'rate_limit'
            );
        }

        return $handler->handle($request);
    }

    private function isLoopback(string $ip): bool
    {
        return $ip === '127.0.0.1'
            || $ip === '::1'
            || $ip === '::ffff:127.0.0.1'
            || str_starts_with($ip, '127.');
    }

    private function tooMany(int $retryAfterSeconds): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->status = $this->rejectStatus;
        $status = $this->rejectStatus;
        $body = $status === 429 ? 'Too Many Requests' : 'Service Unavailable';
        $headers = [
            'Content-Type' => 'text/plain',
            'Retry-After'  => (string)max(1, $retryAfterSeconds),
        ];
        if ($g->zealphp_response !== null) {
            foreach ($headers as $name => $value) {
                $g->zealphp_response->header($name, $value);
            }
        }
        return new Response($body, $status, '', $headers);
    }

    private function logDryRunBlock(string $ip, int $count, int $retryAfterSeconds): void
    {
        if (function_exists('ZealPHP\\elog')) {
            // nodelay is a forwarding hint (burst requests forwarded immediately).
            // With the current fixed-window algorithm there is no artificial delay,
            // so nodelay=true and nodelay=false are behaviourally identical — but
            // the flag is surfaced in the dry-run log for operator visibility.
            $nodedelayStr = $this->nodelay ? 'nodelay' : 'delay';
            \ZealPHP\elog(
                "RateLimitMiddleware [dry-run]: would have blocked IP {$ip} "
                . "(count={$count}, retry-after={$retryAfterSeconds}s, "
                . "table='{$this->tableName}', {$nodedelayStr}).",
                'rate_limit'
            );
        }
    }
}

<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;
use ZealPHP\Store;

/**
 * Rate-Limit Middleware (sliding window, per-IP, shared across workers)
 *
 * Tracks request counts per client IP in a shared `Store` table. When a
 * single IP exceeds `$limit` requests inside `$window` seconds, further
 * requests get `429 Too Many Requests` with a `Retry-After` header.
 *
 * nginx equivalent:
 *   limit_req_zone $binary_remote_addr zone=one:10m rate=60r/m;
 *   limit_req zone=one burst=5 nodelay;
 *
 * The pattern mirrors `Auth::rateLimit()` (src/Learn/Auth.php) — a fixed
 * window resetting every `$window` seconds (not a true rolling window;
 * good enough for spam/abuse defence, not for billing-grade fairness).
 *
 * **Loopback bypass.** By default, requests from 127.0.0.1 / ::1 are not
 * rate-limited so the integration test suite can run repeatedly without
 * `php app.php restart`. Set `ZEALPHP_RATE_LIMIT_LOOPBACK=1` to opt in
 * (useful when you're testing the rate limiter itself).
 *
 * **Store table is required.** Create it before `$app->run()`:
 *
 *   Store::make('rate_limit', 16384, [
 *       'ip'    => [\OpenSwoole\Table::TYPE_STRING, 64],
 *       'count' => [\OpenSwoole\Table::TYPE_INT,    4],
 *       'reset' => [\OpenSwoole\Table::TYPE_INT,    4],
 *   ]);
 *
 *   $app->addMiddleware(new \ZealPHP\Middleware\RateLimitMiddleware(
 *       limit:     60,
 *       window:    60,
 *       tableName: 'rate_limit',
 *   ));
 *
 * If the table doesn't exist when the request arrives the middleware
 * fails-open (passes the request through) and logs once via elog().
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private static bool $warnedMissingTable = false;

    public function __construct(
        private int    $limit     = 60,
        private int    $window    = 60,
        private string $tableName = 'rate_limit',
    ) {
        if ($limit < 0) {
            throw new \InvalidArgumentException('limit must be >= 0');
        }
        if ($window <= 0) {
            throw new \InvalidArgumentException('window must be > 0');
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

        $ip = $this->clientIp($request);
        if ($ip === '') {
            return $handler->handle($request);
        }
        if ($this->isLoopback($ip) && getenv('ZEALPHP_RATE_LIMIT_LOOPBACK') !== '1') {
            return $handler->handle($request);
        }

        $now = time();
        $existing = Store::get($this->tableName, $ip);
        if (is_array($existing)) {
            $reset = is_numeric($existing['reset'] ?? null) ? (int)$existing['reset'] : 0;
            $count = is_numeric($existing['count'] ?? null) ? (int)$existing['count'] : 0;
            if ($now < $reset) {
                if ($count >= $this->limit) {
                    return $this->tooMany($reset - $now);
                }
                Store::incr($this->tableName, $ip, 'count', 1);
                return $handler->handle($request);
            }
        }
        Store::set($this->tableName, $ip, [
            'ip'    => $ip,
            'count' => 1,
            'reset' => $now + $this->window,
        ]);
        return $handler->handle($request);
    }

    private function clientIp(ServerRequestInterface $request): string
    {
        $g = RequestContext::instance();
        $ip = (string)($g->server['REMOTE_ADDR'] ?? '');
        if ($ip !== '') {
            return $ip;
        }
        $params = $request->getServerParams();
        return (string)($params['REMOTE_ADDR'] ?? '');
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
        $g->status = 429;
        $headers = [
            'Content-Type' => 'text/plain',
            'Retry-After'  => (string)max(1, $retryAfterSeconds),
        ];
        if ($g->zealphp_response !== null) {
            foreach ($headers as $name => $value) {
                $g->zealphp_response->header($name, $value);
            }
        }
        return new Response('Too Many Requests', 429, '', $headers);
    }
}

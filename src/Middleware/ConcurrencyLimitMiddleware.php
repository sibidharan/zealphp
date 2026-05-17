<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\Counter;
use ZealPHP\RequestContext;

/**
 * Concurrency-Limit Middleware
 *
 * Bounds the number of in-flight requests across all workers using a shared
 * `Counter` (OpenSwoole\Atomic). Excess requests get `503 Service Unavailable`
 * immediately rather than queuing — pair with an upstream load balancer if
 * you want queuing semantics.
 *
 * nginx equivalent:
 *   limit_conn_zone $binary_remote_addr zone=addr:10m;
 *   limit_conn addr 100;
 *
 * The counter is incremented on entry and decremented on exit via
 * try/finally, so handlers that throw still decrement correctly. The
 * Counter must be instantiated **before** `$app->run()` so all forked
 * workers share the same atomic.
 *
 * Usage in app.php:
 *
 *   $inflight = new \ZealPHP\Counter();
 *   $app->addMiddleware(new \ZealPHP\Middleware\ConcurrencyLimitMiddleware(
 *       maxConcurrent: 100,
 *       counter:       $inflight,
 *   ));
 *
 * Tune `maxConcurrent` based on worker count × per-worker coroutine
 * budget — `ZEALPHP_WORKERS=16` with 256 coroutines/worker gives a
 * realistic ceiling around 4000 concurrent in-flight requests; setting
 * the limit higher than that is a no-op.
 */
class ConcurrencyLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private int     $maxConcurrent,
        private Counter $counter,
    ) {
        if ($maxConcurrent <= 0) {
            throw new \InvalidArgumentException('maxConcurrent must be > 0');
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $newValue = $this->counter->increment(1);
        if ($newValue > $this->maxConcurrent) {
            // Roll back the increment so we don't permanently inflate the
            // counter when overload sheds requests.
            $this->counter->decrement(1);
            return $this->serviceUnavailable();
        }

        try {
            return $handler->handle($request);
        } finally {
            $this->counter->decrement(1);
        }
    }

    private function serviceUnavailable(): ResponseInterface
    {
        $g = RequestContext::instance();
        $g->status = 503;
        $headers = [
            'Content-Type' => 'text/plain',
            'Retry-After'  => '1',
        ];
        if ($g->zealphp_response !== null) {
            foreach ($headers as $name => $value) {
                $g->zealphp_response->header($name, $value);
            }
        }
        return new Response('Service Unavailable', 503, '', $headers);
    }
}

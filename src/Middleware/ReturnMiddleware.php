<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Return Middleware — nginx `return` directive parity.
 *
 * Unconditionally returns a fixed response, like nginx `return` inside a
 * `location`: the route handler never runs. Pair with `ScopedMiddleware` to
 * limit it to a path (the nginx `location { return ... }` shape):
 *
 *   - `new ReturnMiddleware(403)`                  → `return 403;`
 *   - `new ReturnMiddleware(301, '/new')`          → `return 301 /new;` (Location)
 *   - `new ReturnMiddleware(200, 'pong')`          → `return 200 "pong";` (body)
 *
 * For 3xx statuses the second argument is treated as the redirect target
 * (`Location`); for any other status it is the response body.
 *
 * Usage in app.php:
 *
 * ```php
 * $app->addMiddleware(ScopedMiddleware::location(new ReturnMiddleware(403), '/blocked'));
 * $app->addMiddleware(ScopedMiddleware::match(new ReturnMiddleware(301, '/new'), '#^/old$#'));
 * ```
 */
class ReturnMiddleware implements MiddlewareInterface
{
    public function __construct(private int $status, private ?string $textOrUrl = null)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->textOrUrl !== null && $this->status >= 300 && $this->status < 400) {
            return new Response('', $this->status, '', ['Location' => $this->textOrUrl]);
        }
        return new Response($this->textOrUrl ?? '', $this->status);
    }
}

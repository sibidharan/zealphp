<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use OpenSwoole\Core\Psr\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Block `.php` Extension Middleware
 *
 * Refuses any request whose path matches `\.php(/|$)` with a `404`. Useful for
 * apps that want extensionless URLs as the only public surface and don't want
 * `/index.php`, `/admin.php`, etc. to be reachable even when a public file
 * exists.
 *
 * Apache equivalent — the classic `.htaccess` pattern:
 *
 * ```
 * RewriteCond %{THE_REQUEST} ^[A-Z]{3,}\s([^.]+)\.php [NC]
 * RewriteRule ^ - [R=404,L]
 * ```
 *
 * nginx equivalent:
 *   `location ~ \.php$ { return 404; }`
 *
 * Usage in `app.php`:
 *   `$app->addMiddleware(new \ZealPHP\Middleware\BlockPhpExtMiddleware());`
 */
class BlockPhpExtMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        // Match `.php` at the end of ANY path segment, not just the whole path —
        // otherwise a PATH_INFO suffix (/admin.php/foo) bypasses the block (#184).
        if (preg_match('#\.php(/|$)#i', $path) === 1) {
            return new Response('Not Found', 404, '', ['Content-Type' => 'text/plain']);
        }
        return $handler->handle($request);
    }
}

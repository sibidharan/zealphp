<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\RequestContext;

use function ZealPHP\Session\zeal_session_start;

class SessionStartMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = RequestContext::instance();

        // zeal_session_start() auto-emits Set-Cookie on new sessions (issue
        // #12 fix in src/Session/utils.php) — no need to emit it from here.
        // This middleware just triggers an eager start so first-time visitors
        // get a session cookie even when their handler never calls
        // session_start() themselves.
        if (!$g->_session_started) {
            zeal_session_start();
            $g->_session_started = true;
        }

        return $handler->handle($request);
    }
}

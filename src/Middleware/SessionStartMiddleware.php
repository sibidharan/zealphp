<?php
declare(strict_types=1);

namespace ZealPHP\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ZealPHP\G;

use function ZealPHP\Session\zeal_session_id;
use function ZealPHP\Session\zeal_session_start;
use function ZealPHP\Session\zeal_session_get_cookie_params;
use function ZealPHP\Session\zeal_session_name;

class SessionStartMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = G::instance();

        if (!$g->_session_started) {
            zeal_session_start();
            $g->_session_started = true;

            $sessionName = zeal_session_name();
            $sessionId = zeal_session_id();
            $cookie = zeal_session_get_cookie_params();
            \ZealPHP\elog("SessionStart: id=$sessionId secure=" . ($cookie['secure'] ? 'true' : 'false'));
            $g->openswoole_response->cookie(
                $sessionName,
                $sessionId,
                $cookie['lifetime'] ? time() + $cookie['lifetime'] : 0,
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly']
            );
        }

        return $handler->handle($request);
    }
}

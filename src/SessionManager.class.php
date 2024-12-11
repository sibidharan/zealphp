<?php
declare(strict_types=1);
/**
 * Copyright © Upscale Software. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace ZealPHP\Session;
use function ZealPHP\zlog;
use function ZealPHP\uniqidReal;
class SessionManager
{
    /**
     * @var callable
     */
    protected $middleware;

    /**
     * @var callable
     */
    protected $idGenerator;

    protected bool $useCookies;

    protected bool $useOnlyCookies;

    /**
     * Inject dependencies
     *
     * @param callable $middleware function (\Swoole\Http\Request $request, \Swoole\Http\Response $response)
     * @param callable $idGenerator
     * @param bool|null $useCookies
     * @param bool|null $useOnlyCookies
     */
    public function __construct(
        callable $middleware,
        $idGenerator = 'session_create_id',
        ?bool $useCookies = null,
        ?bool $useOnlyCookies = null
    ) {
        $this->middleware = $middleware;
        $this->idGenerator = $idGenerator;
        $this->useCookies = is_null($useCookies) ? (bool)ini_get('session.use_cookies') : $useCookies;
        $this->useOnlyCookies = is_null($useOnlyCookies) ? (bool)ini_get('session.use_only_cookies') : $useOnlyCookies;
    }

    /**
     * Delegate execution to the underlying middleware wrapping it into the session start/stop calls
     */
    public function __invoke(\Swoole\Http\Request $request, \Swoole\Http\Response $response)
    {
        // error_log('SessionManager::__invoke');
        if(isset($_SESSION) and isset($_SESSION['__start_time'])) {
            error_log('[warn] Session leak detected');
        }
        unset($_SESSION);
        $_SESSION = [];
        $sessionName = session_name();
        if ($this->useCookies && isset($request->cookie[$sessionName])) {
            $sessionId = $request->cookie[$sessionName];
        } else if (!$this->useOnlyCookies && isset($request->get[$sessionName])) {
            $sessionId = $request->get[$sessionName];
        } else {
            $sessionId = call_user_func($this->idGenerator);
        }
        session_id($sessionId);
        // error_log('SessionManager::__invoke session_id: ' . session_id());

        session_start();

        // error_log('SessionManager:: session_start');
        if ($this->useCookies) {
            $cookie = session_get_cookie_params();
            $response->cookie(
                $sessionName,
                $sessionId,
                $cookie['lifetime'] ? time() + $cookie['lifetime'] : 0,
                $cookie['path'],
                $cookie['domain'],
                $cookie['secure'],
                $cookie['httponly']
            );
        }
        try {
            $time = microtime();
            $time = explode(' ', $time);
            $time = $time[1] + $time[0];
            $_SESSION['__start_time'] = $time;
            $_SESSION['UNIQUE_REQUEST_ID'] = uniqidReal();
            // zlog("SessionManager:: session_id: " . session_id() . " session_start: " . $_SESSION['__start_time']. " UNIQUE_ID: " . $_SESSION['UNIQUE_REQUEST_ID']);
            call_user_func($this->middleware, $request, $response);
            // error_log('SessionManager:: middleware executed');
        } finally {
            // error_log('SessionManager:: session_write_close');
            session_write_close();
            session_id('');
            $_SESSION = [];
            unset($_SESSION);
            // error_log('SessionManager:: session_id unset and reset');
        }
    }
}
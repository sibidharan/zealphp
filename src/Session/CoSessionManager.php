<?php
namespace ZealPHP\Session;

use function ZealPHP\elog;
use function ZealPHP\bench_mode_enabled;
use function ZealPHP\uniqidReal;
use function ZealPHP\get_current_render_time;

use OpenSwoole\Coroutine as co;

use ZealPHP\Session\Handler\FileSessionHandler;
use ZealPHP\RequestContext;

class CoSessionManager
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
    public function __invoke(\OpenSwoole\Http\Request $request, \OpenSwoole\Http\Response $response)
    {
        $g = RequestContext::instance();
        if (bench_mode_enabled()) {
            $g->session = [];
            $g->openswoole_request = $request;
            $g->openswoole_response = $response;
            $request = new \ZealPHP\HTTP\Request($request);
            $response = new \ZealPHP\HTTP\Response($response);
            $g->zealphp_request = $request;
            $g->zealphp_response = $response;
            try {
                call_user_func($this->middleware, $request, $response);
            } finally {
                unset($g->session);
            }
            return;
        }

        // $g->session is a declared typed property with default [] — always
        // "set". Only check for residue from a prior request in this worker.
        if (isset($g->session['__start_time'])) {
            elog('[warn] Session leak detected');
        }
        $g->session = [];
        $g->_session_started = false;

        $sessionName = zeal_session_name();
        $hasSessionCookie = $this->useCookies && isset($request->cookie[$sessionName]);
        $hasSessionParam = !$this->useOnlyCookies && isset($request->get[$sessionName]);

        // Lazy session: only start if client already has a session cookie/param.
        // For new visitors, use SessionStartMiddleware to eagerly start sessions.
        if ($hasSessionCookie || $hasSessionParam) {
            $sessionId = $hasSessionCookie ? $request->cookie[$sessionName] : $request->get[$sessionName];
            zeal_session_id($sessionId);
            zeal_session_start();
            $g->_session_started = true;

            if ($this->useCookies) {
                $cookie = zeal_session_get_cookie_params();
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
        }

        try {
            $time = microtime();
            $time = explode(' ', $time);
            $time = $time[1] + $time[0];
            $g->session['__start_time'] = $time;
            $g->session['UNIQUE_REQUEST_ID'] = uniqidReal();
            $g->openswoole_request = $request;
            $g->openswoole_response = $response;
            $request = new \ZealPHP\HTTP\Request($request);
            $response = new \ZealPHP\HTTP\Response($response);
            $g->zealphp_request = $request;
            $g->zealphp_response = $response;

            call_user_func($this->middleware, $request, $response);
        } finally {
            if ($g->_session_started) {
                zeal_session_write_close();
                zeal_session_id('');
            }
            $g->session = [];
            unset($g->session);
        }
    }
}

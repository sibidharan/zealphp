<?php
namespace ZealPHP\Session;

use function ZealPHP\elog;
use function ZealPHP\bench_mode_enabled;
use function ZealPHP\uniqidReal;
use function ZealPHP\get_current_render_time;

use OpenSwoole\Coroutine as co;

use ZealPHP\Session\Handler\FileSessionHandler;
use ZealPHP\RequestContext;

use OpenSwoole\Core\Psr\Middleware\StackHandler;
use OpenSwoole\Core\Psr\Response;
use OpenSwoole\HTTP\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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

    public $g;

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
        $this->g = RequestContext::instance();
    }

    // public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {

    // }

    /**
     * Delegate execution to the underlying middleware wrapping it into the session start/stop calls
     */
    public function __invoke($request,$response)
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

        if(isset($_SESSION) and isset($_SESSION['__start_time'])) {
            elog('[warn] Session leak detected');
        }
        unset($_SESSION);
        $_SESSION = [];

        // Superglobals mode runs G as a process-wide singleton. Without an
        // explicit reset, error/exception/shutdown handler stacks pushed by
        // legacy code during request N survive to request N+1 — the classic
        // "handler chain grows until worker recycles" leak. Coroutine mode
        // avoids this naturally (G is per-coroutine, freed on coroutine end);
        // here we have to reset by hand.
        $g = RequestContext::instance();
        $g->error_handlers_stack     = [];
        $g->exception_handlers_stack = [];
        $g->shutdown_functions       = [];
        $g->error_render_depth       = 0;
        $g->error_reporting_level    = null;
        $g->error_status             = null;
        $g->error_exception          = null;
        $g->status                   = null;
        $g->_streaming               = null;
        $g->ignore_user_abort_state  = 0;

        $sessionName = session_name();
        if ($this->useCookies && isset($request->cookie[$sessionName])) {
            $sessionId = $request->cookie[$sessionName];
        } else if (!$this->useOnlyCookies && isset($request->get[$sessionName])) {
            $sessionId = $request->get[$sessionName];
        } else {
            $sessionId = call_user_func($this->idGenerator);
        }
        session_id($sessionId);

        $handler = new FileSessionHandler();
        session_set_save_handler($handler, true);

        session_start();

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
            $_SESSION['__start_time'] = microtime(true);
            $_SESSION['UNIQUE_REQUEST_ID'] = uniqidReal();
            $g->openswoole_request = $request;
            $g->openswoole_response = $response;
            $request = new \ZealPHP\HTTP\Request($request);
            $response = new \ZealPHP\HTTP\Response($response);
            $g->zealphp_request = $request;
            $g->zealphp_response = $response;
            call_user_func($this->middleware, $request, $response);
        } finally {
            elog('SessionManager:: session_write_close took '.get_current_render_time(), 'info');
            session_write_close();
            session_id('');
            $_SESSION = [];
            unset($_SESSION);
        }
    }
}

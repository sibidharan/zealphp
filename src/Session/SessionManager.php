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
     * @var string|callable
     */
    protected $idGenerator;

    protected bool $useCookies;

    protected bool $useOnlyCookies;

    public \ZealPHP\RequestContext $g;

    /**
     * Inject dependencies
     *
     * @param callable $middleware function (\Swoole\Http\Request $request, \Swoole\Http\Response $response)
     * @param callable $idGenerator
     * @param bool|null $useCookies
     * @param bool|null $useOnlyCookies
     */
    /**
     * Check if function isolation cleanup is safe for the current state.
     *
     * Returns false if any registered spl_autoload callback resolves to a
     * class file under App::documentRoot() — that means an app has its own
     * Composer autoloader registered, and cleanup would orphan its lazy-
     * loaded classes (the require_once cache stays but the classes get
     * removed). For autoloader-based apps, use Mode 1 (CGI Pool) instead.
     */
    private static function safeForFunctionIsolation(): bool
    {
        $docRoot = \ZealPHP\App::$document_root;
        if ($docRoot === '' || $docRoot === '.') return true;
        $docRoot = \rtrim($docRoot, '/');
        foreach (\spl_autoload_functions() ?: [] as $cb) {
            if (\is_array($cb) && \is_object($cb[0])) {
                $obj = $cb[0];
                if ($obj instanceof \Composer\Autoload\ClassLoader) {
                    foreach ($obj->getPrefixesPsr4() as $paths) {
                        foreach ($paths as $p) {
                            if (\str_starts_with($p, $docRoot . '/')) return false;
                        }
                    }
                    foreach ($obj->getPrefixes() as $paths) {
                        foreach ($paths as $p) {
                            if (\str_starts_with($p, $docRoot . '/')) return false;
                        }
                    }
                    foreach ($obj->getClassMap() as $file) {
                        if (\str_starts_with($file, $docRoot . '/')) return false;
                    }
                }
            }
        }
        return true;
    }

    /**
     * @param string|callable $idGenerator
     */
    public function __construct(
        callable $middleware,
        string|callable $idGenerator = 'session_create_id',
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
     *
     * @param \OpenSwoole\Http\Request  $request
     * @param \OpenSwoole\Http\Response $response
     */
    public function __invoke($request, $response): void
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

        // Session lifecycle is opt-out (via App::sessionLifecycle(false)) for
        // setups where another framework owns sessions — e.g. Symfony's
        // NativeSessionStorage through the zealphp-symfony bridge. When
        // disabled, we still do the request-context setup + handler-stack
        // reset below; we just skip reading the PHPSESSID cookie, calling
        // session_start, and emitting our own Set-Cookie header. The native
        // session_* functions remain available for user code that wants
        // them.
        //
        // Issue #108 — also skip when the CGI subprocess owns sessions
        // (superglobals(true) + processIsolation(true)). In that lifecycle
        // every public file is dispatched to cgiPool / cgiSubprocess /
        // cgiFcgi, and the subprocess runs native PHP session_start under
        // its own SAPI. Having BOTH the host and the subprocess drive
        // session I/O on the same file produces a race where the host's
        // session_write_close() in this finally block overwrites the
        // subprocess's writes with the host's stale in-memory state. The
        // subprocess captures its own Set-Cookie via uopz (cgi_worker.php /
        // pool_worker.php) and cgiPool / cgiSubprocess / cgiFcgi thread it
        // back into the outbound response, so the cookie story still
        // works — the host just gets out of the way.
        $manageSession = \ZealPHP\App::$session_lifecycle
            && !\ZealPHP\App::cgiOwnsSessions();

        if ($manageSession) {
            if(isset($_SESSION) and isset($_SESSION['__start_time'])) {
                elog('[warn] Session leak detected');
            }
            unset($_SESSION);
            $_SESSION = [];
        }

        // Superglobals mode runs G as a process-wide singleton. Without an
        // explicit reset, error/exception/shutdown handler stacks pushed by
        // legacy code during request N survive to request N+1 — the classic
        // "handler chain grows until worker recycles" leak. Coroutine mode
        // avoids this naturally (G is per-coroutine, freed on coroutine end);
        // here we have to reset by hand. This runs regardless of
        // sessionLifecycle — it's not session-specific.
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
        $g->_session_started         = false;

        $sessionId = null;
        if ($manageSession) {
            $sessionName = session_name() ?: 'PHPSESSID';
            $reqCookie = is_array($request->cookie) ? $request->cookie : [];
            $reqGet = is_array($request->get) ? $request->get : [];
            if ($this->useCookies && isset($reqCookie[$sessionName])) {
                $rawSid = $reqCookie[$sessionName];
            } else if (!$this->useOnlyCookies && isset($reqGet[$sessionName])) {
                $rawSid = $reqGet[$sessionName];
            } else {
                $gen = $this->idGenerator;
                $rawSid = is_callable($gen) ? $gen() : null;
            }
            $sessionId = is_string($rawSid) ? $rawSid : null;
            session_id($sessionId);

            static $handlerRegistered = false;
            if (!$handlerRegistered) {
                $handler = new FileSessionHandler();
                @session_set_save_handler($handler, true);
                $handlerRegistered = true;
            }

            session_start();
            $g->_session_started = true;

            // v0.2.27 — make $g->session and $_SESSION the same array.
            //
            // Reference assignment ($g->session = &$_SESSION) doesn't work
            // because RequestContext has __get/__set ("overloaded object"
            // forbids reference assignment in PHP). Instead: unset the
            // declared typed property so the slot becomes "uninitialized,"
            // which routes reads/writes through the existing __get proxy
            // (RequestContext.php:111) that returns $GLOBALS['_SESSION'] by
            // reference. Combined with the symmetric __set superglobal-key
            // mapping (also added in v0.2.27), $g->session and $_SESSION are
            // now indistinguishable in superglobals mode — both names point
            // at the same array, mutations cross over immediately.
            //
            // The v0.2.22 mirror code in zeal_session_* stays in place as
            // belt-and-suspenders for direct $g->session reads before the
            // first session_*() call.
            unset($g->session);

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
        }
        try {
            if ($manageSession) {
                $_SESSION['__start_time'] = microtime(true);
                $_SESSION['UNIQUE_REQUEST_ID'] = uniqidReal();
            }
            $g->openswoole_request = $request;
            $g->openswoole_response = $response;
            $request = new \ZealPHP\HTTP\Request($request);
            $response = new \ZealPHP\HTTP\Response($response);
            $g->zealphp_request = $request;
            $g->zealphp_response = $response;
            call_user_func($this->middleware, $request, $response);
        } finally {
            if ($manageSession) {
                elog('SessionManager:: session_write_close took '.get_current_render_time(), 'info');
                session_write_close();
                session_id('');
                $_SESSION = [];
                unset($_SESSION);
            }
            if (\ZealPHP\App::$define_isolation
                && \function_exists('zealphp_constants_clear')
            ) {
                (\zealphp_constants_clear(...))();
            }
            if (\function_exists('zealphp_ini_restore')) {
                @(\zealphp_ini_restore(...))();
            }
            if (\ZealPHP\App::$function_isolation) {
                // $GLOBALS user-var cleanup is ALWAYS safe to run — it only
                // touches EG(symbol_table) user slots, never the function or
                // class tables. Decoupled from safeForFunctionIsolation()
                // so autoloader-based apps don't lose $GLOBALS isolation.
                if (\function_exists('zealphp_globals_clean')) {
                    (\zealphp_globals_clean(...))();
                }
                // Function/class/include cleanup is only safe when no app
                // autoloader is registered from documentRoot — removing
                // autoloaded classes would orphan the autoloader's lazy
                // class map.
                if (\function_exists('zealphp_process_state_clean')
                    && self::safeForFunctionIsolation()
                ) {
                    (\zealphp_process_state_clean(...))(6);
                }
            }
        }
    }
}

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
     * @var string|callable
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
    /**
     * Resolve the session handler from App::$session_handler. CoSessionManager
     * runs in coroutine mode, so the default (null) is TableSessionHandler —
     * concurrent-safe via 3-way merge + Atomic CAS + file backing.
     */
    private static function resolveHandler(): ?\SessionHandlerInterface
    {
        $h = \ZealPHP\App::$session_handler;
        if ($h instanceof \SessionHandlerInterface) return $h;
        switch ($h) {
            case 'file':
                return new \ZealPHP\Session\Handler\FileSessionHandler();
            case 'table':
            case null:  // default in coroutine mode — concurrent-safe
                return \ZealPHP\Session\Handler\TableSessionHandler::register();
            case 'redis':
                return new \ZealPHP\Session\Handler\RedisSessionHandler();
            default:
                return null;
        }
    }

    /** Skip cleanup when an app autoloader is registered (its lazy-loaded
     * classes would be cleaned but require_once cache persists). */
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
    }

    /**
     * Delegate execution to the underlying middleware wrapping it into the session start/stop calls
     */
    public function __invoke(\OpenSwoole\Http\Request $request, \OpenSwoole\Http\Response $response): void
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

        // Session lifecycle is opt-out (via App::sessionLifecycle(false)) for
        // setups where another framework owns sessions — e.g. Symfony's
        // NativeSessionStorage through the zealphp-symfony bridge. When
        // disabled, we still do the request-context setup below; we just
        // skip reading the PHPSESSID cookie, calling zeal_session_start, and
        // emitting our own Set-Cookie header. The zeal_session_* uopz
        // overrides remain available to user code regardless.
        //
        // Also skip when the CGI subprocess owns sessions (pi=true) — same
        // guard SessionManager has. Without this, CoSessionManager and the
        // subprocess both drive session I/O on the same file, racing writes.
        $manageSession = \ZealPHP\App::$session_lifecycle
            && !\ZealPHP\App::cgiOwnsSessions();

        if ($manageSession) {
            // One-shot per-worker handler registration. Resolves App::$session_handler:
            //   null → auto-pick (TableSessionHandler — concurrent-safe, this IS coroutine mode)
            //   'table'|'file'|'redis' → corresponding handler
            //   SessionHandlerInterface → use directly
            static $handlerRegistered = false;
            if (!$handlerRegistered) {
                $handler = self::resolveHandler();
                if ($handler !== null) {
                    @\session_set_save_handler($handler, true);
                }
                $handlerRegistered = true;
            }

            $sessionName = zeal_session_name();
            $reqCookie = is_array($request->cookie) ? $request->cookie : [];
            $reqGet = is_array($request->get) ? $request->get : [];
            $hasSessionCookie = $this->useCookies && isset($reqCookie[$sessionName]);
            $hasSessionParam = !$this->useOnlyCookies && isset($reqGet[$sessionName]);

            // Lazy session: only start if client already has a session cookie/param.
            // For new visitors, use SessionStartMiddleware to eagerly start sessions.
            if ($hasSessionCookie || $hasSessionParam) {
                $rawSid = $hasSessionCookie ? $reqCookie[$sessionName] : $reqGet[$sessionName];
                $sessionId = is_string($rawSid) ? $rawSid : null;
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
        }

        try {
            if ($manageSession) {
                $g->session['__start_time'] = microtime(true);
                $g->session['UNIQUE_REQUEST_ID'] = uniqidReal();
            }
            $g->openswoole_request = $request;
            $g->openswoole_response = $response;
            $request = new \ZealPHP\HTTP\Request($request);
            $response = new \ZealPHP\HTTP\Response($response);
            $g->zealphp_request = $request;
            $g->zealphp_response = $response;

            call_user_func($this->middleware, $request, $response);
        } finally {
            if ($manageSession && $g->_session_started) {
                zeal_session_write_close();
                zeal_session_id('');
            }
            $g->session = [];
            unset($g->session);
            if (\ZealPHP\App::$define_isolation
                && \function_exists('zealphp_constants_clear')
            ) {
                (\zealphp_constants_clear(...))();
            }
            if (\function_exists('zealphp_ini_restore')) {
                @(\zealphp_ini_restore(...))();
            }
            if (\ZealPHP\App::$function_isolation
                && \function_exists('zealphp_process_state_clean')
                && self::safeForFunctionIsolation()
            ) {
                (\zealphp_process_state_clean(...))(6);
            }
        }
    }
}

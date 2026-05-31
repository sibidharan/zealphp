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
            // Stage 7 include_isolation needs no per-request cleanup —
            // the ZEND_INCLUDE_OR_EVAL opcode handler handles it inline.
            if (\ZealPHP\App::$function_isolation) {
                if (!\ZealPHP\App::$keep_globals
                    && \function_exists('zealphp_globals_clean')) {
                    (\zealphp_globals_clean(...))();
                }
                if (\function_exists('zealphp_process_state_clean')
                    && self::safeForFunctionIsolation()
                ) {
                    (\zealphp_process_state_clean(...))(6);
                }
            }
            // Per-coroutine $GLOBALS isolation: drain object-valued globals (the
            // `global $wpdb; $wpdb = new wpdb()` pattern) HERE — in the request
            // coroutine's PHP context — so an isolated object's __destruct may
            // yield (e.g. closing a DB socket under HOOK_ALL). Without this the
            // final ref falls to on_close (outside any coroutine) and an I/O
            // destructor throws "API must be called in the coroutine". No-op
            // unless coroutine $GLOBALS isolation is active (the ext gates it);
            // runs regardless of function_isolation (coroutine-legacy leaves that
            // off yet still isolates globals).
            if (\function_exists('zealphp_coroutine_globals_request_end')) {
                (\zealphp_coroutine_globals_request_end(...))();
            }
            // Per-request run_time_cache reset (coroutine-legacy). User functions
            // and methods persisted across requests by silent-redeclare keep an
            // op_array run_time_cache that caches resolved constant / function /
            // method / property pointers. That cache lives in CG(arena), which is
            // rewound every request — but the op_array's map_ptr still points at the
            // reused arena slot, so the next request reads a STALE resolution
            // (classic symptom: `define('MB', 1024 * KB_IN_BYTES)` throws
            // "Unsupported operand types: string * int" because the cached
            // KB_IN_BYTES fetch returns garbage, while constant('KB_IN_BYTES') is
            // still correct). Nulling the map_ptr forces a fresh, correct re-init on
            // the next call. Leak-free (the arena is rewound) and concurrency-safe:
            // running coroutine frames captured EX(run_time_cache) at entry, so only
            // FUTURE calls re-resolve. Runs last so nothing here re-warms a cache we
            // just cleared. Gated on silent_redeclare (the marker that user symbols
            // persist across requests); a no-op otherwise.
            if (\ZealPHP\App::$silent_redeclare
                && \function_exists('zealphp_reset_request_rtcaches')
            ) {
                (\zealphp_reset_request_rtcaches(...))();
            }
            // Per-request function-static reset (coroutine-legacy) — the PHP-FPM
            // "fresh process per request" contract. PHP's shutdown_executor()
            // destroys every user function/method's live static_variables table at
            // request end so `static $x = INIT;` re-initialises next request;
            // OpenSwoole never runs that per-request shutdown, so a static keeps its
            // last value across requests. The canonical break is WordPress's
            // `static $first_init` in wp_start_object_cache() — a stale `false` on
            // request 2 skips wp_cache_init(), leaving $wp_object_cache null ->
            // "Call to a member function switch_to_blog() on null" 500, then a worker
            // crash on request 3. zealphp_reset_request_statics() mirrors
            // shutdown_executor() for per-request (non-boot) symbols. Same gate.
            if (\ZealPHP\App::$silent_redeclare
                && \function_exists('zealphp_reset_request_statics')
            ) {
                (\zealphp_reset_request_statics(...))();
            }
        }
    }
}

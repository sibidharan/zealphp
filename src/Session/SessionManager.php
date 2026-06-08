<?php
namespace ZealPHP\Session;

use function ZealPHP\elog;
use function ZealPHP\bench_mode_enabled;
use function ZealPHP\uniqidReal;
use function ZealPHP\get_current_render_time;

use OpenSwoole\Coroutine as co;

use ZealPHP\RequestContext;

use OpenSwoole\Core\Psr\Middleware\StackHandler;
use OpenSwoole\Core\Psr\Response;
use OpenSwoole\HTTP\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * OpenSwoole `onRequest` handler for superglobals mode (`App::superglobals(true)`).
 *
 * Invoked as a callable (via `__invoke()`) for every inbound HTTP request on the
 * worker. Responsible for:
 * - Resetting per-request `RequestContext` state (error/exception/shutdown stacks).
 * - Optionally managing the PHP session lifecycle (`session_start()` → inner
 *   middleware stack → `session_write_close()`), gated on `App::$session_lifecycle`
 *   and `App::cgiOwnsSessions()`.
 * - Wrapping the raw OpenSwoole request/response in `ZealPHP\HTTP\Request` /
 *   `ZealPHP\HTTP\Response` and storing them on `RequestContext`.
 * - Running the per-request isolation cleanup hooks from `ext-zealphp` when
 *   `coroutine-legacy` mode is active (`zealphp_reset_request_rtcaches`,
 *   `zealphp_reset_request_statics`, `zealphp_reset_request_class_statics`).
 *
 * The coroutine-mode equivalent is `CoSessionManager`.
 */
class SessionManager
{
    /**
     * The inner middleware callable — the PSR-15 stack entry point.
     *
     * @var callable
     */
    protected $middleware;

    /**
     * Session ID generator: a callable or the string `'session_create_id'`.
     *
     * @var string|callable
     */
    protected $idGenerator;

    /** Whether to read/write the session ID via cookies. */
    protected bool $useCookies;

    /** Whether cookies are the only allowed transport for the session ID. */
    protected bool $useOnlyCookies;

    /** Snapshot of the `RequestContext` singleton at construction time. */
    public \ZealPHP\RequestContext $g;

    /**
     * Returns `false` when running `zealphp_process_state_clean()` would be unsafe.
     *
     * Checks registered `spl_autoload` callbacks: if any `Composer\Autoload\ClassLoader`
     * maps classes under `App::$document_root`, cleaning function/class state would
     * orphan those lazily-loaded classes. In that case the caller should skip the
     * cleanup and rely on `legacy-cgi` (process-isolated) mode instead.
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
     * Inject dependencies for the session manager.
     *
     * @param callable        $middleware      The inner PSR-15 middleware stack entry point.
     * @param string|callable $idGenerator     Session ID generator; defaults to `'session_create_id'`.
     * @param bool|null       $useCookies      When `null`, inherits `session.use_cookies` ini value.
     * @param bool|null       $useOnlyCookies  When `null`, inherits `session.use_only_cookies` ini value.
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
     * Handle one inbound OpenSwoole request: set up session + context, run the middleware stack, then clean up.
     *
     * Sequence (when session management is active):
     * 1. Reset per-request `RequestContext` state (error/shutdown stacks, streaming flags).
     * 2. Resolve the session ID from cookie or query string; call `session_start()`.
     * 3. Wrap raw OpenSwoole objects in `ZealPHP\HTTP\Request` / `ZealPHP\HTTP\Response`.
     * 4. Invoke the inner middleware stack via `call_user_func($this->middleware, ...)`.
     * 5. In the `finally` block: `session_write_close()`, isolation cleanup hooks
     *    (`zealphp_reset_request_rtcaches`, `zealphp_reset_request_statics`,
     *    `zealphp_reset_request_class_statics`), and constant/globals cleanup.
     *
     * When `App::$session_lifecycle` is `false` or `App::cgiOwnsSessions()` returns
     * `true`, the session lifecycle steps are skipped — context setup and cleanup
     * hooks still run.
     *
     * @param \OpenSwoole\Http\Request  $request  Raw OpenSwoole request object.
     * @param \OpenSwoole\Http\Response $response Raw OpenSwoole response object.
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
        // ResponseMiddleware stashes the canonical PSR-7 request here each
        // request; on the process-wide singleton (superglobals mode) it would
        // otherwise pin the previous request's object until the next dispatch
        // overwrote it. Drop the reference at request end. (Coroutine mode's G
        // is per-coroutine and freed automatically, so no equivalent is needed.)
        $g->psr_request              = null;

        $sessionId = null;
        if ($manageSession) {
            $sessionName = session_name() ?: 'PHPSESSID';
            // #305 — parse the raw Cookie header PHP-canonically (OpenSwoole's own
            // cookie parser is disabled via http_parse_cookie=false). Writes the
            // parsed map back onto $request->cookie so the Request wrapper created
            // below (and the superglobal populate) inherit it.
            $reqCookie = \ZealPHP\App::requestCookieMap($request);
            $reqGet = is_array($request->get) ? $request->get : [];
            // #244: track whether the id is CLIENT-SUPPLIED (cookie/query param)
            // vs server-minted by the idGenerator. Only a client id can be a
            // planted/fixated value, so only it is subject to the strict-mode
            // rotation below.
            $clientSupplied = false;
            if ($this->useCookies && isset($reqCookie[$sessionName])) {
                $rawSid = $reqCookie[$sessionName];
                $clientSupplied = true;
            } else if (!$this->useOnlyCookies && isset($reqGet[$sessionName])) {
                $rawSid = $reqGet[$sessionName];
                $clientSupplied = true;
            } else {
                $gen = $this->idGenerator;
                $rawSid = is_callable($gen) ? $gen() : null;
            }
            $sessionId = is_string($rawSid) ? $rawSid : null;
            session_id($sessionId);

            // #295 — honour the configured session handler instead of hardcoding
            // FileSessionHandler (which ignored App::sessionHandler()). Resolution is
            // memoised per worker; unconfigured (null) keeps the file default via the
            // read-site fallback to the same resolver.
            $activeHandler = \ZealPHP\App::resolveActiveSessionHandler();
            if ($activeHandler !== null) {
                $g->session_params['handler'] = $activeHandler;
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

            // #244 session.use_strict_mode: a CLIENT-SUPPLIED id that opened an
            // EMPTY session (stale / foreign / never-issued) must not be honoured
            // — regenerate to a fresh server-generated id and delete the old one
            // so a fixated id can't become an authed session. $_SESSION is the
            // canonical store here (the typed $g->session slot was just unset);
            // read it defensively in case an upstream cleared it. session_*() are
            // the framework's uopz overrides; session_regenerate_id(true) routes
            // through zeal_session_regenerate_id (deletes old + emits Set-Cookie)
            // and session_id() returns the new id for the cookie emit below.
            if (zeal_session_strict_should_regenerate(
                \ZealPHP\App::$session_strict_mode,
                $clientSupplied,
                isset($_SESSION) ? $_SESSION : []
            )) {
                session_regenerate_id(true);
                $newId = session_id();
                $sessionId = is_string($newId) ? $newId : $sessionId;
            }

            if ($this->useCookies) {
                // zeal_session_get_cookie_params() is exactly what the uopz
                // override of session_get_cookie_params() resolves to at runtime
                // (App.php), but carries the samesite-inclusive return type.
                $cookie = zeal_session_get_cookie_params();
                $response->cookie(
                    $sessionName,
                    $sessionId,
                    $cookie['lifetime'] ? time() + $cookie['lifetime'] : 0,
                    $cookie['path'],
                    $cookie['domain'],
                    $cookie['secure'],
                    $cookie['httponly'],
                    $cookie['samesite'] ?? 'Lax'  // 8th arg — emit SameSite (was dropped)
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
            // `global $wpdb; $wpdb = new wpdb()` pattern) in the request coroutine
            // so an isolated object's __destruct may yield. No-op unless coroutine
            // $GLOBALS isolation is active. See CoSessionManager for the rationale.
            if (\function_exists('zealphp_coroutine_globals_request_end')) {
                (\zealphp_coroutine_globals_request_end(...))();
            }
            // Per-request run_time_cache reset (coroutine-legacy) — see the detailed
            // rationale in CoSessionManager. Persisted user functions/methods keep an
            // arena-backed op_array run_time_cache (cached constant/method pointers);
            // CG(arena) is rewound every request, so the stale map_ptr yields garbage
            // resolutions on the next request. Nulling it forces a fresh re-init.
            if (\ZealPHP\App::perRequestStateResetsActive()
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
            if (\ZealPHP\App::perRequestStateResetsActive()
                && \function_exists('zealphp_reset_request_statics')
            ) {
                (\zealphp_reset_request_statics(...))();
            }
            // Per-request CLASS-STATIC-PROPERTY reset (coroutine-legacy) — the
            // class-property analog of the function-static reset above. PHP's
            // shutdown_executor() resets static properties per request too (via
            // zend_cleanup_internal_class_data()); OpenSwoole never runs that, and
            // the per-coroutine isolation leaves OBJECT statics process-shared, so a
            // static DI container / connection registry persists across requests —
            // Drupal's static service container then throws "The specified database
            // connection is not defined" on request 2. Resets non-boot user-class
            // statics to their template; framework class statics (App::$routes, the
            // middleware stack, Store/Counter backends) are skipped via the snapshot.
            if (\ZealPHP\App::perRequestStateResetsActive()
                && \function_exists('zealphp_reset_request_class_statics')
            ) {
                (\zealphp_reset_request_class_statics(...))();
            }
        }
    }
}

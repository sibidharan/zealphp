<?php
namespace ZealPHP\Session;

use function ZealPHP\elog;
use function ZealPHP\bench_mode_enabled;
use function ZealPHP\uniqidReal;
use function ZealPHP\get_current_render_time;

use OpenSwoole\Coroutine as co;

use ZealPHP\Session\Handler\FileSessionHandler;
use ZealPHP\RequestContext;

/**
 * Per-coroutine session lifecycle manager (coroutine / `superglobals(false)` mode).
 *
 * Registered as the OpenSwoole `onRequest` handler in coroutine mode. For each
 * inbound request it:
 * 1. Creates a per-coroutine `RequestContext` (`G`) instance and populates
 *    `$g->openswoole_request` / `$g->openswoole_response` / `$g->zealphp_request` /
 *    `$g->zealphp_response`.
 * 2. Optionally starts a session (lazy — only when the client already holds a
 *    `PHPSESSID` cookie, unless `SessionStartMiddleware` is registered).
 * 3. Invokes the PSR-15 middleware stack (`$this->middleware`).
 * 4. Writes and closes the session in the `finally` block, then runs the
 *    per-request isolation resets for `coroutine-legacy` mode
 *    (`zealphp_reset_request_rtcaches`, `zealphp_reset_request_statics`,
 *    `zealphp_reset_request_class_statics`) when `App::$silent_redeclare` is active.
 *
 * The session handler is resolved once per worker via
 * `App::resolveActiveSessionHandler()` from `App::$session_handler`:
 * `null` (default, unconfigured) → the inline file path (NOT auto-promoted to
 * `TableSessionHandler` — #295 preserves the historical file default);
 * `'table'` → `TableSessionHandler` (concurrent-safe; opt in explicitly);
 * `'file'` → `FileSessionHandler`;
 * `'redis'` → `RedisSessionHandler`;
 * or any `\SessionHandlerInterface` instance passed directly.
 *
 * IMPORTANT: do not call `session_start()` / `session_write_close()` directly —
 * use the `zeal_session_*` uopz-overridden wrappers so state routes through
 * `$g->session` rather than the process-wide `$_SESSION`.
 */
class CoSessionManager
{
    /**
     * The PSR-15 middleware stack entry point (wrapped as a callable).
     *
     * @var callable
     */
    protected $middleware;

    /**
     * Session-id generator: either a callable or the string name of a built-in
     * function such as `'session_create_id'`.
     *
     * @var string|callable
     */
    protected $idGenerator;

    /** Whether to read/write the session id via a cookie (`session.use_cookies`). */
    protected bool $useCookies;

    /** Whether to refuse session ids passed in query-string (`session.use_only_cookies`). */
    protected bool $useOnlyCookies;

    /**
     * Returns `true` when it is safe to run `zealphp_process_state_clean()`.
     *
     * The cleanup is unsafe when a Composer autoloader maps classes inside
     * `App::$document_root` — those lazily-loaded classes would have their
     * statics reset while the `require_once` cache still considers them loaded,
     * causing a mismatch on the next request. When such an autoloader is
     * detected, cleanup is skipped for the whole worker.
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
     * Inject the middleware stack callable and session configuration.
     *
     * @param callable         $middleware      The PSR-15 stack entry point — called with
     *                                          `(ZealPHP\HTTP\Request, ZealPHP\HTTP\Response)`.
     * @param string|callable  $idGenerator     Session-id generator; defaults to `'session_create_id'`.
     * @param bool|null        $useCookies      Override `session.use_cookies`; `null` reads the ini.
     * @param bool|null        $useOnlyCookies  Override `session.use_only_cookies`; `null` reads the ini.
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
     * Handle one HTTP request: set up `RequestContext`, optionally start the
     * session, invoke the middleware stack, then close the session and run
     * per-request isolation resets in the `finally` block.
     *
     * Called directly by OpenSwoole's `onRequest` event — do not call manually.
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

        // #332 — claim live-superglobal OWNERSHIP for this request coroutine
        // as early as possible (ext-zealphp 0.3.36+), so a `go()` child spawned
        // anywhere in the request — including this preamble's own elog paths,
        // whose async log channel spawns a coroutine on first use — can't
        // snapshot-and-steal the request's superglobals on its first yield.
        // The OnRequest populate claims again after writing $GLOBALS (same
        // owner, idempotent). No-op without the ext function.
        if (\ZealPHP\App::$coroutine_isolated_superglobals
            && \function_exists('zealphp_superglobals_owner')
        ) {
            (\zealphp_superglobals_owner(...))();
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
            // #295 — wire the configured handler into THIS request's per-coroutine
            // session_params so zeal_session_start()/write_close() actually use it.
            // Resolution is memoised per worker (App::resolveActiveSessionHandler);
            // this is just a per-request array assignment. The previous once-per-worker
            // `@session_set_save_handler($h)` was a no-op for the zeal_session_*
            // overrides, so the handler never reached session_params and sessions
            // silently fell back to the inline file path. Unconfigured (null) keeps
            // that file default — the read sites fall back to it via the same resolver.
            $activeHandler = \ZealPHP\App::resolveActiveSessionHandler();
            if ($activeHandler !== null) {
                $g->session_params['handler'] = $activeHandler;
            }

            $sessionName = zeal_session_name();
            // #305 — parse the raw Cookie header PHP-canonically (OpenSwoole's
            // own cookie parser is disabled via http_parse_cookie=false). Writes
            // the parsed map back onto $request->cookie, so the Request wrapper
            // created below (and the superglobal populate) inherit it.
            $reqCookie = \ZealPHP\App::requestCookieMap($request);
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

                // #244 session.use_strict_mode: this branch is reached ONLY for a
                // client-supplied id, so an id with NO BACKING STORE ENTRY
                // (stale / foreign / never-issued) must not be honoured — mint a
                // fresh server-generated id so a fixated id can't become an
                // authed session. zeal_session_start() just read the store and
                // recorded entry existence in session_params['session_existed']
                // (ext-zealphp#2: an issued-but-empty session is a KNOWN id and
                // must NOT rotate — the old data-emptiness heuristic rotated it
                // on every data-less request).
                $coStoreExists = $g->session_params['session_existed'] ?? null;
                if (zeal_session_strict_should_regenerate(
                    \ZealPHP\App::$session_strict_mode,
                    true,
                    $g->session,
                    is_bool($coStoreExists) ? $coStoreExists : null
                )) {
                    $freshId = session_create_id();
                    if (is_string($freshId)) {
                        $sessionId = $freshId;
                        zeal_session_id($sessionId);
                        // ext-zealphp#2 — keep write_close's canonical sid slot
                        // in sync, or every write this request makes lands in
                        // the rejected OLD id's store (same desync class as the
                        // zeal_session_regenerate_id() fix).
                        $params = $g->session_params;
                        $params['session_id'] = $sessionId;
                        $g->session_params = $params;
                    }
                }

                if ($this->useCookies) {
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

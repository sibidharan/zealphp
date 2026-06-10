<?php

namespace ZealPHP;

use ZealPHP\App;

/**
 * Per-request state container. Lives on `Coroutine::getContext()` in
 * coroutine mode (recommended default) so each request gets isolated
 * state freed automatically when the coroutine ends. In legacy
 * superglobals mode it's a process-wide singleton bridging declared
 * properties to PHP's `$_GET` / `$_POST` / `$_SESSION` etc.
 *
 * Previously named `G` — that name remains available via `class_alias`
 * at the bottom of this file for backward compatibility. New code
 * should reference `RequestContext`.
 */
class RequestContext
{
    private static ?self $instance = null;

    // Declared properties bypass __get/__set — direct slot access (~2ns vs
    // ~50ns through magic methods). This is the entire property contract:
    // any undeclared write is a typo or a misuse and is rejected by __set.
    /** @var array<string, scalar|null> */
    public array $server = [];
    /** @var array<string, mixed> */
    public array $get = [];
    /** @var array<string, mixed> */
    public array $post = [];
    /** @var array<string, mixed> */
    public array $request = [];
    /** @var array<string, mixed> */
    public array $cookie = [];
    /** @var array<string, mixed> */
    public array $files = [];
    /** @var array<string, mixed> */
    public array $session = [];
    /**
     * Keys present in `$g->session` at session-load time. Lets
     * `zeal_session_write_close()` distinguish an in-request `unset()` (key was
     * loaded then removed → must be deleted from the store) from a concurrent
     * add (key never loaded here → must be preserved through the merge). `#21`.
     * @var list<string>
     */
    public array $session_loaded_keys = [];
    /** @var array<string, mixed> */
    public array $session_params = [];
    public ?int $status = null;
    /**
     * Raw status-line override (#327): set ONLY by the `header("HTTP/1.1
     * <code> <reason>")` form, which Apache mod_php forwards verbatim —
     * code AND reason — even for codes outside 100–599. The vendor PSR-7
     * `withStatus()` throws on out-of-table codes, so the raw pair rides
     * these side-channel fields and `App::emitEffectiveStatus()` overrides
     * the wire status at the emit chokepoints. Any later explicit status
     * set (`http_response_code()`, `Status:` header, int return) clears
     * them via `response_set_status()` — last write wins, like mod_php.
     */
    public ?int $raw_status_code = null;
    public ?string $raw_status_reason = null;
    public ?bool $_streaming = null;
    public ?bool $_session_started = null;
    /**
     * Per-request CGI backend override set by the matched route's `backend:`
     * option in `ResponseMiddleware::dispatchRoute()`, read by `App::include()`
     * to pick the dispatch strategy (`pool`/`proc`/`fork`/`fcgi` + interpreter/
     * address) for THIS request's includes. `null` = no override (fall back to
     * `App::resolveCgiBackend()` / the global `App::cgiMode()`).
     * @var array{mode:string, interpreter?:string, address?:string, fcgi_params?:array<string,string>}|null
     */
    public ?array $cgi_backend_override = null;
    /** @var \ZealPHP\HTTP\Request|null In tests, this slot may hold a mock — see `tests/Unit/RestTest.php` */
    public mixed $zealphp_request = null;
    /** @var \ZealPHP\HTTP\Response|null In tests, this slot may hold a mock — see `tests/Unit/RestTest.php` */
    public mixed $zealphp_response = null;
    /** @var \OpenSwoole\Http\Request|null In tests, this slot may hold a mock — see `tests/Unit/RestTest.php` */
    public mixed $openswoole_request = null;
    /** @var \OpenSwoole\Http\Response|null In tests, this slot may hold a mock — see `tests/Unit/RestTest.php` */
    public mixed $openswoole_response = null;
    /** @var \Psr\Http\Message\ServerRequestInterface|null The PSR-7 request for the current dispatch; set by `ResponseMiddleware::process()` so ZealAPI (and other inner layers) can reach the same object the middleware stack used. */
    public mixed $psr_request = null;
    // Legacy Apache mod_php shim state — only populated by the `apache_*()`
    // functions in `src/utils.php`, used by CGI bridge legacy code. Lazy.
    public ?\ZealPHP\Legacy\ApacheContext $apacheContext = null;
    public int $ignore_user_abort_state = 0;
    /** @var array<int, array{0: callable, 1: int}> stack of [callable, levels] */
    public array $error_handlers_stack = [];
    /** @var array<int, callable> stack of callables */
    public array $exception_handlers_stack = [];

    /** Re-entry guards: set true while inside the native dispatcher
     * closure so a nested error/exception fired while running the
     * user-supplied callable falls back to PHP's default handler
     * instead of recursing through our handler until the call stack
     * is exhausted. */
    public bool $_error_handler_in_flight = false;
    public bool $_exception_handler_in_flight = false;
    /** @var array<int, array{0: callable, 1: array<int|string, mixed>}> queue of [callable, args] */
    public array $shutdown_functions = [];
    public ?int $error_reporting_level = null;
    public ?int $error_status = null;
    public ?\Throwable $error_exception = null;
    public int $error_render_depth = 0;
    // Session shim state — previously stored as dynamic properties.
    public ?int $cache_expire = null;
    public ?string $cache_limiter = null;
    public ?string $session_module_name = null;
    // Per-request memoization scratch space — back-end for once() / has() / forget().
    // Keyed by caller-chosen string. Lifetime matches RequestContext (per coroutine
    // in coroutine mode, per request in superglobals mode after the manager resets).
    /** @var array<string, mixed> */
    public array $memo = [];

    private function __construct()
    {
    }

    public static function instance(): self
    {
        if (!App::$superglobals || App::$coroutine_isolated_superglobals) {
            $cid = \OpenSwoole\Coroutine::getCid();
            if ($cid >= 0) {
                $context = \OpenSwoole\Coroutine::getContext($cid);
                if (!isset($context['__g'])) {
                    // #42 — child-coroutine inheritance. A `go()` child (or an
                    // App::parallel task) is a NEW coroutine with a fresh
                    // OpenSwoole context, so a naive lookup here minted an EMPTY
                    // RequestContext: every deep `$g->server[*]` read inside the
                    // child returned null while the request's real context sat
                    // one level up (observed in the wild as render-time
                    // HTTP_HOST/PHP_SELF/DOCUMENT_ROOT = '' — issue #42's
                    // two-key `UNIQUE_ID,REQUEST_URI` signature is an app logger
                    // partially repopulating that fresh instance). Walk the
                    // parent-coroutine chain and ADOPT the nearest ancestor's
                    // instance — same object, so the child shares the request's
                    // state exactly like nested includes share it in plain PHP
                    // (one request = one $g). Depth-capped; coroutines spawned
                    // outside a request (onWorkerStart, service runners) find no
                    // ancestor instance and keep today's fresh-instance path.
                    // Adoption is lazy: only code that actually calls
                    // instance() in a child pays the walk, once.
                    $pcid = $cid;
                    for ($depth = 0; $depth < 32; $depth++) {
                        $pcid = \OpenSwoole\Coroutine::getPcid($pcid);
                        if ($pcid <= 0) {
                            break;
                        }
                        $pctx = \OpenSwoole\Coroutine::getContext($pcid);
                        if ($pctx !== null && isset($pctx['__g'])) {
                            $context['__g'] = $pctx['__g'];
                            break;
                        }
                    }
                }
                if (!isset($context['__g'])) {
                    $context['__g'] = new self();
                }
                $instance = $context['__g'];
                assert($instance instanceof self);
                return $instance;
            }
        }
        if (self::$instance === null) {
            $singleton = new self();
            if (App::$superglobals) {
                self::bridgeSuperglobalSlots($singleton);
            }
            self::$instance = $singleton;
        }
        return self::$instance;
    }

    /**
     * #346 — Apache/mod_php (and any non-OpenSwoole SAPI) bridge: a DECLARED,
     * default-initialized typed property ("public array $server = []") is
     * "set", so reads resolve from the empty slot and the __get superglobals
     * proxy NEVER runs — $g->server / $g->get / $g->request were always []
     * under plain Apache even though the SAPI populated $_SERVER/$_GET
     * correctly. Unsetting the request-input slots makes reads AND writes
     * route through __get/__set, which proxy to $GLOBALS['_SERVER'] etc. by
     * reference — the same live-alias contract the ZealPHP server's OnRequest
     * populate establishes per request (there this unset is simply
     * idempotent; under Apache it is the only place that can establish the
     * bridge, because no ZealPHP request lifecycle ever runs). Applied to the
     * process-wide singleton at construction when `App::$superglobals` is on.
     */
    private static function bridgeSuperglobalSlots(self $instance): void
    {
        unset(
            $instance->get,
            $instance->post,
            $instance->cookie,
            $instance->files,
            $instance->server,
            $instance->request,
            $instance->session,
            $instance->env
        );
    }

    /**
     * Read by reference is only required in superglobals mode, where the
     * proxy must hand back `$GLOBALS['_SESSION']` etc. so legacy code that
     * mutates `$_SESSION['k'] = $v` carries the write through. In coroutine
     * mode (recommended default) all reads go through the typed properties
     * declared above; returning by value avoids the autovivification footgun
     * where `&$g->nonexistent` would create a property on first read.
     *
     * @param string $key
     * @return mixed
     */
    public function &__get($key)
    {
        if (App::$superglobals) {
            if (in_array($key, ['get', 'post', 'cookie', 'files', 'server', 'request', 'env', 'session'], true)) {
                $superglobalKey = '_' . strtoupper($key);
                // v0.2.27 — initialize to [] not null. The superglobals are
                // array-typed; returning null here would cause `$g->session[$k]`
                // to fatal-error on null array access when read before any
                // session_*() call. Apache mod_php behaviour: superglobals
                // are always arrays once populated.
                if (!isset($GLOBALS[$superglobalKey])) {
                    $GLOBALS[$superglobalKey] = [];
                }
                return $GLOBALS[$superglobalKey];
            }
            return $GLOBALS[$key];
        }
        // Coroutine mode: typed properties are the contract. An undeclared
        // read is a bug in the caller — surface it instead of silently
        // creating a dynamic property. PHP emits an undefined-property
        // notice automatically when the key is missing.
        //
        // After unset() on a declared typed property the slot is "uninitialized";
        // reading it by ref would throw "must not be accessed before initialization".
        // We return a ref to a local null in that case, matching the missing-key
        // behavior — callers see the same null, regardless of how the slot got there.
        $null = null;
        $ref =& $null;
        if (property_exists($this, $key) && isset($this->$key)) {
            $ref =& $this->$key;
        } elseif (in_array($key, ['get', 'post', 'cookie', 'files', 'server', 'request', 'env', 'session'], true)) {
            // The slot is an unset typed-`array` superglobal property — e.g.
            // `session` before any session_*() call, or after CoSessionManager
            // unset() it under sessionLifecycle(false). PHP type-checks a by-ref
            // __get return against the unset typed property, so handing back the
            // `null` above would TypeError ("null … must be compatible with …
            // type array") and 500 the request — notably the access-log path
            // reading `$g->session['username']` (issue #164). Return an empty
            // array by ref WITHOUT initializing the property: the value is now
            // array-compatible AND `isset($g->session)` stays false, which the
            // session-state detection in Session/utils.php depends on. Mirrors
            // the superglobals-mode branch above, which likewise hands back [].
            $empty = [];
            $ref =& $empty;
        }
        return $ref;
    }

    /**
     * `__set` fires for undeclared properties AND for declared typed properties
     * that have been `unset()` (the slot is "uninitialized" so direct access
     * routes through `__set` on assignment). In superglobals mode we keep the
     * legacy bridge to `$GLOBALS[$key]` so pre-coroutine code that stashed
     * values via `$g->custom = $val` keeps working. In coroutine mode the
     * typed properties are the contract; we re-initialize the declared slot
     * (preserves PHP's type-check via direct property assignment) and reject
     * any other write loudly so typos still surface.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function __set($key, $value)
    {
        if (App::$superglobals) {
            // v0.2.27 — symmetric with __get's superglobal-key mapping.
            // Writing $g->session = $newArray in superglobals mode must hit
            // $GLOBALS['_SESSION'], not $GLOBALS['session'] (which would
            // create a useless global named "session"). The pre-v0.2.27
            // mapping silently dropped writes for the seven superglobal
            // names; now they round-trip correctly through both __get and
            // __set when the declared typed property has been unset() (e.g.,
            // by SessionManager establishing the $g->session ↔ $_SESSION
            // alias). All other keys still go to $GLOBALS[$key] for legacy
            // `$g->custom = $val` patterns.
            if (in_array($key, ['get', 'post', 'cookie', 'files', 'server', 'request', 'env', 'session'], true)) {
                $GLOBALS['_' . strtoupper($key)] = $value;
                return;
            }
            $GLOBALS[$key] = $value;
            return;
        }
        if (property_exists($this, $key)) {
            // Declared-but-unset slot: direct write bypasses __set and re-inits.
            $this->$key = $value;
            return;
        }
        throw new \BadMethodCallException(
            "Undeclared property '\$g->{$key}'. In coroutine mode, only "
            . "typed properties on " . self::class . " may be set. "
            . "Either use a declared property or add a new one to the class."
        );
    }

    /**
     * @param string $key
     * @return mixed
     */
    public static function get($key)
    {
        return self::instance()->$key;
    }

    /**
     * @param string $key
     * @param mixed  $value
     */
    public static function set($key, $value): void
    {
        self::instance()->$key = $value;
    }

    /**
     * Compute once per request, cache for the rest of the request.
     *
     * Safe alternative to `static $cache = []` inside a function. Computes
     * `$fn()` the first time it's called with `$key` in this request, caches
     * the result on the per-coroutine `RequestContext`, returns the cached
     * value on subsequent calls. The cache is freed automatically when the
     * coroutine ends — no state survives to the next request.
     *
     * Mirrors Laravel 11's `once()` helper. Use this anywhere you'd reach
     * for `static $foo = ...` for request-scoped memoization but want to
     * avoid leaking state into worker process memory.
     *
     * ```
     * $user = RequestContext::once('current_user', fn() => Auth::loadUser($id));
     * ```
     */
    public static function once(string $key, callable $fn): mixed
    {
        $ctx = self::instance();
        if (!array_key_exists($key, $ctx->memo)) {
            $ctx->memo[$key] = $fn();
        }
        return $ctx->memo[$key];
    }

    /**
     * True if `once($key, ...)` has been computed in this request.
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::instance()->memo);
    }

    /**
     * Discard the memoized value for `$key` in this request. The next `once()`
     * call with the same key will recompute.
     */
    public static function forget(string $key): void
    {
        unset(self::instance()->memo[$key]);
    }
}

// Backward-compatible alias: `\ZealPHP\G` was the original name. Existing
// code that references `G::instance()` or types against `\ZealPHP\G`
// continues to work without changes. New code should use `RequestContext`.
class_alias(RequestContext::class, 'ZealPHP\\G');

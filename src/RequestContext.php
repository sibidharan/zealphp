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
 * Previously named `G` — that name remains available via class_alias
 * at the bottom of this file for backward compatibility. New code
 * should reference `RequestContext`.
 */
class RequestContext
{
    private static ?self $instance = null;

    // Declared properties bypass __get/__set — direct slot access (~2ns vs
    // ~50ns through magic methods). This is the entire property contract:
    // any undeclared write is a typo or a misuse and is rejected by __set.
    /** @var array<string, mixed> */
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
    /** @var array<string, mixed> */
    public array $session_params = [];
    public ?int $status = null;
    public ?bool $_streaming = null;
    public ?bool $_session_started = null;
    public mixed $zealphp_request = null;
    public mixed $zealphp_response = null;
    public mixed $openswoole_request = null;
    public mixed $openswoole_response = null;
    // Legacy Apache mod_php shim state — only populated by the apache_*()
    // functions in src/utils.php, used by CGI bridge legacy code. Lazy.
    public ?\ZealPHP\Legacy\ApacheContext $apacheContext = null;
    public int $ignore_user_abort_state = 0;
    /** @var array<int, array{0: callable, 1: int}> stack of [callable, levels] */
    public array $error_handlers_stack = [];
    /** @var array<int, callable> stack of callables */
    public array $exception_handlers_stack = [];
    /** @var array<int, array{0: callable, 1: array<int, mixed>}> queue of [callable, args] */
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
        if (!App::$superglobals) {
            $cid = \OpenSwoole\Coroutine::getCid();
            if ($cid >= 0) {
                $context = \OpenSwoole\Coroutine::getContext($cid);
                if (!isset($context['__g'])) {
                    $context['__g'] = new self();
                }
                $instance = $context['__g'];
                assert($instance instanceof self);
                return $instance;
            }
        }
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
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
                if (!isset($GLOBALS[$superglobalKey])) {
                    $GLOBALS[$superglobalKey] = null;
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
        }
        return $ref;
    }

    /**
     * __set fires for undeclared properties AND for declared typed properties
     * that have been unset() (the slot is "uninitialized" so direct access
     * routes through __set on assignment). In superglobals mode we keep the
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
     * the result on the per-coroutine RequestContext, returns the cached
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
     * True if once($key, ...) has been computed in this request.
     */
    public static function has(string $key): bool
    {
        return array_key_exists($key, self::instance()->memo);
    }

    /**
     * Discard the memoized value for $key in this request. The next once()
     * call with the same key will recompute.
     */
    public static function forget(string $key): void
    {
        unset(self::instance()->memo[$key]);
    }
}

// Backward-compatible alias: `\ZealPHP\G` was the original name. Existing
// code that references `G::instance()` or types against `\ZealPHP\G`
// continues to work without changes. New code should use RequestContext.
class_alias(RequestContext::class, 'ZealPHP\\G');

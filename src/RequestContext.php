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
    private static $instance = null;

    // Declared properties bypass __get/__set — direct slot access (~2ns vs
    // ~50ns through magic methods). This is the entire property contract:
    // any undeclared write is a typo or a misuse and is rejected by __set.
    public array $server = [];
    public array $get = [];
    public array $post = [];
    public array $request = [];
    public array $cookie = [];
    public array $files = [];
    public array $session = [];
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
    public array $error_handlers_stack = [];     // stack of [callable, levels]
    public array $exception_handlers_stack = []; // stack of callables
    public array $shutdown_functions = [];       // queue of [callable, args]
    public ?int $error_reporting_level = null;
    public ?int $error_status = null;
    public ?\Throwable $error_exception = null;
    public int $error_render_depth = 0;
    // Session shim state — previously stored as dynamic properties.
    public ?int $cache_expire = null;
    public ?string $cache_limiter = null;
    public ?string $session_module_name = null;

    private function __construct()
    {
    }

    public static function instance()
    {
        if (!App::$superglobals) {
            $cid = \OpenSwoole\Coroutine::getCid();
            if ($cid >= 0) {
                $context = \OpenSwoole\Coroutine::getContext($cid);
                if (!isset($context['__g'])) {
                    $context['__g'] = new self();
                }
                return $context['__g'];
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
        $null = null;
        $ref =& $null;
        if (property_exists($this, $key)) {
            $ref =& $this->$key;
        }
        return $ref;
    }

    /**
     * __set fires only for undeclared properties — declared typed slots
     * bypass it entirely (PHP semantics). In superglobals mode we keep the
     * legacy bridge to `$GLOBALS[$key]` so pre-coroutine code that stashed
     * values via `$g->custom = $val` keeps working. In coroutine mode the
     * typed properties are the contract; an undeclared write is a typo and
     * is rejected loudly.
     */
    public function __set($key, $value)
    {
        if (App::$superglobals) {
            $GLOBALS[$key] = $value;
            return;
        }
        throw new \BadMethodCallException(
            "Undeclared property '\$g->{$key}'. In coroutine mode, only "
            . "typed properties on " . self::class . " may be set. "
            . "Either use a declared property or add a new one to the class."
        );
    }

    public static function get($key)
    {
        return self::instance()->$key;
    }

    public static function set($key, $value)
    {
        self::instance()->$key = $value;
    }
}

// Backward-compatible alias: `\ZealPHP\G` was the original name. Existing
// code that references `G::instance()` or types against `\ZealPHP\G`
// continues to work without changes. New code should use RequestContext.
class_alias(RequestContext::class, 'ZealPHP\\G');

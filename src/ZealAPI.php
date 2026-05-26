<?php
namespace ZealPHP;
// error_reporting(E_ALL ^ E_DEPRECATED);

use ZealPHP\REST;
use ZealPHP\App;
use function ZealPHP\elog;
use function ZealPHP\jTraceEx;

use OpenSwoole\Core\Psr\Middleware\StackHandler;
use OpenSwoole\Core\Psr\Response;
use OpenSwoole\HTTP\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * File-based API dispatcher.
 *
 * URL convention — two dispatch modes
 * -----------------------------------
 *
 * **Mode 1 — filename match (all methods):**
 *   `/api/device/list`             → `api/device/list.php` defines `$list = function(...){...}`
 *   `/api/device/add`              → `api/device/add.php`  defines `$add  = function(...){...}`
 *   The closure accepts ALL HTTP methods. The handler reads
 *   `$this->get_request_method()` if it needs to differentiate.
 *
 * **Mode 2 — per-method dispatch (Next.js App Router style):**
 *   `/api/users`  GET              → `api/users.php` defines `$get    = function(...){...}`
 *   `/api/users`  POST             → `api/users.php` defines `$post   = function(...){...}`
 *   Undefined methods return 405 + `Allow` header. HEAD auto-derives from
 *   `$get`. OPTIONS lists defined methods automatically.
 *
 * Resolution: filename match takes priority. If `$list` exists in `list.php`,
 * it wins and any `$get`/`$post` in the same file are unreachable (warned).
 *
 * The variable name MUST match `basename($file, '.php')`. The closure is
 * `Closure::bind`'d to a `ZealAPI` instance, so inside the handler `$this` is the
 * `ZealAPI` object and you can call `$this->paramsExists()`, `$this->die()`, etc.
 *
 * Parameter injection (by name)
 * -----------------------------
 *   `$app`      → the `ZealAPI` instance
 *   `$request`  → `ZealPHP\HTTP\Request`
 *   `$response` → `ZealPHP\HTTP\Response`
 *   `$server`   → `OpenSwoole` server
 *   any other   → `null` (or its declared default value)
 *
 * Error responses
 * ---------------
 * All `ZealAPI` failures emit JSON with an `"error"` key and an HTTP status:
 *
 *   `400  invalid_module`        — path component fails the strict regex
 *   `400  invalid_request`       — method name contains slashes/dots/etc
 *   `404  method_not_found`      — file or expected variable name missing
 *   `404  undefined_method`      — handler called `$this->X()` but `X` is not a
 *                                 method on `ZealAPI`/`REST`. Response includes
 *                                 a `"hint"` and, if a close match is found via
 *                                 `levenshtein`, a `"did_you_mean"` suggestion:
 *
 *                                   `{ "error": "undefined_method",
 *                                     "method": "paramExist",
 *                                     "hint": "...Did you mean $this->paramsExists()?",
 *                                     "did_you_mean": "paramsExists" }`
 *
 *                                 Prior to this change, an undefined-method
 *                                 call inside the handler caused `__call` to
 *                                 re-invoke the same closure → infinite
 *                                 recursion. `processApi()` now dispatches the
 *                                 closure directly, so `__call` is only
 *                                 reached on real typos.
 *
 *   `500  (PHP exception)`       — uncaught throwable inside the handler;
 *                                 stack trace logged via `elog()`.
 */
class ZealAPI extends REST
{
    public string $data = "";
    /** @var array<string, array<int, \ReflectionParameter>> */
    private static array $reflectionCache = [];

    /** @var \Closure|null */
    private $api_rpc;
    /** @var mixed */
    public $_response = null;
    /** @var mixed */
    public $request = null;
    /** @var string|null */
    public $cwd = null;
    /** @var array<string, mixed>|null */
    private ?array $_undefinedMethodError = null;

    /**
     * @param mixed  $request
     * @param mixed  $response
     * @param string $cwd
     */
    public function __construct($request, $response, $cwd)
    {
        $this->cwd = $cwd;
        $this->_response = $response;
        $this->request = $request;
        parent::__construct($request, $response);                  // Init parent contructor
    }

    /*
    * Public method for access api.
    * This method dynmically call the method based on the query string
    *
    */
    /**
     * @param string      $module
     * @param string|null $request
     * @return mixed
     */
    public function processApi($module, $request=null)
    {
        $g = RequestContext::instance();
        $module = $module ? '/'.$module : '';
        $func = basename($request ?? '');

        if ($module !== '' && !preg_match('/^\/[a-zA-Z0-9_\/-]+$/', $module)) {
            $this->response($this->json(['error' => 'invalid_module']), 400);
            return;
        }
        if ($request !== null && !preg_match('/^[a-zA-Z0-9_\-]+$/', $request)) {
            $this->response($this->json(['error' => 'invalid_request']), 400);
            return;
        }

        if ($module === '' && method_exists($this, $func)) {
            $this->$func();
        } else {
            if ($module !== '') {
                $dir = $this->cwd.'/api'.$module;
                // Apache parity (issue #18): DOCUMENT_ROOT is ALWAYS the web
                // root, never the /api subdirectory. Handlers that include
                // files relative to $_SERVER['DOCUMENT_ROOT'] (the mod_php
                // convention) must resolve against htdocs, not htdocs/api.
                $g->server['DOCUMENT_ROOT'] = App::resolveDocumentRoot();
                $file = $dir.'/'.$request.'.php';

                $apiBase = realpath($this->cwd . '/api');
                $realFile = realpath($file);
                if (!$realFile || !$apiBase || !str_starts_with($realFile, $apiBase . DIRECTORY_SEPARATOR)) {
                    $this->response($this->json(['error' => 'method_not_found']), 404);
                    return;
                }

                if (file_exists($realFile)) {
                    include $realFile;
                    $_vars = get_defined_vars();

                    // Resolution order:
                    // 1. Filename match ($list in list.php) → all HTTP methods
                    // 2. Method variable ($get, $post, …) → per-method dispatch
                    // 3. No match → 405 or 404

                    $filenameHandler = ($_vars[$func] ?? null) instanceof \Closure ? $_vars[$func] : null;

                    $methodKeywords = ['get', 'post', 'put', 'delete', 'patch'];

                    if ($filenameHandler instanceof \Closure) {
                        if (\ZealPHP\App::$api_warn_collisions) {
                            if (in_array($func, $methodKeywords, true)) {
                                elog(
                                    "api{$module}/{$request}.php: filename '{$request}' collides with"
                                    . " HTTP method — \${$func} is treated as a filename match (all"
                                    . " methods reach it). To use per-method dispatch, rename the"
                                    . " file (e.g. api{$module}.php) and define \$get/\$post/… there.",
                                    'warning'
                                );
                            }
                            $unreachable = [];
                            foreach ($methodKeywords as $_m) {
                                if ($_m !== $func && ($_vars[$_m] ?? null) instanceof \Closure) {
                                    $unreachable[] = '$' . $_m;
                                }
                            }
                            if ($unreachable) {
                                elog(
                                    "api{$module}/{$request}.php: \${$func} (filename match) takes priority"
                                    . " — " . implode(', ', $unreachable) . " are unreachable."
                                    . " Remove \${$func} to enable per-method routing.",
                                    'warning'
                                );
                            }
                        }
                        $closureToBind = $filenameHandler;
                    } else {
                        /** @var array<string, \Closure> $methodHandlers */
                        $methodHandlers = [];
                        foreach (['get', 'post', 'put', 'delete', 'patch'] as $_m) {
                            $candidate = $_vars[$_m] ?? null;
                            if ($candidate instanceof \Closure) {
                                $methodHandlers[$_m] = $candidate;
                            }
                        }

                        if (empty($methodHandlers)) {
                            $this->response($this->json([
                                'error' => 'handler_not_found',
                                'hint'  => "api{$module}/{$request}.php exists but defines neither"
                                    . " \${$func} nor any method handler (\$get, \$post, \$put,"
                                    . " \$delete, \$patch). Define \${$func} = function() {…};"
                                    . " for a catch-all, or \$get/\$post/… for per-method dispatch.",
                            ]), 404);
                            elog(
                                "api{$module}/{$request}.php: no handler found — expected"
                                . " \${$func} or method closures (\$get, \$post, …)",
                                'warning'
                            );
                            return;
                        }

                        $rawMethod = $this->get_request_method();
                        $httpMethod = strtolower(is_string($rawMethod) ? $rawMethod : 'GET');

                        $allowMethods = array_map('strtoupper', array_keys($methodHandlers));
                        if (isset($methodHandlers['get'])) {
                            $allowMethods[] = 'HEAD';
                        }
                        $allowMethods[] = 'OPTIONS';
                        $allowMethods = array_values(array_unique($allowMethods));

                        if ($httpMethod === 'head' && isset($methodHandlers['get'])) {
                            $closureToBind = $methodHandlers['get'];
                        } elseif (isset($methodHandlers[$httpMethod])) {
                            $closureToBind = $methodHandlers[$httpMethod];
                        } else {
                            response_add_header('Allow', implode(', ', $allowMethods));
                            $this->response($this->json([
                                'error' => 'method_not_allowed',
                                'allowed' => $allowMethods,
                            ]), 405);
                            return;
                        }
                    }

                    $this->api_rpc = \Closure::bind($closureToBind, $this, get_class($this));
                    // Apache parity (issue #18): the script path is rooted at
                    // the URL ('/api/<module>/<request>.php'), and
                    // SCRIPT_FILENAME is the absolute path mod_php would have
                    // resolved — i.e. the real handler file, not DOCUMENT_ROOT
                    // concatenated with a /api-less PHP_SELF.
                    $scriptName = '/api'.$module.'/'.$request.'.php';
                    $g->server['PHP_SELF']        = $scriptName;
                    $g->server['SCRIPT_NAME']     = $scriptName;
                    $g->server['SCRIPT_FILENAME'] = $realFile;
                    $handler = $this->api_rpc;
                    $cacheKey = $file . ':' . $func;
                    if (!isset(self::$reflectionCache[$cacheKey])) {
                        // $handler is always a bound Closure here (set above by
                        // \Closure::bind(${$func}, $this, get_class()) at line 124).
                        $reflection = new \ReflectionFunction($handler);
                        self::$reflectionCache[$cacheKey] = $reflection->getParameters();
                    }

                    $invokeArgs = [];
                    foreach (self::$reflectionCache[$cacheKey] as $param) {
                        $pname = $param->getName();
                        if ($pname == 'app'){
                            $invokeArgs[] = $this;
                        } else if ($pname == 'request'){
                            $invokeArgs[] = $this->request;
                        } else if ($pname == 'response'){
                            $invokeArgs[] = $this->_response;
                        } else if ($pname == 'server'){
                            $invokeArgs[] = App::$server;
                        } else {
                            $invokeArgs[] = $param->isDefaultValueAvailable()
                                ? $param->getDefaultValue()
                                : null;
                        }
                    }
                    ob_start();
                    // Invoke the closure directly. It was Closure::bind'd to
                    // $this above, so $this inside the closure is still the
                    // ZealAPI instance. Going through $this->$func(...) instead
                    // would round-trip through __call, and a typo inside the
                    // closure (e.g. $this->paramExist vs $this->paramsExists)
                    // would then proxy back to the same closure → infinite loop.
                    try {
                        $object = $handler(...$invokeArgs);
                    } catch (\BadMethodCallException $e) {
                        // __call collected the structured error in
                        // $this->_undefinedMethodError before throwing.
                        ob_end_clean();
                        if (!empty($this->_undefinedMethodError)) {
                            response_add_header('Content-Type', 'application/json');
                            return new Response($this->json($this->_undefinedMethodError), 404);
                        }
                        throw $e;
                    }
                    // If the handler already sent a response (via $this->response(),
                    // $response->sse(), or similar), the output buffer is empty and
                    // we should NOT create a second Response — that would corrupt
                    // streaming connections (SSE, chunked).
                    if ($g->_streaming ?? false) {
                        ob_end_clean();
                        return;
                    }

                    if(is_int($object)){
                        $status = (int)$object;
                    } else {
                        $status = $g->status ?? 200;;
                    }

                    if($object instanceof ResponseInterface){
                        return $object;
                    }

                    if ($object instanceof \Generator) {
                        ob_end_clean();
                        return $object;
                    }

                    if(is_array($object) or is_object($object)){
                        response_add_header('Content-Type', 'application/json');
                        echo json_encode($object, JSON_PRETTY_PRINT);
                    } else if (is_string($object)){
                        echo $object;
                    }

                    $buffer = ob_get_clean();

                    return (new Response($buffer, $status));
                    
                } else {
                    $this->response($this->json(['error'=>'method_not_found']), 404);
                }
            } else {
                //we can even process functions without module here.
                $this->response($this->json(['error'=>'method_not_found']), 404);
            }
        }
    }

    /**
     * Whether the current request is authenticated.
     *
     * Consults the callback registered with `App::authChecker()`. Without
     * one, returns `false` (safe fail-closed default). The callback shape is
     * `fn(): bool` — typically reads `$_SESSION`, `$g->session`, or your
     * auth system's own state.
     *
     * See issue `#13`. Earlier versions hardcoded `return false;`, breaking
     * every endpoint guarded by `requirePostAuth()`.
     */
    public function isAuthenticated(): bool
    {
        $checker = App::authChecker();
        return $checker !== null ? (bool) $checker() : false;
    }

    /**
     * Checks if all supplied parameters exists
     *
     * @param array<int, string> $parms Http Parameters
     * @return bool
     */
    public function paramsExists($parms = array())
    {
        $exists = true;
        $req = is_array($this->_request) ? $this->_request : [];
        foreach ($parms as $param) {
            if (!array_key_exists($param, $req)) {
                $exists = false;
            }
        }
        return $exists;
    }

    /**
     * Whether the current user is an admin. Consults the callback
     * registered with `App::adminChecker()` — `fn(): bool` — or returns
     * `false` if none. See `isAuthenticated()` for the design.
     */
    public function isAdmin(): bool
    {
        $checker = App::adminChecker();
        return $checker !== null ? (bool) $checker() : false;
    }

    /**
     * The current user's display name (or null when unauthenticated).
     * Consults the callback registered with `App::usernameProvider()` —
     * `fn(): ?string` — or returns `null` if none.
     */
    public function getUsername(): ?string
    {
        $provider = App::usernameProvider();
        if ($provider === null) {
            return null;
        }
        $name = $provider();
        return is_string($name) ? $name : null;
    }

    /**
     * POST + authenticated guard. Returns false and sends 403 if check fails.
     */
    public function requirePostAuth(): bool
    {
        if ($this->get_request_method() !== "POST" || !$this->isAuthenticated()) {
            $this->response($this->json(["error" => "Unauthorized"]), 403);
            return false;
        }
        return true;
    }

    /**
     * @param \Throwable $e
     */
    public function die($e): void
    {
        $data = [
            "error" => $e->getMessage(),
            "stack" => jTraceEx($e),
            "type" => "exception"
        ];
        elog(jTraceEx($e), "error");
        $response_code = 400;
        if ($e->getMessage() == "Expired token" || $e->getMessage() == "Unauthorized") {
            $response_code = 403;
        }

        if ($e->getMessage() == "Not found") {
            $response_code = 404;
        }
        $data = $this->json($data);
        $this->response($data, $response_code);
    }

    /**
     * Catch missing-method calls from inside an API handler closure (e.g. a typo
     * like `$this->paramExist` instead of `$this->paramsExists`).
     *
     * Previously this proxied to `$this->api_rpc` — but `api_rpc` IS the closure
     * we're currently executing, so the proxy re-invoked it and infinitely
     * recursed until stack overflow. `processApi()` now invokes the closure
     * directly, so `__call` is only reached on actual typos. Surface the typo
     * loudly with a "did you mean" hint so developers don't waste time
     * staring at `"method_not_callable"` wondering what's wrong.
     *
     * @param string             $method
     * @param array<int, mixed>  $args
     * @return mixed
     */
    public function __call($method, $args)
    {
        $available = get_class_methods($this);
        $suggestion = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($available as $candidate) {
            if (str_starts_with($candidate, '__')) continue;
            $d = levenshtein(strtolower($method), strtolower($candidate));
            if ($d < $bestDistance) {
                $bestDistance = $d;
                $suggestion = $candidate;
            }
        }
        // Only suggest when the typo is plausibly close (≤3 edits and ≤40% of name length)
        $closeEnough = $suggestion !== null
            && $bestDistance <= 3
            && $bestDistance <= max(1, (int) floor(strlen($method) * 0.4));

        $error = [
            'error'  => 'undefined_method',
            'method' => $method,
            'hint'   => "No method ZealPHP\\ZealAPI::{$method}() exists. "
                      . ($closeEnough
                          ? "Did you mean \$this->{$suggestion}()?"
                          : 'Check the method name against the ZealAPI/REST class — or define it in your handler file.'),
        ];
        if ($closeEnough) {
            $error['did_you_mean'] = $suggestion;
        }
        elog(
            "ZealAPI: undefined method \$this->{$method}() called from API handler"
            . ($closeEnough ? " — did you mean \$this->{$suggestion}()?" : ''),
            'error'
        );
        // Stash the structured error and throw — processApi catches this and
        // emits a clean 404 JSON response. Throwing (rather than just
        // $this->response()) short-circuits the rest of the closure body, so a
        // typo in a guard clause can't fall through into the success path.
        $this->_undefinedMethodError = $error;
        throw new \BadMethodCallException("ZealAPI: undefined method \$this->{$method}()");
    }

    /**
     * Resolve the canonical "club" identifier from the current request,
     * accepting either `club` (the new name) or `group` (the legacy alias
     * still used by older client code). Returns whatever the request
     * payload carries — typically a string id — or null when neither key
     * is present.
     */
    public function resolveClubParam(): mixed
    {
        $req = is_array($this->_request) ? $this->_request : [];
        return $req['club'] ?? $req['group'] ?? null;
    }

    /**
     * Shorthand for emitting a 400 JSON error from a caught Throwable.
     * Mirrors the `{"error": "<message>"}` envelope every other ZealAPI
     * error path uses, so client code can rely on one error shape.
     */
    public function failAs(\Throwable $e): void
    {
        $this->response($this->json(["error" => $e->getMessage()]), 400);
    }

    /**
     * @param mixed $data
     * @return string
     */
    public function json($data)
    {
        if (is_array($data)) {
            return (string)json_encode($data, JSON_PRETTY_PRINT);
        } else {
            return "{}";
        }
    }
}

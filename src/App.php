<?php
namespace ZealPHP;

use ZealPHP\ZealAPI;
use ZealPHP\Session;
use function ZealPHP\elog;
use function ZealPHP\jTraceEx;
use function ZealPHP\response_add_header;
use function ZealPHP\response_set_status;

use OpenSwoole\Core\Psr\Middleware\StackHandler;
use OpenSwoole\Core\Psr\Response;
use OpenSwoole\HTTP\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use OpenSwoole\Coroutine as co;
class App
{
    protected $routes = [];
    protected $routes_by_method = [];
    protected $routes_by_exact_method = [];
    protected $ws_routes = [];
    protected static $workerStartHooks = [];
    protected $host;
    protected $port;
    static $cwd;
    static $server;
    static $default_php_self;
    private static $instance = null;
    public static $display_errors = true;
    public static $superglobals = true;
    public static $middleware_stack = null;
    public static $middleware_wait_stack = [];
    public static $ignore_php_ext = true;
    public static $coproc_implicit_request_handler = false;
    /** Apache DirectorySlash equivalent — redirect `/foo` → `/foo/` when foo is a directory. */
    public static $directory_slash = true;
    /** Apache DirectoryIndex — file names tried in order when a directory is requested. */
    public static $directory_index = ['index.php', 'index.html', 'index.htm'];
    /** Apache PATH_INFO — when `/script.php/extra/path`, expose `/extra/path` as PATH_INFO. */
    public static $path_info = true;
    /** Static handler URL-prefix whitelist. Empty = serve any path under document_root (Apache default). */
    public static $static_handler_locations = [];
    /** Block any path containing a dotfile component (.git, .env, .htaccess, etc.). Apache convention. */
    public static $block_dotfiles = true;
    private static $fallback_handler = null;
    /** Initial error_reporting level captured at boot — referenced by the per-coroutine override. */
    public static int $initial_error_reporting = E_ALL;
    /** Status -> custom error handler registry (key 0 = catch-all). */
    private static array $error_handlers = [];
    private const REASON_PHRASES = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        418 => "I'm a teapot",
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    private function __construct($host = '0.0.0.0', $port = 8080,$cwd = __DIR__)
    {
        # if uopz not enabled, throw error
        if (!extension_loaded('uopz')) {
            throw new \Exception("uopz extension is required for ZealPHP to work, 'pecl install uopz' to install and load it in your php.ini");
        }
        $this->host = $host;
        $this->port = $port;
        self::$cwd = $cwd;

        //TODO: $_ENV - read from /etc/environment, make this optional?
        $_ENV = [];
        if (file_exists('/etc/environment')) {
            $env = file_get_contents('/etc/environment');
            $env = explode("\n", $env);
            foreach ($env as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) {
                    continue;
                }
                list($key, $value) = explode('=', $line, 2);
                $_ENV[$key] = $value;
            }
        }

        // Capture initial error_reporting BEFORE uopz overrides it (else our override
        // would self-recurse trying to read the "native" default).
        self::$initial_error_reporting = \error_reporting();

        // Install ONE process-level native error/exception handler before uopz
        // overrides. After uopz takes over set_error_handler / set_exception_handler,
        // user-space calls store handlers in G (per-coroutine). Real PHP errors
        // raised by the engine still go through THIS native dispatcher, which
        // reads the current coroutine's G stack — giving per-coroutine isolation.
        \set_error_handler(static function (int $severity, string $message, string $file, int $line) {
            $g = \ZealPHP\G::instance();
            $level = $g->error_reporting_level ?? \ZealPHP\App::$initial_error_reporting;
            if (!($severity & $level)) {
                return true; // suppressed by error_reporting
            }
            $stack = $g->error_handlers_stack;
            if (!empty($stack)) {
                $top = $stack[count($stack) - 1];
                [$callable, $levels] = $top;
                if ($severity & $levels) {
                    try {
                        return (bool)$callable($severity, $message, $file, $line);
                    } catch (\Throwable $e) {
                        // Avoid loops; let PHP default handle if user handler explodes.
                        return false;
                    }
                }
            }
            return false; // PHP default handler
        });

        \set_exception_handler(static function (\Throwable $e) {
            $g = \ZealPHP\G::instance();
            $stack = $g->exception_handlers_stack;
            if (!empty($stack)) {
                try {
                    $stack[count($stack) - 1]($e);
                } catch (\Throwable $e2) {
                    // swallow
                }
            }
        });

        // Built-ins always present in CLI/OpenSwoole — uopz can override directly.
        \uopz_set_return('header', \Closure::fromCallable('\ZealPHP\header'), true);
        \uopz_set_return('header_remove', \Closure::fromCallable('\ZealPHP\header_remove'), true);
        \uopz_set_return('headers_list', \Closure::fromCallable('\ZealPHP\headers_list'), true);
        \uopz_set_return('headers_sent', \Closure::fromCallable('\ZealPHP\headers_sent'), true);
        \uopz_set_return('setcookie', \Closure::fromCallable('\ZealPHP\setcookie') , true);
        \uopz_set_return('setrawcookie', \Closure::fromCallable('\ZealPHP\setrawcookie') , true);
        \uopz_set_return('http_response_code', \Closure::fromCallable('\ZealPHP\http_response_code'), true);
        \uopz_set_return('flush', \Closure::fromCallable('\ZealPHP\flush'), true);
        \uopz_set_return('ob_flush', \Closure::fromCallable('\ZealPHP\ob_flush'), true);
        \uopz_set_return('ob_end_flush', \Closure::fromCallable('\ZealPHP\ob_end_flush'), true);
        \uopz_set_return('ob_implicit_flush', \Closure::fromCallable('\ZealPHP\ob_implicit_flush'), true);
        \uopz_set_return('set_time_limit', \Closure::fromCallable('\ZealPHP\set_time_limit'), true);
        \uopz_set_return('ignore_user_abort', \Closure::fromCallable('\ZealPHP\ignore_user_abort'), true);
        \uopz_set_return('connection_status', \Closure::fromCallable('\ZealPHP\connection_status'), true);
        \uopz_set_return('connection_aborted', \Closure::fromCallable('\ZealPHP\connection_aborted'), true);
        \uopz_set_return('output_add_rewrite_var', \Closure::fromCallable('\ZealPHP\output_add_rewrite_var'), true);
        \uopz_set_return('output_reset_rewrite_vars', \Closure::fromCallable('\ZealPHP\output_reset_rewrite_vars'), true);
        \uopz_set_return('is_uploaded_file', \Closure::fromCallable('\ZealPHP\is_uploaded_file'), true);
        \uopz_set_return('move_uploaded_file', \Closure::fromCallable('\ZealPHP\move_uploaded_file'), true);
        // Per-coroutine error/exception/shutdown handler registry.
        \uopz_set_return('set_error_handler', \Closure::fromCallable('\ZealPHP\set_error_handler'), true);
        \uopz_set_return('restore_error_handler', \Closure::fromCallable('\ZealPHP\restore_error_handler'), true);
        \uopz_set_return('set_exception_handler', \Closure::fromCallable('\ZealPHP\set_exception_handler'), true);
        \uopz_set_return('restore_exception_handler', \Closure::fromCallable('\ZealPHP\restore_exception_handler'), true);
        \uopz_set_return('register_shutdown_function', \Closure::fromCallable('\ZealPHP\register_shutdown_function'), true);
        \uopz_set_return('error_reporting', \Closure::fromCallable('\ZealPHP\error_reporting'), true);
        // Apache-only built-ins (apache_*, getallheaders, virtual) are NOT defined
        // in CLI SAPI; uopz can't override what doesn't exist. They are registered
        // as global shims via src/apache_shims.php (composer files autoload) that
        // delegate to the same \ZealPHP\* namespaced implementations.
        \uopz_set_return('session_start', \Closure::fromCallable('\ZealPHP\Session\zeal_session_start'), true);
        \uopz_set_return('session_id', \Closure::fromCallable('\ZealPHP\Session\zeal_session_id'), true);
        \uopz_set_return('session_status', \Closure::fromCallable('\ZealPHP\Session\zeal_session_status'), true);
        \uopz_set_return('session_name', \Closure::fromCallable('\ZealPHP\Session\zeal_session_name'), true);
        \uopz_set_return('session_write_close', \Closure::fromCallable('\ZealPHP\Session\zeal_session_write_close'), true);
        \uopz_set_return('session_destroy', \Closure::fromCallable('\ZealPHP\Session\zeal_session_destroy'), true);
        \uopz_set_return('session_unset', \Closure::fromCallable('\ZealPHP\Session\zeal_session_unset'), true);
        \uopz_set_return('session_regenerate_id', \Closure::fromCallable('\ZealPHP\Session\zeal_session_regenerate_id'), true);
        \uopz_set_return('session_get_cookie_params', \Closure::fromCallable('\ZealPHP\Session\zeal_session_get_cookie_params'), true);
        \uopz_set_return('session_set_cookie_params', \Closure::fromCallable('\ZealPHP\Session\zeal_session_set_cookie_params'), true);
        \uopz_set_return('session_cache_limiter', \Closure::fromCallable('\ZealPHP\Session\zeal_session_cache_limiter'), true);
        \uopz_set_return('session_cache_expire', \Closure::fromCallable('\ZealPHP\Session\zeal_session_cache_expire'), true);
        \uopz_set_return('session_commit', \Closure::fromCallable('\ZealPHP\Session\zeal_session_commit'), true);
        \uopz_set_return('session_abort', \Closure::fromCallable('\ZealPHP\Session\zeal_session_abort'), true);
        \uopz_set_return('session_encode', \Closure::fromCallable('\ZealPHP\Session\zeal_session_encode'), true);
        \uopz_set_return('session_decode', \Closure::fromCallable('\ZealPHP\Session\zeal_session_decode'), true);
        \uopz_set_return('session_save_path', \Closure::fromCallable('\ZealPHP\Session\zeal_session_save_path'), true);
        \uopz_set_return('session_module_name', \Closure::fromCallable('\ZealPHP\Session\zeal_session_module_name'), true);
    }

    /**
     * Initializes the application.
     *
     * @param string $host The host address to bind to. Defaults to '0.0.0.0'.
     * @param int    $port The port number to bind to. Defaults to 8080.
     * @param string $cwd  The current working directory. Defaults to the directory of the script.
     *
     * @return App
     */
    public static function init($host = '0.0.0.0', $port = 8080, $cwd=null): App
    {
        if ($cwd === null) {
            $php_self = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1)[0]['file'];
            $file_name = '/'.basename($php_self);
            $cwd = dirname($php_self);
            self::$default_php_self = $file_name;
            self::$middleware_stack = (new StackHandler())->add(new ResponseMiddleware());
        }
        if(!App::$superglobals){
            co::set(['hook_flags'=> \OpenSwoole\Runtime::HOOK_ALL]);
            \OpenSwoole\Runtime::enableCoroutine(\OpenSwoole\Runtime::HOOK_ALL);
        }
        if (self::$instance == null) {
            self::$instance = new App($host, $port, $cwd);
        } else {
            elog("App already initialized", "warn");
        }
        return self::$instance;
    }

    public static function superglobals($enable = true){
        self::$superglobals = $enable;
    }

    public static function instance()
    {
        return self::$instance;
    }

    public function routes()
    {
        return $this->routes;
    }

    public function routesByMethod(): array
    {
        return $this->routes_by_method;
    }

    public function routesByExactMethod(): array
    {
        return $this->routes_by_exact_method;
    }

    protected function isExactRoutePath(string $path): bool
    {
        return preg_match('/[\\\\^$.|?*+()[\\]{}]/', $path) === 0;
    }

    /**
     * Register a WebSocket endpoint.
     *
     * @param string        $path      URI path, e.g. '/ws/chat'
     * @param callable      $onMessage function($server, $frame, $g) — called for each message
     * @param callable|null $onOpen    function($server, $request, $g) — called on connect
     * @param callable|null $onClose   function($server, $fd, $g)     — called on disconnect
     */
    public function ws(string $path, callable $onMessage, ?callable $onOpen = null, ?callable $onClose = null): void
    {
        $this->ws_routes[$path] = [
            'message' => $onMessage,
            'open'    => $onOpen,
            'close'   => $onClose,
        ];
    }

    public function wsRoutes(): array
    {
        return $this->ws_routes;
    }

    // -----------------------------------------------------------------------
    // Timer helpers (must be called inside a coroutine context: workerStart,
    // request handler, or onWorkerStart callback)
    // -----------------------------------------------------------------------

    /** Recurring timer: calls $fn every $ms milliseconds in this worker. */
    public static function tick(int $ms, callable $fn): int
    {
        return \OpenSwoole\Timer::tick($ms, $fn);
    }

    /** One-shot timer: calls $fn once after $ms milliseconds. */
    public static function after(int $ms, callable $fn): int
    {
        return \OpenSwoole\Timer::after($ms, $fn);
    }

    /** Cancel a timer returned by tick() or after(). */
    public static function clearTimer(int $id): void
    {
        \OpenSwoole\Timer::clear($id);
    }

    /**
     * Register a callback to run inside every worker's workerStart event.
     * Use this to start per-worker timers, warm caches, open connections, etc.
     * Called as: $fn($server, $workerId)
     */
    public static function onWorkerStart(callable $fn): void
    {
        self::$workerStartHooks[] = $fn;
    }

    private function buildParamMap($handler): array
    {
        try {
            $reflection = is_array($handler)
                ? new \ReflectionMethod($handler[0], $handler[1])
                : new \ReflectionFunction($handler);
            $map = [];
            foreach ($reflection->getParameters() as $param) {
                $pname = $param->getName();
                $map[] = [
                    'name'        => $pname,
                    'has_default' => $param->isDefaultValueAvailable(),
                    'default'     => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null,
                ];
            }
            return $map;
        } catch (\ReflectionException $e) {
            return [];
        }
    }

    // Prevent the instance from being cloned.
    private function __clone()
    {
    }

    // Prevent from being unserialized.
    public function __wakeup()
    {
    }

    public static function getServer()
    {
        return self::$server;
    }

    public static function display_errors($display_errors = true)
    {
        self::$display_errors = $display_errors;
    }

    
    /**
     * Registers a route with the application.
     *
     * @param string $path The URL path pattern for the route. Flask-like {param} syntax can be used for named parameters.
     * @param array $options Optional settings for the route, such as HTTP methods.
     *                       - 'methods' (array): HTTP methods allowed for this route. Defaults to ['GET'].
     * @param callable|null $handler The callback function to handle the route.
     *
     * If only two arguments are provided, the second argument is assumed to be the handler, and no options are set.
     *
     * The route pattern is converted to a named regex group for parameter matching.
     *
     * Example usage:
     * $app->route('/user/{id}', ['methods' => ['GET', 'POST']], function($id) {
     *     // Handler code here
     * });
     */
    public function route($path, $options = [], $handler = null)
    {
        // If only two arguments are provided, assume second is handler and no options.
        // But it's good that we clearly specify all three arguments in usage.
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }

        // Default methods to GET if not specified
        $methods = $options['methods'] ?? ['GET'];

        // Convert flask-like {param} to named regex group
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = "#^" . $pattern . "$#";

        $this->routes[] = [
            'path'      => $path,
            'pattern'   => $pattern,
            'methods'   => array_map('strtoupper', $methods),
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => (bool)($options['raw'] ?? false),
        ];
    }

    /**
     * nsRoute: Define a route under a specific namespace.
     * e.g. $app->nsRoute('api', '/users', ['methods' => ['GET']], fn() => "User list");
     * This will create a route at /api/users
     */
    public function nsRoute($namespace, $path, $options = [], $handler = null)
    {
        // If only two arguments are provided, assume second is handler and no options.
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }

        // Prepend the namespace prefix to the path
        $namespace = trim($namespace, '/');
        $path = '/' . $namespace . '/' . ltrim($path, '/');

        // Default methods to GET if not specified
        $methods = $options['methods'] ?? ['GET'];

        // Convert {param} style placeholders (no change from route)
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = "#^" . $pattern . "$#";

        $this->routes[] = [
            'path'      => $path,
            'pattern'   => $pattern,
            'methods'   => array_map('strtoupper', $methods),
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => (bool)($options['raw'] ?? false),
        ];
    }

    /**
     * nsPathRoute: Define a route under a namespace but allow the last parameter to capture everything (including slashes).
     * Here we assume the route is something like $app->nsPathRoute('api', ...)
     * and the actual route will be `/api/{path}` with {path} capturing all trailing segments.
     * 
     * Example:
     * $app->nsPathRoute('api', ['methods' => ['GET']], function($path) {
     *     return "Full path under /api: $path";
     * });
     * 
     * Accessing /api/devices/set_pref will set $path = "devices/set_pref".
     */
    public function nsPathRoute($namespace, $path, $options = [], $handler = null)
    {
        // If only two arguments are provided, assume second is handler and no options.
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }
    
        // Prepend the namespace prefix to the path
        $namespace = trim($namespace, '/');
        $path = '/' . $namespace . '/' . ltrim($path, '/');
    
        // Default methods to GET if not specified
        $methods = $options['methods'] ?? ['GET'];
    
        // Find all parameters
        preg_match_all('/\{([^}]+)\}/', $path, $paramMatches);
        $paramsFound = $paramMatches[1] ?? [];
        $lastParam = end($paramsFound);
    
        // Replace parameters: all but last use [^/]+, last one uses .+
        $pattern = preg_replace_callback('/\{([^}]+)\}/', function($m) use ($lastParam) {
            $paramName = $m[1];
            if ($paramName === $lastParam) {
                // Last parameter is catch-all, match everything remaining
                return '(?P<' . $paramName . '>.+)';
            } else {
                // Intermediate parameters match a single segment only
                return '(?P<' . $paramName . '>[^/]+)';
            }
        }, $path);
    
        $pattern = "#^" . $pattern . "$#";
    
        $this->routes[] = [
            'path'      => $path,
            'pattern'   => $pattern,
            'methods'   => array_map('strtoupper', $methods),
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => (bool)($options['raw'] ?? false),
        ];
    }


    /**
     * patternRoute: Allow full control of the pattern without {param} placeholders.
     * Here, the user provides a fully formed regex pattern (without anchors) and we anchor it internally.
     * e.g. $app->patternRoute('/api/(.*)', ['methods'=>['GET']], fn() => "Pattern matched!");
     * This will match any route starting with /api/.
     * 
     * TODO: Allow users to provide variable names for the regex groups.
     */
    public function patternRoute($regex, $options = [], $handler = null)
    {
        // If only two arguments are provided
        if (is_callable($options) && $handler === null) {
            $handler = $options;
            $options = [];
        }

        $methods = $options['methods'] ?? ['GET'];

        // Ensure the pattern is properly anchored if not already
        if (substr($regex, 0, 1) !== '#') {
            $regex = "#^" . $regex . "$#";
        }

        $this->routes[] = [
            'path'      => $regex,
            'pattern'   => $regex,
            'methods'   => array_map('strtoupper', $methods),
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => (bool)($options['raw'] ?? false),
        ];
    }

    /**
     * Parses the given CSS file.
     *
     * @param string $file The path to the CSS file to be parsed.
     * @return array The parsed CSS rules as an associative array.
     */
    public static function parseCss($file)
    {
        $css = file_get_contents($file);
        preg_match_all('/(?ims)([a-z0-9\s\.\:#_\-@,]+)\{([^\}]*)\}/', $css, $arr);
        $result = array();
        foreach ($arr[0] as $i => $x) {
            $selector = trim($arr[1][$i]);
            $rules = explode(';', trim($arr[2][$i]));
            $rules_arr = array();
            foreach ($rules as $strRule) {
                if (!empty($strRule)) {
                    $rule = explode(":", $strRule);
                    $rules_arr[trim($rule[0])] = trim($rule[1]);
                }
            }

            $selectors = explode(',', trim($selector));
            foreach ($selectors as $strSel) {
                $result[$strSel] = $rules_arr;
            }
        }
        return $result;
    }

    /**
     * Renders a template with the provided data.
     * This function looks for templates in the ./template folder located in the current working directory of the server.
     * It takes PHP_SELF into account and uses it as the source folder to look for templates unless the $__template_file starts with /.
     * Starting the $__template_file with / tells the render function to look for the template from the root of the template folder.
     *
     * @param string $__template_file The name of the template to render. Defaults to 'index'.
     * @param array $__args An associative array of data to pass to the template. Defaults to an empty array.
     * @throws TemplateUnavailableException if the template does not exist.
     * @return void
     */
    /**
     * Returns a Generator that yields template output. If the template file returns
     * a Generator (via IIFE), delegates to it with yield from — enabling streaming
     * templates. If the template echoes normally, captures output and yields it
     * as a single chunk. Backwards-compatible with all existing templates.
     *
     * Streaming template — declare parameters, framework injects by name:
     *   <?php return function($users) {
     *       foreach ($users as $user) {
     *           yield "<div>{$user['name']}</div>";
     *       }
     *   };
     *
     * Route handler:
     *   return (function() {
     *       yield from App::renderStream('shell-open', ['title' => 'Users']);
     *       yield from App::renderStream('users/list', ['users' => $users]);
     *       yield from App::renderStream('shell-close');
     *   })();
     *
     * Supports three template styles:
     *   1. return function($var) { yield ...; }  → Closure with param injection (cleanest)
     *   2. return (function() use ($var) { yield ...; })()  → Generator (IIFE, explicit)
     *   3. Regular echo template  → captured output yielded as one chunk
     */
    public static function renderStream(string $__template_file = 'index', array $__args = [], string $__default_template_dir = 'template'): \Generator
    {
        $__current_file = self::getCurrentFile(null);
        $__template_dir = self::$cwd . "/$__default_template_dir";
        $__root_lookup = strpos($__template_file, '/') === 0;
        if ($__root_lookup) {
            $__template_file_path = $__template_dir . $__template_file . '.php';
        } else if(!empty($__current_file) and is_dir("$__template_dir/" . $__current_file)){
            $__template_file_path = "$__template_dir/" . $__current_file . '/' . $__template_file . '.php';
        } else {
            $__template_file_path = "$__template_dir/" . $__template_file . '.php';
        }

        $__template_file_path = realpath($__template_file_path);

        if (!$__template_file_path or !file_exists($__template_file_path) or strpos($__template_file_path, self::$cwd) !== 0) {
            $caller = array_shift(debug_backtrace());
            throw new TemplateUnavailableException("The template $__template_file_path does not exist in file " . str_replace(App::$cwd, '', $caller['file']) . ":" . $caller['line'] );
        }

        ob_start();
        extract($__args, EXTR_SKIP);
        $__result = include $__template_file_path;
        $__output = ob_get_clean();

        if ($__result instanceof \Closure) {
            static $__reflCache = [];
            if (!isset($__reflCache[$__template_file_path])) {
                $__ref = new \ReflectionFunction($__result);
                $__reflCache[$__template_file_path] = array_map(
                    fn($__p) => ['name' => $__p->getName(), 'default' => $__p->isDefaultValueAvailable() ? $__p->getDefaultValue() : null],
                    $__ref->getParameters()
                );
            }
            $__params = [];
            foreach ($__reflCache[$__template_file_path] as $__p) {
                $__params[] = $__args[$__p['name']] ?? $__p['default'];
            }
            $__gen = $__result(...$__params);
            if ($__gen instanceof \Generator) {
                yield from $__gen;
            }
        } else if ($__result instanceof \Generator) {
            yield from $__result;
        } else if ($__output !== '' && $__output !== false) {
            yield $__output;
        }
    }

    public static function renderToString(string $__template_file = 'index', array $__args = [], string $__default_template_dir = 'template'): string
    {
        ob_start();
        try {
            self::render($__template_file, $__args, $__default_template_dir);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }

    public static function render($__template_file = 'index', $__args = [], $__default_template_dir = 'template')
    {
        $__current_file = self::getCurrentFile(null);
        $__template_dir = self::$cwd . "/$__default_template_dir";
        $__root_lookup = strpos($__template_file, '/') === 0;
        if ($__root_lookup) {
            $__template_file_path = $__template_dir . $__template_file . '.php';
        } else if(!empty($__current_file) and is_dir("$__template_dir/" . $__current_file)){
            $__template_file_path = "$__template_dir/" . $__current_file . '/' . $__template_file . '.php';
        } else {
            $__template_file_path = "$__template_dir/" . $__template_file . '.php';
        }

        $__template_file_path = realpath($__template_file_path);

        if (!$__template_file_path or !file_exists($__template_file_path) or strpos($__template_file_path, self::$cwd) !== 0) {
            $caller = array_shift(debug_backtrace());
            throw new TemplateUnavailableException("The template $__template_file_path does not exist in file " . str_replace(App::$cwd, '', $caller['file']) . ":" . $caller['line'] );
        } else {
            extract($__args, EXTR_SKIP);
            include $__template_file_path;
        }
    }

    
    /**
     * Returns the current executing script name without extenstion
     * @return String
     */
    public static function getCurrentFile($file = null)
    {
        $g = G::instance();
        if ($file == null) {
            return basename($g->server['PHP_SELF'] ?? '', '.php');
        } else {
            return basename($file, '.php');
        }
    }

    
    /**
     * Checks if the given file path is within the public directory.
     *
     * @param string $abs_file The absolute file path to check.
     * @return bool Returns true if the file is within the public directory, false otherwise.
     */
    public function includeCheck($abs_file){
        if (!$abs_file || strpos($abs_file, self::$cwd."/public") !== 0) {
            return false; // outside the public directory
        }
        if (self::$block_dotfiles) {
            $relative = substr($abs_file, strlen(self::$cwd."/public"));
            foreach (explode('/', $relative) as $segment) {
                if ($segment !== '' && $segment[0] === '.') {
                    return false; // dotfile (.git, .env, .htaccess, etc.)
                }
            }
        }
        return true;
    }

    /**
     * Apache DirectorySlash + DirectoryIndex behavior.
     *
     * If the request hit a directory under public/, optionally 301-redirect
     * to the trailing-slash form, then walk App::$directory_index until a
     * file is found. .php files run via includeFile(); others are served
     * via sendFile() (so Range/ETag work).
     *
     * Returns: \Generator for streaming, int for status code, null when the
     * route was handled inline (response already emitted), or false to
     * indicate the directory has no servable index.
     */
    public function serveDirectory(string $relDir, string $urlPrefix)
    {
        $g = G::instance();

        if (self::$directory_slash) {
            $requestPath = parse_url($g->server['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
            if ($requestPath !== '' && substr($requestPath, -1) !== '/') {
                $newUrl = $requestPath . '/';
                $qs = parse_url($g->server['REQUEST_URI'] ?? '', PHP_URL_QUERY);
                if ($qs) $newUrl .= '?' . $qs;
                $g->zealphp_response->redirect($newUrl, 301);
                $g->_streaming = true;
                return null;
            }
        }

        $base = self::$cwd . '/public/' . $relDir;
        foreach (self::$directory_index as $indexFile) {
            $abs = realpath($base . '/' . $indexFile);
            if (!$abs || !file_exists($abs)) continue;
            if (!$this->includeCheck($abs)) continue;

            $relPath = '/' . trim($urlPrefix, '/') . '/' . $indexFile;
            $g->server['PHP_SELF']        = $relPath;
            $g->server['SCRIPT_NAME']     = $relPath;
            $g->server['SCRIPT_FILENAME'] = $abs;

            if (substr($indexFile, -4) === '.php') {
                $__r = App::includeFile($abs);
                if ($__r instanceof \Generator) return $__r;
                return null;
            }
            $g->zealphp_response->sendFile($abs);
            $g->_streaming = true;
            return null;
        }
        return false;
    }

    /**
     * Include a PHP file with process isolation when superglobals mode is active.
     * Uses a separate PHP process (CGI-style) to ensure files run at the true
     * global scope — required for legacy apps like WordPress that use bare
     * variable assignments and `global` keyword declarations.
     */
    public static function includeFile(string $path): mixed
    {
        if (self::$coproc_implicit_request_handler) {
            echo self::cgiInclude($path);
            return null;
        }
        $result = include $path;
        if ($result instanceof \Generator || $result instanceof \Closure) {
            return $result;
        }
        return null;
    }

    private static function cgiInclude(string $path): string
    {
        $g = G::instance();

        $ctx = json_encode([
            'server' => $g->server ?? [],
            'get'    => $g->get ?? [],
            'post'   => $g->post ?? [],
            'cookie' => $g->cookie ?? [],
            'files'  => $g->files ?? [],
            'env'    => $g->env ?? $_ENV,
        ], JSON_UNESCAPED_SLASHES);

        $env = [];
        $allowedPrefixes = ['HTTP_', 'REQUEST_', 'SERVER_', 'SCRIPT_', 'DOCUMENT_', 'CONTENT_', 'REMOTE_', 'QUERY_', 'PATH_'];
        foreach ($g->server ?? [] as $k => $v) {
            if (!is_string($v)) continue;
            if ($k === 'HTTPS') {
                $env[$k] = $v;
                continue;
            }
            foreach ($allowedPrefixes as $prefix) {
                if (str_starts_with($k, $prefix)) {
                    $env[$k] = $v;
                    break;
                }
            }
        }
        $env['ZEALPHP_REQUEST_CONTEXT'] = $ctx;
        $env['ZEALPHP_CWD'] = self::$cwd;

        $cgiWorker = __DIR__ . '/cgi_worker.php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            PHP_BINARY . ' ' . escapeshellarg($cgiWorker) . ' ' . escapeshellarg($path),
            $descriptors,
            $pipes,
            self::$cwd . '/public',
            $env
        );

        if (!is_resource($process)) {
            elog("cgiInclude: failed to start process for $path", "error");
            return '<pre>500 Internal Server Error</pre>';
        }

        try {
            $postBody = $g->zealphp_request->parent->getContent();
            if ($postBody) fwrite($pipes[0], $postBody);
        } catch (\Throwable $e) {}
        fclose($pipes[0]);

        // Protocol: CGI worker sends metadata as a single JSON line on stderr
        // BEFORE streaming body on stdout. This enables SSE and streaming.
        $metaLine = fgets($pipes[2]);
        fclose($pipes[2]);

        $streaming = false;
        if ($metaLine) {
            $meta = json_decode(trim($metaLine), true);
            if ($meta) {
                response_set_status($meta['status_code'] ?? 200);
                foreach ($meta['headers'] ?? [] as $pair) {
                    if (count($pair) >= 2) {
                        $g->zealphp_response->header($pair[0], $pair[1]);
                    }
                }
                foreach ($meta['cookies'] ?? [] as $args) {
                    if (!empty($args)) {
                        $g->zealphp_response->cookie(...$args);
                    }
                }
                foreach ($meta['rawcookies'] ?? [] as $args) {
                    if (!empty($args)) {
                        $g->zealphp_response->rawCookie(...$args);
                    }
                }
                // Detect streaming content types (SSE, chunked, event-stream)
                foreach ($meta['headers'] ?? [] as $pair) {
                    if (strcasecmp($pair[0], 'Content-Type') === 0 &&
                        stripos($pair[1], 'text/event-stream') !== false) {
                        $streaming = true;
                    }
                }
            }
        }

        if ($streaming && $g->openswoole_response->isWritable()) {
            $g->zealphp_response->flush();
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk === false || $chunk === '') {
                    usleep(10000);
                    continue;
                }
                if (!$g->openswoole_response->isWritable()) break;
                $g->openswoole_response->write($chunk);
            }
            fclose($pipes[1]);
            proc_close($process);
            if ($g->openswoole_response->isWritable()) {
                $g->openswoole_response->end();
            }
            $g->_streaming = true;
            return '';
        }

        $body = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);
        return $body;
    }

    /**
     * Register a fallback handler for unmatched routes (like Apache's RewriteRule . /index.php [L]).
     */
    public function setFallback(callable $handler): void
    {
        self::$fallback_handler = [
            'handler'   => $handler,
            'param_map' => $this->buildParamMap($handler),
            'raw'       => false,
        ];
    }

    public static function getFallback(): ?array
    {
        return self::$fallback_handler;
    }

    public function addMiddleware(\Psr\Http\Server\MiddlewareInterface $middleware){
        self::$middleware_wait_stack[] = $middleware;
    }

    private function invokeFallbackOrNotFound(): \Psr\Http\Message\ResponseInterface
    {
        // Dispatch the fallback as a real route so its body — whether echoed,
        // returned as string/array/Generator/Response — is preserved instead of
        // being discarded by the outer route's int-return path in dispatchRoute.
        if (self::$fallback_handler !== null) {
            $method = G::instance()->server['REQUEST_METHOD'] ?? 'GET';
            return (new ResponseMiddleware())->dispatchRoute(self::$fallback_handler, [], $method);
        }
        return $this->renderError(404);
    }

    /**
     * Register a custom error page handler — Apache's `ErrorDocument` equivalent.
     *
     * Status-specific:  $app->setErrorHandler(404, fn() => App::render('404'));
     * Catch-all:        $app->setErrorHandler(fn($status) => ...);
     *
     * Handler signature supports param injection by name — any of:
     *   function() | function($status) | function($exception) |
     *   function($status, $exception, $request, $response)
     */
    public function setErrorHandler(int|callable $statusOrHandler, ?callable $handler = null): void
    {
        if (is_callable($statusOrHandler) && $handler === null) {
            $cb = $statusOrHandler;
            $status = 0; // catch-all
        } else {
            $status = (int)$statusOrHandler;
            $cb = $handler;
        }
        if (!is_callable($cb)) {
            throw new \InvalidArgumentException('setErrorHandler requires a callable');
        }
        self::$error_handlers[$status] = [
            'handler'   => $cb,
            'param_map' => $this->buildParamMap($cb),
            'raw'       => false,
        ];
    }

    public static function getErrorHandler(int $status): ?array
    {
        return self::$error_handlers[$status] ?? self::$error_handlers[0] ?? null;
    }

    /**
     * Render the response for an error status. Dispatches a user-registered
     * handler if one exists (status-specific takes precedence over catch-all);
     * otherwise returns the framework's default body (HTML or JSON per Accept).
     *
     * Handler exceptions are caught and logged — falls back to default body
     * so a buggy 500 handler can't infinite-loop.
     */
    public function renderError(int $status, ?\Throwable $exception = null): \Psr\Http\Message\ResponseInterface
    {
        $g = G::instance();
        // Recursion guard — if a user-registered error handler itself triggers
        // an error, the nested call falls straight through to the default page
        // instead of looping back into the same handler.
        if (($g->error_render_depth ?? 0) >= 1) {
            return $this->defaultErrorResponse($status, $exception);
        }
        $route = self::getErrorHandler($status);
        if ($route !== null) {
            $g->error_status    = $status;
            $g->error_exception = $exception;
            // Seed g->status with the error status so a handler that returns array/string
            // produces a response with the right HTTP status (the handler can still
            // override via http_response_code() before returning).
            $g->status = $status;
            $g->error_render_depth = ($g->error_render_depth ?? 0) + 1;
            try {
                $method = $g->server['REQUEST_METHOD'] ?? 'GET';
                return (new ResponseMiddleware())->dispatchRoute(
                    $route,
                    ['status' => $status, 'exception' => $exception],
                    $method
                );
            } catch (\Throwable $e) {
                elog("Error handler for $status itself threw: " . $e->getMessage(), 'error');
                // fall through to default
            } finally {
                $g->error_render_depth = max(0, ($g->error_render_depth ?? 1) - 1);
            }
        }
        return $this->defaultErrorResponse($status, $exception);
    }

    /**
     * Default error body. Honors `Accept: application/json` for JSON envelope,
     * otherwise emits HTML. Stack trace included only when App::$display_errors.
     */
    private function defaultErrorResponse(int $status, ?\Throwable $exception): \Psr\Http\Message\ResponseInterface
    {
        $g = G::instance();
        $reason = self::REASON_PHRASES[$status] ?? '';
        $accept = strtolower($g->server['HTTP_ACCEPT'] ?? '');
        $wantsJson = $accept !== ''
            && str_contains($accept, 'application/json')
            && !str_contains($accept, 'text/html');

        if ($wantsJson) {
            $body = json_encode([
                'error' => [
                    'status'  => $status,
                    'message' => $reason,
                    'trace'   => ($exception && self::$display_errors) ? jTraceEx($exception) : null,
                ],
            ], JSON_UNESCAPED_SLASHES);
            return (new Response($body))
                ->withStatus($status)
                ->withHeader('Content-Type', 'application/json');
        }

        $body = "<pre>{$status} {$reason}</pre>";
        if ($exception && self::$display_errors) {
            $body .= "\n<pre>" . htmlspecialchars(jTraceEx($exception)) . "</pre>";
        }
        return (new Response($body))->withStatus($status);
    }

    protected static function parseCliArgs(): array
    {
        $argv = $_SERVER['argv'] ?? $GLOBALS['argv'] ?? [];
        if (count($argv) <= 1) {
            return [];
        }

        array_shift($argv);
        $command = 'start';
        $flags = [];
        $i = 0;
        while ($i < count($argv)) {
            $arg = $argv[$i];
            if (in_array($arg, ['start', 'stop', 'status', 'restart', 'logs'], true)) {
                $command = $arg;
                $i++;
                continue;
            }
            if ($arg === '-h' || $arg === '--help' || $arg === 'help') {
                self::cliHelp();
                exit(0);
            }
            if ($arg === '-p' || $arg === '--port') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['port'] = (int)$argv[++$i];
                if ($flags['port'] < 1 || $flags['port'] > 65535) { echo "Error: port must be between 1 and 65535\n"; exit(1); }
            } elseif ($arg === '-H' || $arg === '--host') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['host'] = $argv[++$i];
            } elseif ($arg === '-w' || $arg === '--workers') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['worker_num'] = max(1, (int)$argv[++$i]);
            } elseif ($arg === '-d' || $arg === '--daemonize') {
                $flags['daemonize'] = true;
            } elseif ($arg === '--task-workers') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['task_worker_num'] = max(0, (int)$argv[++$i]);
            } elseif ($arg === '--pid-file') {
                if ($i + 1 >= count($argv)) { echo "Error: {$arg} requires a value\n"; exit(1); }
                $flags['pid_file'] = $argv[++$i];
            } elseif ($arg === '--access') {
                $flags['log_access'] = true;
            } elseif ($arg === '--debug') {
                $flags['log_debug'] = true;
            } elseif ($arg === '--server') {
                $flags['log_server'] = true;
            } elseif ($arg === '--zlog') {
                $flags['log_zlog'] = true;
            } elseif (str_starts_with($arg, '-')) {
                echo "Warning: unknown flag '{$arg}' (ignored)\n";
            }
            $i++;
        }

        switch ($command) {
            case 'stop':
                if (isset($flags['port']) || !empty($flags['pid_file'])) {
                    self::cliStop(self::resolvePidFile($flags));
                } else {
                    self::cliStopAuto();
                }
                exit(0);
            case 'status':
                self::cliStatus($flags);
                exit(0);
            case 'logs':
                self::cliLogs($flags);
                exit(0);
            case 'restart':
                $pidFile = self::resolvePidFile($flags);
                $wasDaemonized = file_exists($pidFile);
                echo "Restarting ZealPHP...\n";
                self::cliStop($pidFile, quiet: true);
                if ($wasDaemonized && !isset($flags['daemonize'])) {
                    $flags['daemonize'] = true;
                }
                // Fork a watcher child whose only job is to poll for the new
                // PID file and print "Restarted (pid X, port Y)". The actual
                // start happens in the original process (parent of the fork),
                // which falls through and eventually calls $server->start() —
                // that daemonizes internally, so we can't print success from
                // there. The watcher gives the user clear confirmation that
                // the new server came up.
                if (function_exists('pcntl_fork')) {
                    $port = $flags['port'] ?? (self::$instance ? self::$instance->port : 8080);
                    $watcherPid = pcntl_fork();
                    if ($watcherPid === 0) {
                        // Detach so the watcher survives independently and
                        // doesn't show up in the parent's process group.
                        if (function_exists('posix_setsid')) { @posix_setsid(); }
                        $newPid = 0;
                        for ($i = 0; $i < 50; $i++) {   // poll up to 5s
                            usleep(100000);
                            if (file_exists($pidFile)) {
                                $candidate = (int)trim(@file_get_contents($pidFile) ?: '');
                                if ($candidate > 0 && @posix_kill($candidate, 0)) {
                                    $newPid = $candidate;
                                    break;
                                }
                            }
                        }
                        if ($newPid > 0) {
                            echo "Restarted (pid {$newPid}, port {$port}).\n";
                        } else {
                            echo "Restarted but could not confirm — check `php app.php status`.\n";
                        }
                        exit(0);
                    }
                    // parent (original CLI process) continues to start the server
                }
                // fall through to start
            default:
                $pidFile = self::resolvePidFile($flags);
                if ($command === 'start' && file_exists($pidFile)) {
                    $pid = (int)trim(file_get_contents($pidFile));
                    if ($pid > 0 && @posix_kill($pid, 0)) {
                        $port = $flags['port'] ?? (self::$instance ? self::$instance->port : 8080);
                        echo "ZealPHP is already running (pid {$pid}, port {$port})\n";
                        echo "Use 'php app.php stop' to stop, or 'php app.php restart' to restart\n";
                        exit(0);
                    }
                    @unlink($pidFile);
                }

                $overrides = [];
                if (isset($flags['host'])) { $overrides['_host'] = $flags['host']; }
                if (isset($flags['port'])) { $overrides['_port'] = $flags['port']; }
                if (isset($flags['worker_num'])) { $overrides['worker_num'] = $flags['worker_num']; }
                if (isset($flags['daemonize'])) { $overrides['daemonize'] = true; }
                if (isset($flags['task_worker_num'])) { $overrides['task_worker_num'] = $flags['task_worker_num']; }
                if (isset($flags['pid_file'])) { $overrides['pid_file'] = $flags['pid_file']; }
                return $overrides;
        }
    }

    private static function resolvePidFile(array $flags): string
    {
        if (!empty($flags['pid_file'])) {
            return $flags['pid_file'];
        }
        $envPid = getenv('ZEALPHP_PID_FILE');
        if ($envPid !== false && trim((string)$envPid) !== '') {
            return trim((string)$envPid);
        }
        $port = $flags['port'] ?? (self::$instance ? self::$instance->port : 8080);
        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir !== false && trim((string)$logDir) !== '') {
            return rtrim(trim((string)$logDir), '/') . "/zealphp_{$port}.pid";
        }
        if (is_dir('/tmp/zealphp')) {
            return "/tmp/zealphp/zealphp_{$port}.pid";
        }
        return "/tmp/zealphp_{$port}.pid";
    }

    private static function cliStop(string $pidFile, bool $quiet = false): void
    {
        $say = function (string $msg) use ($quiet): void {
            if (!$quiet) { echo $msg; }
        };

        if (!file_exists($pidFile)) {
            $say("ZealPHP is not running (no PID file: {$pidFile})\n");
            return;
        }
        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid <= 0 || !@posix_kill($pid, 0)) {
            $say("ZealPHP is not running (stale PID file)\n");
            @unlink($pidFile);
            return;
        }
        $pgid = @posix_getpgid($pid);
        $killGroup = $pgid && $pgid !== posix_getpgid(posix_getpid());
        $say("Stopping ZealPHP (pid {$pid})...\n");
        $killGroup ? posix_kill(-$pgid, SIGTERM) : posix_kill($pid, SIGTERM);
        // OpenSwoole graceful shutdown (workers finish current requests, master
        // tears down listeners) typically takes 5-7 seconds. Poll for up to 10s
        // before falling back to SIGKILL.
        for ($i = 0; $i < 10; $i++) {       // first 500ms: fast poll
            usleep(50000);
            if (!@posix_kill($pid, 0)) {
                @unlink($pidFile);
                $say("Stopped.\n");
                return;
            }
        }
        for ($i = 0; $i < 95; $i++) {       // next 9.5s: slower poll
            usleep(100000);
            if (!@posix_kill($pid, 0)) {
                @unlink($pidFile);
                $say("Stopped.\n");
                return;
            }
        }
        $say("Graceful shutdown timed out, force killing...\n");
        $killGroup ? posix_kill(-$pgid, SIGKILL) : posix_kill($pid, SIGKILL);
        usleep(100000);
        @unlink($pidFile);
    }

    private static function cliStopAuto(): void
    {
        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir === false || trim((string)$logDir) === '') {
            $logDir = is_dir('/tmp/zealphp') ? '/tmp/zealphp' : '/tmp';
        }
        $pidFiles = glob(rtrim(trim((string)$logDir), '/') . '/zealphp_*.pid');
        $running = [];
        foreach ($pidFiles as $f) {
            $pid = (int)trim(file_get_contents($f));
            if ($pid > 0 && @posix_kill($pid, 0)) {
                $port = preg_match('/zealphp_(\d+)\.pid$/', $f, $m) ? $m[1] : '?';
                $running[] = ['file' => $f, 'pid' => $pid, 'port' => $port];
            } else {
                @unlink($f);
            }
        }
        if (empty($running)) {
            echo "No ZealPHP instances running\n";
            return;
        }
        if (count($running) === 1) {
            self::cliStop($running[0]['file']);
            return;
        }
        echo "Multiple ZealPHP instances running:\n";
        foreach ($running as $r) {
            echo "  pid {$r['pid']}, port {$r['port']}\n";
        }
        echo "Use 'php app.php stop -p PORT' to stop a specific instance\n";
    }

    private static function cliStatus(array $flags): void
    {
        if (isset($flags['port'])) {
            $pidFile = self::resolvePidFile($flags);
            self::cliStatusOne($pidFile);
            return;
        }

        $logDir = getenv('ZEALPHP_LOG_DIR');
        if ($logDir === false || trim((string)$logDir) === '') {
            $logDir = is_dir('/tmp/zealphp') ? '/tmp/zealphp' : '/tmp';
        }
        $pidFiles = glob(rtrim(trim((string)$logDir), '/') . '/zealphp_*.pid');
        if (empty($pidFiles)) {
            echo "No ZealPHP instances running\n";
            exit(1);
        }

        $found = 0;
        foreach ($pidFiles as $pidFile) {
            $pid = (int)trim(file_get_contents($pidFile));
            if ($pid <= 0 || !@posix_kill($pid, 0)) {
                @unlink($pidFile);
                continue;
            }
            $port = '?';
            if (preg_match('/zealphp_(\d+)\.pid$/', $pidFile, $m)) {
                $port = $m[1];
            }
            echo "ZealPHP is running (pid {$pid}, port {$port})\n";
            $found++;
        }

        if ($found === 0) {
            echo "No ZealPHP instances running\n";
            exit(1);
        }
        exit(0);
    }

    private static function cliStatusOne(string $pidFile): void
    {
        if (!file_exists($pidFile)) {
            echo "ZealPHP is not running\n";
            exit(1);
        }
        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid <= 0 || !@posix_kill($pid, 0)) {
            echo "ZealPHP is not running (stale PID file)\n";
            @unlink($pidFile);
            exit(1);
        }
        $port = '?';
        if (preg_match('/zealphp_(\d+)\.pid$/', $pidFile, $m)) {
            $port = $m[1];
        }
        echo "ZealPHP is running (pid {$pid}, port {$port})\n";
        exit(0);
    }

    private static function cliLogs(array $flags): void
    {
        if (isset($flags['port'])) {
            echo "Note: log files are shared across all ports. -p flag ignored.\n";
        }
        $hasFilter = isset($flags['log_access']) || isset($flags['log_debug'])
                  || isset($flags['log_server']) || isset($flags['log_zlog']);

        $files = [];

        if (!$hasFilter || isset($flags['log_access'])) {
            $path = \ZealPHP\log_file_for('access');
            if ($path !== null) {
                $files[] = $path;
            }
        }
        if (!$hasFilter || isset($flags['log_debug'])) {
            $path = \ZealPHP\log_file_for('debug');
            if ($path !== null) {
                $files[] = $path;
            }
        }
        if (!$hasFilter || isset($flags['log_zlog'])) {
            $path = \ZealPHP\log_file_for('zlog');
            if ($path !== null) {
                $files[] = $path;
            }
        }
        if (!$hasFilter || isset($flags['log_server'])) {
            $serverLog = getenv('ZEALPHP_SERVER_LOG_FILE');
            if ($serverLog === false || trim((string)$serverLog) === '') {
                $dir = \ZealPHP\resolve_log_dir();
                if ($dir !== null) {
                    $serverLog = $dir . '/server.log';
                }
            }
            if ($serverLog !== null && trim((string)$serverLog) !== '') {
                $files[] = trim((string)$serverLog);
            }
        }

        if (empty($files)) {
            echo "No log files found. Check ZEALPHP_LOG_DIR or run the server first.\n";
            exit(1);
        }

        foreach ($files as $file) {
            if (!file_exists($file)) {
                $dir = dirname($file);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                @touch($file);
            }
        }

        echo "Tailing log files (Ctrl+C to stop):\n";
        foreach ($files as $file) {
            echo "  {$file}\n";
        }
        echo "\n";

        $cmd = 'tail -F';
        foreach ($files as $file) {
            $cmd .= ' ' . escapeshellarg($file);
        }
        passthru($cmd);
    }

    private static function cliHelp(): void
    {
        echo <<<'HELP'
Usage: php app.php [command] [options]

Commands:
  start    Start the server (default)
  stop     Stop a running server
  restart  Stop and restart the server
  status   Check if server is running
  logs     Tail log files (Ctrl+C to stop)

Options:
  -p, --port N         Listen port (default: from App::init)
  -H, --host ADDR      Listen address (default: 0.0.0.0)
  -w, --workers N      Number of worker processes
  -d, --daemonize      Run in background
  --task-workers N     Number of task workers (default: 0)
  --pid-file PATH      Custom PID file path
  -h, --help           Show this help message

Log filters (use with 'logs' command):
  --access             Only tail access.log
  --debug              Only tail debug.log
  --server             Only tail server.log
  --zlog               Only tail zlog.log

Examples:
  php app.php                        Start with defaults
  php app.php start -p 9501 -d      Start daemonized on port 9501
  php app.php stop                   Stop the default (port 8080) server
  php app.php stop -p 9501          Stop the server on port 9501
  php app.php restart -p 9501       Restart on port 9501
  php app.php status                 Check if default server is running
  php app.php status -p 9501        Check server on port 9501
  php app.php logs                   Tail all log files
  php app.php logs --access          Tail only access log
  php app.php logs --access --debug  Tail access + debug logs

PID files: /tmp/zealphp/zealphp_{port}.pid (one per port, supports multiple apps)

HELP;
    }

    /**
     * Runs the ZealPHP application.
     *
     * @param array|null $settings Optional settings to override the default OpenSwoole Server Configuration settings.
     *
     * Default settings:
     * - enable_static_handler: bool (default: true)
     * - document_root: string (default: self::$cwd . '/public')
     * - enable_coroutine: bool (default: true)
     * - pid_file: string (default: '/tmp/zealphp_{port}.pid')
     *
     * CLI usage:
     *   php app.php [start|stop|status] [-p port] [-H host] [-w workers] [-d] [--task-workers N] [--pid-file path]
     */
    public function run($settings = null)
    {
        $cliOverrides = self::parseCliArgs();
        if (isset($cliOverrides['_host'])) {
            $this->host = $cliOverrides['_host'];
            unset($cliOverrides['_host']);
        }
        if (isset($cliOverrides['_port'])) {
            $this->port = (int)$cliOverrides['_port'];
            unset($cliOverrides['_port']);
            if (is_array($settings) && isset($settings['pid_file'])) {
                $settings['pid_file'] = preg_replace(
                    '/zealphp_\d+\.pid$/',
                    "zealphp_{$this->port}.pid",
                    $settings['pid_file']
                );
            }
        }
        if (!empty($cliOverrides)) {
            $settings = array_merge($settings ?? [], $cliOverrides);
        }

        App::$coproc_implicit_request_handler = App::$superglobals;
        if(!App::$superglobals){
            co::set(['hook_flags'=> \OpenSwoole\Runtime::HOOK_ALL]);
            \OpenSwoole\Runtime::enableCoroutine(\OpenSwoole\Runtime::HOOK_ALL);
        }
        // Use the same path resolution as the stop/status CLI commands so that
        // `php app.php stop` finds the PID file the server just wrote. Without
        // this, the server writes /tmp/zealphp_PORT.pid (flat) but stop looks
        // under /tmp/zealphp/zealphp_PORT.pid (subdir) — they disagree.
        $defaultPidFile = self::resolvePidFile(['port' => $this->port]);
        $default_settings = [
            'enable_static_handler' => true,
            'document_root' => self::$cwd . '/public',
            // Restrict OpenSwoole's built-in static handler to the listed URL prefixes
            // (Apache equivalent: serving only safe subtrees). Leave empty to serve all
            // — including dotfiles — like Apache default. Default whitelist below is
            // safe for typical web apps; override via $app->run(['static_handler_locations' => [...]]).
            'static_handler_locations' => self::$static_handler_locations !== []
                ? self::$static_handler_locations
                : ['/css', '/js', '/img', '/images', '/fonts', '/assets', '/static', '/favicon.ico', '/robots.txt'],
            'enable_coroutine' =>  !self::$superglobals,
            // Runtime compression is owned by OpenSwoole. Do not also register
            // CompressionMiddleware unless this setting is disabled.
            'http_compression' => true,
            'pid_file' => $defaultPidFile,
            // Worker recycling — bounds memory growth from leaks accumulated
            // in long-running workers (static caches, closure captures, leaky
            // extensions). After this many requests a worker exits cleanly and
            // is respawned with a fresh PHP arena. Set 0 to disable. Override
            // via ZEALPHP_MAX_REQUEST env var or $app->run(['max_request' => N]).
            'max_request' => (int)(getenv('ZEALPHP_MAX_REQUEST') ?: 100000),
            'task_worker_num' => 0,
            'task_enable_coroutine' => true,
            // Suppress NOTICE-level messages from OpenSwoole internals (e.g. ERRNO 1005
            // "session does not exist" when SSE/WS clients disconnect mid-stream).
            // Pass 'log_level' => 0 in $app->run() settings to restore full debug output.
            'log_level' => 4,  // 0=DEBUG 1=TRACE 2=INFO 3=NOTICE 4=WARNING 5=ERROR 6=NONE
        ];
        $pidFile = $settings['pid_file'] ?? $default_settings['pid_file'];
        if (file_exists($pidFile)) {
            $existingPid = (int)trim(file_get_contents($pidFile));
            if ($existingPid > 0 && @posix_kill($existingPid, 0)) {
                echo "ZealPHP is already running (pid {$existingPid}, port {$this->port})\n";
                echo "Use 'php app.php stop' to stop, or 'php app.php restart' to restart\n";
                exit(0);
            }
            @unlink($pidFile);
        }

        self::$server = $server = new \OpenSwoole\WebSocket\Server($this->host, $this->port);
        if ($settings == null){
            $effective_settings = $default_settings;
        } else {
            $effective_settings = array_merge($default_settings, $settings);
            $effective_settings['enable_coroutine'] = !self::$superglobals;
        }
        $server->set($effective_settings);

        # Include all files in route directory and its sub directories

        $route_files = glob(self::$cwd."/route/*.php");
        foreach ($route_files as $route_file) {
            elog("Including route file 1: ".str_replace(App::$cwd, '', $route_file));
            include $route_file;
        }

        # Implicit route for including APIs.
        # The two-segment route is registered FIRST so that /api/users/list
        # matches with module=users, request=list (a single segment passing
        # the security regex), instead of being captured by the one-segment
        # catch-all as request="users/list" — which contains a slash and
        # would fail validation with a misleading "invalid_request" error.
        $this->nsPathRoute('api', "{module}/{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ], function($module, $rquest, $response, $request){
            $api = new ZealAPI($request, $response, self::$cwd);
            try {
                return $api->processApi($module, $rquest);
            } catch (\Exception $e){
                $api->die($e);
            }
        });

        $this->nsPathRoute('api', "{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ], function($rquest, $response, $request){
            $api = new ZealAPI($request, $response, self::$cwd);
            try {
                return $api->processApi("", $rquest);
            } catch (\Exception $e){
                $api->die($e);
            }
        });

        # Implicit route for ignoring PHP extensions

        if(App::$ignore_php_ext){
            $this->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
                return App::instance()->renderError(403);
            });
        }

        # Block URLs targeting dotfile segments (.git/, .env, .htaccess, …).
        # `.well-known/` is allowed — it's a registered convention (RFC 8615).
        if (App::$block_dotfiles) {
            $this->patternRoute('/(.*/)?\.(?!well-known)[^/]*', [
                'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH', 'HEAD']
            ], function($response) {
                return App::instance()->renderError(403);
            });
        }
        // $this->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
        //     echo("<pre>403 Forbidden</pre>");
        //     return(403);
        // });

        # Implicit route for index.php

        $this->route('/',[
            'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH']
        ], function($response){
            // elog("Index route hit");
            $g = G::instance();
            $file = 'index';
            $g->server['PHP_SELF'] = '/'.$file.'.php';
            $g->server['SCRIPT_NAME'] = '/'.$file.'.php';
            $g->server['SCRIPT_FILENAME'] = self::$cwd."/public/".$file.".php";
            $abs_file = self::$cwd."/public/".$file.".php";
            if(file_exists($abs_file)){
                if ($this->includeCheck($abs_file)){
                    $__r = App::includeFile($abs_file);
                    if ($__r instanceof \Generator) return $__r;
                } else {
                    return App::instance()->renderError(403);
                }
            } else {
                return $this->invokeFallbackOrNotFound();
            }
        });

        # Global route for all files in the root of the public directory
        $this->route(App::$ignore_php_ext ? '/{file}/?' : '/{file}(\.php)?/?', [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH']
        ], function($file, $response){
            $g = G::instance();
            # if file ends with .php remove it
            if (substr($file, -4) == '.php') {
                $file = substr($file, 0, -4);
            }
            $abs_file = realpath(self::$cwd."/public/".$file.'.php');
            if(file_exists($abs_file)){
                if ($this->includeCheck($abs_file)){
                    $g->server['PHP_SELF'] = '/'.$file.'.php';
                    $g->server['SCRIPT_NAME'] = '/'.$file.'.php';
                    $g->server['SCRIPT_FILENAME'] = $abs_file;
                    $__r = App::includeFile($abs_file);
                    if ($__r instanceof \Generator) return $__r;
                } else {
                    return App::instance()->renderError(403);
                }
            } else if(is_dir(self::$cwd."/public/".$file)){
                $result = $this->serveDirectory($file, $file);
                if ($result === false) {
                    return $this->invokeFallbackOrNotFound();
                }
                return $result;
            } else {
                return $this->invokeFallbackOrNotFound();
            }
        });

        # Global route for all directories and sub directories in the public directory
        $this->nsPathRoute('{dir}', App::$ignore_php_ext ? '{uri}/?' : '{uri}(\.php)?/?', [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS', 'PATCH']
        ], function($dir, $uri, $response){
            $g = G::instance();
            elog("Directory: $dir, URI: $uri");
            # if uri ends with .php remove it
            if (substr($uri, -4) == '.php') {
                $uri = substr($uri, 0, -4);
            }
            $abs_file = realpath(self::$cwd."/public/".$dir.'/'.$uri.'.php');
            if(file_exists($abs_file)){
                if ($this->includeCheck($abs_file)){
                    $g->server['PHP_SELF'] = '/'.$dir.'/'.$uri.'.php';
                    $g->server['SCRIPT_NAME'] = '/'.$dir.'/'.$uri.'.php';
                    $g->server['SCRIPT_FILENAME'] = $abs_file;
                    // include $abs_file;
                    $__r = App::includeFile($abs_file);
                    if ($__r instanceof \Generator) return $__r;
                } else {
                    return App::instance()->renderError(403);
                }
            } else if(is_dir(self::$cwd."/public/".$dir.'/'.$uri)){
                $result = $this->serveDirectory($dir.'/'.$uri, $dir.'/'.$uri);
                if ($result === false) {
                    return $this->invokeFallbackOrNotFound();
                }
                return $result;
            } else {
                return $this->invokeFallbackOrNotFound();
            }
        });

        if (($effective_settings['task_worker_num'] ?? 0) > 0) {
            $server->on('task', function ($server, $id, $rid, $data) {
                $handler = $data['handler'];
                $_func = basename($handler);
                if(file_exists(App::$cwd.$handler.'.php')){
                    include App::$cwd.$handler.'.php';
                    $result = $$_func(...$data['args']);
                    unset($$_func);
                } else {
                    elog("Task handler not found: $handler", "error");
                    $result = false;
                }
                elog(json_encode([$data, $result]), "task");
                return [
                    'task' => $data,
                    'result' => $result
                ];
            });

            $server->on('finish', function ($server, $task_id, $data) {
                elog(json_encode($data), "task_task");
            });
        }

        $SessionManager = self::$superglobals ?  'ZealPHP\Session\SessionManager' : 'ZealPHP\Session\CoSessionManager';

        foreach (array_reverse(self::$middleware_wait_stack) as $middleware) {
            elog("Registering middleware: ".get_class($middleware));
            self::$middleware_stack = self::$middleware_stack->add($middleware);
        }

        $server->on("request",new $SessionManager(function(\ZealPHP\HTTP\Request $request, \ZealPHP\HTTP\Response $response) {
            $g = G::instance();
            static $serverSoftware = null;
            if ($serverSoftware === null) {
                $serverSoftware = 'ZealPHP/dev (' . php_uname('s') . ') PHP/' . phpversion();
            }

            $g->status = 200;
            $g->get = $request->get ?? [];
            $g->post = $request->post ?? [];
            $g->request = $g->get + $g->post;
            $g->cookie = $request->cookie ?? [];
            $g->files = $request->files ?? [];

            // Build $_SERVER — use array_change_key_case instead of foreach+strtoupper
            $srv = $request->server ? array_change_key_case($request->server, CASE_UPPER) : [];
            if ($request->header) {
                foreach ($request->header as $key => $value) {
                    $srv['HTTP_' . strtr(strtoupper($key), '-', '_')] = $value;
                }
            }
            $srv += [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => '/',
                'SCRIPT_NAME' => '/app.php',
                'SERVER_NAME' => $srv['HTTP_HOST'] ?? site_host(),
                'DOCUMENT_ROOT' => self::$cwd . '/public',
                'PHP_SELF' => App::$default_php_self,
                'SERVER_SOFTWARE' => $serverSoftware,
            ];
            $srv['SCRIPT_FILENAME'] ??= $srv['DOCUMENT_ROOT'] . $srv['PHP_SELF'];

            if ($srv['REQUEST_METHOD'] === 'POST' && isset($srv['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
                $srv['REQUEST_METHOD'] = $srv['HTTP_X_HTTP_METHOD_OVERRIDE'];
            }
            $g->server = $srv;

            $serverRequest  = new \ZealPHP\HTTP\LazyServerRequest($request->parent);

            try {
                $serverResponse = App::middleware()->handle($serverRequest);

                // Per-request shutdown functions (Apache mod_php parity). Run AFTER
                // middleware returns but BEFORE emit, so a shutdown function can
                // still echo/header()/http_response_code() into the final response.
                $shutdown = $g->shutdown_functions;
                if (!empty($shutdown)) {
                    ob_start();
                    $beforeStatus = $g->status;
                    foreach ($shutdown as [$fn, $args]) {
                        try { $fn(...$args); } catch (\Throwable $e) {
                            elog("shutdown function threw: ".$e->getMessage(), 'error');
                        }
                    }
                    $g->shutdown_functions = [];
                    $extra = ob_get_clean();
                    if ($extra !== false && $extra !== '') {
                        $combined = (string)$serverResponse->getBody() . $extra;
                        $bodyRes = fopen('php://temp', 'r+');
                        fwrite($bodyRes, $combined);
                        rewind($bodyRes);
                        $serverResponse = $serverResponse->withBody(
                            new \OpenSwoole\Core\Psr\Stream($bodyRes)
                        );
                    }
                    if ($g->status !== null && $g->status !== $beforeStatus
                        && $g->status !== $serverResponse->getStatusCode()) {
                        $serverResponse = $serverResponse->withStatus($g->status);
                    }
                }

                if ($response->parent->isWritable()) {
                    $response->flush();
                    $response->parent->header('X-Powered-By', 'ZealPHP + OpenSwoole');
                    \OpenSwoole\Core\Psr\Response::emit($response->parent, $serverResponse);
                }
                access_log($serverResponse->getStatusCode(), 0);
            } catch (\Throwable|\OpenSwoole\ExitException $e) {
                elog(jTraceEx($e), "error");
                if ($response->parent->isWritable()) {
                    // Render via App::renderError so a user-registered 500 handler
                    // (Apache ErrorDocument equivalent) runs even at the top level.
                    try {
                        $errResp = App::instance()->renderError(500, $e);
                        $response->parent->status($errResp->getStatusCode());
                        foreach ($errResp->getHeaders() as $name => $values) {
                            foreach ($values as $value) {
                                $response->parent->header($name, $value);
                            }
                        }
                        $g->status = $errResp->getStatusCode();
                        $response->parent->end((string)$errResp->getBody());
                    } catch (\Throwable $e2) {
                        $response->parent->status(500);
                        $g->status = 500;
                        $body = App::$display_errors
                            ? "<pre>".jTraceEx($e)."</pre>"
                            : "<pre>500 Internal Server Error</pre>";
                        $response->parent->end($body);
                    }
                }
            }
        }));

        // Build method-indexed dispatch table once at boot (O(1) method lookup per request)
        foreach ($this->routes as $route) {
            foreach ($route['methods'] as $m) {
                $this->routes_by_method[$m][] = $route;
                if (isset($route['path']) && $this->isExactRoutePath($route['path'])) {
                    $this->routes_by_exact_method[$m][$route['path']] = $route;
                }
            }
        }

        // Register the php:// stream wrapper once per worker process instead of per-request
        // and invoke any user-registered onWorkerStart hooks (timers, warmup, etc.)
        $server->on('workerStart', function($server, $workerId) {
            @stream_wrapper_unregister("php");
            stream_wrapper_register("php", \ZealPHP\IOStreamWrapper::class);
            foreach (self::$workerStartHooks as $hook) {
                $hook($server, $workerId);
            }
        });

        // fd → ws path map, shared across WebSocket event closures
        $wsFdMap = [];

        $server->on('open', function(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\Http\Request $request) use (&$wsFdMap) {
            $path  = $request->server['path_info'] ?? '/';
            $wsFdMap[$request->fd] = $path;
            $g     = G::instance();

            // Initialize session from the upgrade request's cookie so
            // WebSocket onOpen handlers can read $g->session just like
            // HTTP handlers do via CoSessionManager.
            $sessionName = function_exists('ZealPHP\\Session\\zeal_session_name')
                ? \ZealPHP\Session\zeal_session_name()
                : 'PHPSESSID';
            if (isset($request->cookie[$sessionName])) {
                $g->cookie[$sessionName] = $request->cookie[$sessionName];
                \ZealPHP\Session\zeal_session_id($request->cookie[$sessionName]);
                \ZealPHP\Session\zeal_session_start();
                $g->_session_started = true;
            }

            $route = App::instance()->wsRoutes()[$path] ?? null;
            if ($route && $route['open']) {
                ($route['open'])($server, $request, $g);
            }

            // Write-close the session after onOpen so the file isn't locked
            if ($g->_session_started ?? false) {
                \ZealPHP\Session\zeal_session_write_close();
            }
        });

        $server->on('message', function(\OpenSwoole\WebSocket\Server $server, \OpenSwoole\WebSocket\Frame $frame) use (&$wsFdMap) {
            // Skip control frames: PING(9), PONG(10), CONTINUATION(0)
            // Only dispatch TEXT(1) and BINARY(2) to route handlers
            $op = $frame->opcode;
            if ($op !== \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_TEXT &&
                $op !== \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY) {
                return;
            }
            $path  = $wsFdMap[$frame->fd] ?? null;
            $g     = G::instance();
            $route = $path ? (App::instance()->wsRoutes()[$path] ?? null) : null;
            if ($route && $route['message']) {
                ($route['message'])($server, $frame, $g);
            }
        });

        $server->on('close', function(\OpenSwoole\WebSocket\Server $server, int $fd) use (&$wsFdMap) {
            $path  = $wsFdMap[$fd] ?? null;
            unset($wsFdMap[$fd]);
            $g     = G::instance();
            $route = $path ? (App::instance()->wsRoutes()[$path] ?? null) : null;
            if ($route && $route['close']) {
                ($route['close'])($server, $fd, $g);
            }
        });

        // Graceful shutdown: send WebSocket CLOSE frame 1001 (Going Away) to all connections
        $server->on('shutdown', function(\OpenSwoole\WebSocket\Server $server) use (&$wsFdMap) {
            foreach (array_keys($wsFdMap) as $fd) {
                if ($server->isEstablished($fd)) {
                    $server->disconnect($fd, 1001, 'Server shutting down');
                }
            }
        });

        elog("ZealPHP server running at http://{$this->host}:{$this->port} with ".count($this->routes)." routes");
        $server->start();
    }

    public static function middleware(){
        return self::$middleware_stack;
    }
}

class ResponseMiddleware implements MiddlewareInterface
{
    private function dispatchRawRoute(array $route, array $params, string $method): ResponseInterface
    {
        $g = G::instance();
        $handler = $route['handler'];

        $invokeArgs = [];
        foreach ($route['param_map'] as $param) {
            $pname = $param['name'];
            if (isset($params[$pname])) {
                $invokeArgs[] = $params[$pname];
            } else if ($pname === 'app') {
                $invokeArgs[] = $this;
            } else if ($pname === 'request') {
                $invokeArgs[] = $g->zealphp_request;
            } else if ($pname === 'response') {
                $invokeArgs[] = $g->zealphp_response;
            } else {
                $invokeArgs[] = $param['has_default'] ? $param['default'] : null;
            }
        }

        try {
            $object = call_user_func_array($handler, $invokeArgs);
            if ($object instanceof ResponseInterface) {
                return $object;
            }

            if ($object instanceof \Generator) {
                // Capture status BEFORE flush — Response::flush() clears g->status.
                $streamStatus = $g->status ?? 200;
                $g->openswoole_response->status($streamStatus);
                $g->zealphp_response->header('Accept-Ranges', 'none');
                $g->zealphp_response->flush();
                foreach ($object as $chunk) {
                    if (!$g->openswoole_response->isWritable()) break;
                    $g->openswoole_response->write((string)$chunk);
                    \OpenSwoole\Coroutine::sleep(0);
                }
                if ($g->openswoole_response->isWritable()) {
                    $g->openswoole_response->end();
                }
                return (new Response('', $streamStatus));
            }

            if ($g->_streaming ?? false) {
                return (new Response('', $g->status ?? 200));
            }

            if (is_int($object)) {
                $status = (int)$object;
                $body = '';
            } else {
                $status = $g->status ?? 200;
                if (is_array($object) or is_object($object)) {
                    response_add_header('Content-Type', 'application/json');
                    $body = json_encode($object);
                } else if (is_string($object)) {
                    $body = $object;
                } else {
                    $body = '';
                }
            }

            if ($method === 'HEAD') {
                response_add_header('Content-Length', (string)strlen($body));
                return (new Response('', $status));
            }
            return (new Response($body, $status));
        } catch (\Throwable|\OpenSwoole\ExitException $e) {
            if($e instanceof \OpenSwoole\ExitException){
                if($e->getStatus() == 0){
                    return (new Response(''))->withStatus($g->status ?? 200);
                } else {
                    return App::instance()->renderError(500);
                }
            }
            // If this dispatch was itself invoked by renderError (error handler
            // dispatch path), let the throw bubble back so the outer renderError
            // catches it and renders the ORIGINAL error status's default page —
            // not a fresh 500 from inside the recursion.
            if (($g->error_render_depth ?? 0) > 0) {
                throw $e;
            }
            // User-installed exception handler runs before the default error page.
            $excStack = $g->exception_handlers_stack ?? [];
            if (!empty($excStack)) {
                ob_start();
                try { $excStack[count($excStack) - 1]($e); } catch (\Throwable $e2) { /* swallow */ }
                $body = ob_get_clean();
                return (new Response($body))->withStatus($g->status ?? 500);
            }
            elog(jTraceEx($e), "error");
            return App::instance()->renderError(500, $e);
        }
    }

    public function dispatchRoute(array $route, array $params, string $method): ResponseInterface
    {
        if (($route['raw'] ?? false) === true) {
            return $this->dispatchRawRoute($route, $params, $method);
        }

        $g = G::instance();
        $handler = $route['handler'];

        $invokeArgs = [];
        foreach ($route['param_map'] as $param) {
            $pname = $param['name'];
            if (isset($params[$pname])) {
                $invokeArgs[] = $params[$pname];
            } else if ($pname === 'app') {
                $invokeArgs[] = $this;
            } else if ($pname === 'request') {
                $invokeArgs[] = $g->zealphp_request;
            } else if ($pname === 'response') {
                $invokeArgs[] = $g->zealphp_response;
            } else {
                $invokeArgs[] = $param['has_default'] ? $param['default'] : null;
            }
        }

        try {
            ob_start();
            $object = call_user_func_array($handler, $invokeArgs);

            // Fast paths — discard output buffer without string copy
            if ($object instanceof \Generator) {
                ob_end_clean();
                $streamStatus = $g->status ?? 200;
                $g->openswoole_response->status($streamStatus);
                $g->zealphp_response->header('Accept-Ranges', 'none');
                $g->zealphp_response->flush();
                foreach ($object as $chunk) {
                    if (!$g->openswoole_response->isWritable()) break;
                    $g->openswoole_response->write((string)$chunk);
                    \OpenSwoole\Coroutine::sleep(0);
                }
                if ($g->openswoole_response->isWritable()) {
                    $g->openswoole_response->end();
                }
                return (new Response('', $streamStatus));
            }

            if ($g->_streaming ?? false) {
                // Apache+mod_php auto-flushes any remaining buffer at handler exit
                // even in streaming mode. Mirror that to keep the last echo visible.
                if (ob_get_level() > 0) {
                    $remaining = ob_get_clean();
                    if ($remaining !== false && $remaining !== ''
                        && isset($g->openswoole_response)
                        && $g->openswoole_response->isWritable()) {
                        $g->openswoole_response->write($remaining);
                    }
                }
                return (new Response('', $g->status ?? 200));
            }

            if (is_int($object)) {
                ob_end_clean();
                $istatus = (int)$object;
                // Status-only returns from a handler (e.g. `return 404;`) route
                // through renderError so any registered custom error page fires —
                // Apache's `ErrorDocument` behavior for unhandled status codes.
                if ($istatus >= 400 && $istatus < 600) {
                    return App::instance()->renderError($istatus);
                }
                return (new Response('', $istatus));
            }

            $status = $g->status ?? 200;

            if ($object instanceof ResponseInterface) {
                ob_end_clean();
                return $object;
            }

            if (is_array($object) || is_object($object)) {
                ob_end_clean();
                $body = json_encode($object);
                $g->zealphp_response->header('Content-Type', 'application/json');
                if ($method === 'HEAD') {
                    $g->zealphp_response->header('Content-Length', (string)strlen($body));
                    return (new Response('', $status));
                }
                return (new Response($body, $status));
            }

            if (is_string($object)) {
                ob_end_clean();
                if ($method === 'HEAD') {
                    $g->zealphp_response->header('Content-Length', (string)strlen($object));
                    return (new Response('', $status));
                }
                return (new Response($object, $status));
            }

            // void + echo — only path that needs the buffered output
            $buffer = ob_get_clean();
            if ($method === 'HEAD') {
                $g->zealphp_response->header('Content-Length', (string)strlen($buffer));
                return (new Response('', $status));
            }
            return (new Response($buffer, $status));
        } catch (\Throwable|\OpenSwoole\ExitException $e) {
            if($e instanceof \OpenSwoole\ExitException){
                if($e->getStatus() == 0){
                    elog("HTTP Status: ".$g->status);
                    return (new Response(ob_get_clean()))->withStatus($g->status ?? 200);
                } else {
                    @ob_end_clean();
                    return App::instance()->renderError(500);
                }
            }
            // Inside an error-render recursion — rethrow so the outer renderError
            // catches and falls through to the default body for the ORIGINAL status.
            if (($g->error_render_depth ?? 0) > 0) {
                @ob_end_clean();
                throw $e;
            }
            // User-installed exception handler runs before the default error page.
            $excStack = $g->exception_handlers_stack ?? [];
            if (!empty($excStack)) {
                if (ob_get_level() > 0) { @ob_clean(); }
                ob_start();
                try { $excStack[count($excStack) - 1]($e); } catch (\Throwable $e2) { /* swallow */ }
                $body = ob_get_clean();
                @ob_end_clean();
                return (new Response($body))->withStatus($g->status ?? 500);
            }
            @ob_end_clean();
            elog(jTraceEx($e), "error");
            return App::instance()->renderError(500, $e);
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = G::instance();
        $uri = $g->server['REQUEST_URI'];
        $method = $g->server['REQUEST_METHOD'];
        $app = App::instance();

        // URL-decoded traversal/null-byte rejection BEFORE route matching.
        // Apache rejects these at the URI parse layer; we do the same so encoded
        // attacks (%2e%2e, %00, backslash) can't survive past pattern matching.
        $path = parse_url($uri, PHP_URL_PATH) ?? $uri;
        $decoded = rawurldecode($path);
        if (strpos($decoded, "\0") !== false
            || strpos($decoded, '\\') !== false
            || preg_match('#(^|/)\.\.(/|$)#', $decoded)) {
            return App::instance()->renderError(400);
        }

        // Apache PATH_INFO — `/script.php/extra/path` exposes `/extra/path` to
        // the script and rewrites REQUEST_URI to just the script. Triggers
        // only when the literal `.php/` appears in the URL (WordPress/Drupal
        // permalink style); implicit-extension routing is unaffected.
        if (App::$path_info && strpos($path, '.php/') !== false) {
            [$scriptPath, $extra] = explode('.php/', $path, 2);
            $scriptPath .= '.php';
            $abs = realpath(App::$cwd . '/public' . $scriptPath);
            if ($abs && is_file($abs) && strpos($abs, App::$cwd . '/public') === 0) {
                $g->server['PATH_INFO']       = '/' . $extra;
                $g->server['PATH_TRANSLATED'] = App::$cwd . '/public/' . $extra;
                $g->server['SCRIPT_NAME']     = $scriptPath;
                $qs = parse_url($uri, PHP_URL_QUERY);
                // When ignore_php_ext is on, the `.php` URI would hit the 403-block
                // route — strip the extension so the implicit file route resolves it.
                $rewritten = App::$ignore_php_ext
                    ? substr($scriptPath, 0, -4)
                    : $scriptPath;
                $uri = $rewritten . ($qs ? '?' . $qs : '');
                $g->server['REQUEST_URI'] = $uri;
            }
        }

        // OPTIONS — return allowed methods for this URI without running a handler
        if ($method === 'OPTIONS') {
            $allowed = ['OPTIONS'];
            foreach ($app->routesByMethod() as $m => $routes) {
                foreach ($routes as $route) {
                    if (preg_match($route['pattern'], $uri)) {
                        $allowed[] = $m;
                        if ($m === 'GET') $allowed[] = 'HEAD';
                        break;
                    }
                }
            }
            $allowed = array_unique($allowed);
            response_set_status(204);
            response_add_header('Allow', implode(', ', $allowed));
            return new Response('', 204);
        }

        // HEAD — match GET routes, run the handler, strip the body
        $matchMethod = ($method === 'HEAD') ? 'GET' : $method;

        $exactRoutes = $app->routesByExactMethod();
        if (isset($exactRoutes[$matchMethod][$uri])) {
            return $this->dispatchRoute($exactRoutes[$matchMethod][$uri], [], $method);
        }

        foreach ($app->routesByMethod()[$matchMethod] ?? [] as $route) {
            if (preg_match($route['pattern'], $uri, $matches)) {
                $params = array_filter($matches, fn($k) => !is_numeric($k), ARRAY_FILTER_USE_KEY);
                return $this->dispatchRoute($route, $params, $method);
            }
        }
        $fallback = App::getFallback();
        if ($fallback !== null) {
            return $this->dispatchRoute($fallback, [], $method);
        }
        return App::instance()->renderError(404);
    }
}

// class LoggingMiddleware implements MiddlewareInterface
// {
//     public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
//     {
//         $response = $handler->handle($request);
//         // elog("LoggingMiddleware process() received:".$response->getBody());
//         access_log($response->getStatusCode(), strlen($response->getBody()));
//         return $response;
//     }
// }

class TemplateUnavailableException extends \Exception {

	protected $message = "The template you are trying to include does not seem to exist. Please check the file name.
	Invalid error message. ";
	protected $code = 1002;

	public function __construct($message) {
		$this->message = $message;
		parent::__construct($this->message, $this->code);
	}

	public function __toString() {
		return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
	}

}


class LocationHeaderMiddleware implements MiddlewareInterface
{
    private $correctPort;

    public function __construct($correctPort)
    {
        $this->correctPort = $correctPort;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Location')) {
            $location = $response->getHeaderLine('Location');
            $parsedUrl = parse_url($location);

            if (isset($parsedUrl['host']) && isset($parsedUrl['port']) && $parsedUrl['port'] != $this->correctPort) {
                $parsedUrl['port'] = $this->correctPort;
                $newLocation = $this->buildUrl($parsedUrl);
                $response = $response->withHeader('Location', $newLocation);
            }
        }

        return $response;
    }

    private function buildUrl($parsedUrl)
    {
        $scheme   = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
        $host     = isset($parsedUrl['host']) ? $parsedUrl['host'] : '';
        $port     = isset($parsedUrl['port']) ? ':' . $parsedUrl['port'] : '';
        $path     = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        $query    = isset($parsedUrl['query']) ? '?' . $parsedUrl['query'] : '';
        $fragment = isset($parsedUrl['fragment']) ? '#' . $parsedUrl['fragment'] : '';

        return "$scheme$host$port$path$query$fragment";
    }
}

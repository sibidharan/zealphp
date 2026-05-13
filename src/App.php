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
    private static $fallback_handler = null;

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

        \uopz_set_return('header', \Closure::fromCallable('\ZealPHP\header'), true);
        \uopz_set_return('headers_list', \Closure::fromCallable('\ZealPHP\headers_list'), true);
        \uopz_set_return('setcookie', \Closure::fromCallable('\ZealPHP\setcookie') , true);
        \uopz_set_return('http_response_code', \Closure::fromCallable('\ZealPHP\http_response_code'), true);
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
            $__ref = new \ReflectionFunction($__result);
            $__params = [];
            foreach ($__ref->getParameters() as $__p) {
                $__params[] = $__args[$__p->getName()] ?? ($__p->isDefaultValueAvailable() ? $__p->getDefaultValue() : null);
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
            return basename($g->server['PHP_SELF'], '.php');
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
        // elog("Checking file: $abs_file inside ".self::$cwd);
        if (!$abs_file || strpos($abs_file, self::$cwd."/public") !== 0) {
            return false; //May be operating outside the public directory
        } else {
            return true;
        }
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
            'env'    => $g->env ?? $_ENV ?? [],
        ], JSON_UNESCAPED_SLASHES);

        $env = [];
        foreach ($g->server ?? [] as $k => $v) {
            if (is_string($v)) $env[$k] = $v;
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

    private function invokeFallbackOrNotFound(): int
    {
        if (self::$fallback_handler !== null) {
            $handler = self::$fallback_handler['handler'];
            $handler();
            $g = G::instance();
            return $g->status ?? 200;
        }
        echo("<pre>404 Not Found</pre>");
        return 404;
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
            if ($arg === 'start' || $arg === 'stop' || $arg === 'status') {
                $command = $arg;
                $i++;
                continue;
            }
            if ($arg === '-h' || $arg === '--help' || $arg === 'help') {
                self::cliHelp();
                exit(0);
            }
            if ($arg === '-p' || $arg === '--port') {
                $flags['port'] = (int)($argv[++$i] ?? 8080);
            } elseif ($arg === '-H' || $arg === '--host') {
                $flags['host'] = $argv[++$i] ?? '0.0.0.0';
            } elseif ($arg === '-w' || $arg === '--workers') {
                $flags['worker_num'] = max(1, (int)($argv[++$i] ?? 4));
            } elseif ($arg === '-d' || $arg === '--daemonize') {
                $flags['daemonize'] = true;
            } elseif ($arg === '--task-workers') {
                $flags['task_worker_num'] = max(0, (int)($argv[++$i] ?? 0));
            } elseif ($arg === '--pid-file') {
                $flags['pid_file'] = $argv[++$i] ?? null;
            }
            $i++;
        }

        switch ($command) {
            case 'stop':
                self::cliStop(self::resolvePidFile($flags));
                exit(0);
            case 'status':
                self::cliStatus(self::resolvePidFile($flags));
                exit(0);
            default:
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
        $port = $flags['port'] ?? (self::$instance ? self::$instance->port : 8080);
        return "/tmp/zealphp_{$port}.pid";
    }

    private static function cliStop(string $pidFile): void
    {
        if (!file_exists($pidFile)) {
            echo "ZealPHP is not running (no PID file: {$pidFile})\n";
            return;
        }
        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid <= 0 || !posix_kill($pid, 0)) {
            echo "ZealPHP is not running (stale PID file)\n";
            @unlink($pidFile);
            return;
        }
        echo "Stopping ZealPHP (pid {$pid})...\n";
        posix_kill($pid, SIGTERM);
        for ($i = 0; $i < 100; $i++) {
            if (!posix_kill($pid, 0)) {
                echo "Stopped.\n";
                return;
            }
            usleep(100000);
        }
        echo "Force killing...\n";
        posix_kill($pid, SIGKILL);
        usleep(200000);
        @unlink($pidFile);
    }

    private static function cliStatus(string $pidFile): void
    {
        if (!file_exists($pidFile)) {
            echo "ZealPHP is not running\n";
            exit(1);
        }
        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid <= 0 || !posix_kill($pid, 0)) {
            echo "ZealPHP is not running (stale PID file)\n";
            @unlink($pidFile);
            exit(1);
        }
        echo "ZealPHP is running (pid {$pid})\n";
        exit(0);
    }

    private static function cliHelp(): void
    {
        echo <<<'HELP'
Usage: php app.php [command] [options]

Commands:
  start    Start the server (default)
  stop     Stop a running server
  status   Check if server is running

Options:
  -p, --port N         Listen port (default: from App::init)
  -H, --host ADDR      Listen address (default: 0.0.0.0)
  -w, --workers N      Number of worker processes
  -d, --daemonize      Run in background
  --task-workers N     Number of task workers (default: 0)
  --pid-file PATH      Custom PID file path
  -h, --help           Show this help message

Examples:
  php app.php                     Start with defaults
  php app.php start -p 9501 -d   Start daemonized on port 9501
  php app.php stop               Stop the running server
  php app.php status             Check if server is running

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
        }
        if (!empty($cliOverrides)) {
            $settings = array_merge($settings ?? [], $cliOverrides);
        }

        App::$coproc_implicit_request_handler = App::$superglobals;
        if(!App::$superglobals){
            co::set(['hook_flags'=> \OpenSwoole\Runtime::HOOK_ALL]);
            \OpenSwoole\Runtime::enableCoroutine(\OpenSwoole\Runtime::HOOK_ALL);
        }
        $default_settings = [
            'enable_static_handler' => true,
            'document_root' => self::$cwd . '/public',
            'enable_coroutine' =>  !self::$superglobals,
            // Runtime compression is owned by OpenSwoole. Do not also register
            // CompressionMiddleware unless this setting is disabled.
            'http_compression' => true,
            'pid_file' => "/tmp/zealphp_{$this->port}.pid",
            'task_worker_num' => 0,
            'task_enable_coroutine' => true,
            // Suppress NOTICE-level messages from OpenSwoole internals (e.g. ERRNO 1005
            // "session does not exist" when SSE/WS clients disconnect mid-stream).
            // Pass 'log_level' => 0 in $app->run() settings to restore full debug output.
            'log_level' => 4,  // 0=DEBUG 1=TRACE 2=INFO 3=NOTICE 4=WARNING 5=ERROR 6=NONE
        ];
        // elog("Initializing ZealPHP server at http://{$this->host}:{$this->port}");
        // WebSocket\Server extends HTTP\Server — all HTTP routes still work
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

        # Implicit route for including APIs
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

        # Implicit route for ignoring PHP extensions

        if(App::$ignore_php_ext){
            $this->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
                echo("<pre>403 Forbidden</pre>");
                return(403);
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
                    echo("<pre>403 Forbidden</pre>");
                    return(403);
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
                    echo("<pre>403 Forbidden</pre>");
                    return 403;
                }
            } else if(is_dir(self::$cwd."/public/".$file)){
                $abs_file = realpath(self::$cwd."/public/".$file."/index.php");
                if(file_exists($abs_file)){
                    if ($this->includeCheck($abs_file)){
                        $g->server['PHP_SELF'] = '/'.$file.'/index.php';
                        $g->server['SCRIPT_NAME'] = '/'.$file.'/index.php';
                        $g->server['SCRIPT_FILENAME'] = $abs_file;
                        $__r = App::includeFile($abs_file);
                    if ($__r instanceof \Generator) return $__r;
                    } else {
                        echo("<pre>403 Forbidden</pre>");
                        return 403;
                    }
                } else {
                    return $this->invokeFallbackOrNotFound();
                }
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
                    echo("<pre>403 Forbidden</pre>");
                    return(403);
                }
            } else if(is_dir(self::$cwd."/public/".$dir.'/'.$uri)){
                $abs_path = self::$cwd."/public/".$dir.'/'.$uri."/index.php";
                if(file_exists($abs_path)){
                    if ($this->includeCheck($abs_path)){
                        $g->server['PHP_SELF'] = '/'.$dir.'/'.$uri.'/index.php';
                        $g->server['SCRIPT_NAME'] = '/'.$dir.'/'.$uri.'/index.php';
                        $g->server['SCRIPT_FILENAME'] = $abs_path;
                        $__r = App::includeFile($abs_path);
                        if ($__r instanceof \Generator) return $__r;
                    } else {
                        echo("<pre>403 Forbidden</pre>");
                        return(403);
                       
                    }
                } else {
                    return $this->invokeFallbackOrNotFound();
                }
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

        $server->on("request",new $SessionManager(function(\ZealPHP\HTTP\Request $request, \ZealPHP\HTTP\Response $response) use ($server) {
            $g = G::instance();
            $g->status = 200; //Unless changed by the handler
            // $_GET alternative
            $g->get = $request->get ?? [];
            // $_POST alternative
            $g->post = $request->post ?? [];

            //$_REQUEST alternative
            $g->request = array_merge($g->get, $g->post);

            // $_COOKIE alternative
            $g->cookie = $request->cookie ?? [];

            // $_FILES alternative
            $g->files = [];
            if (!empty($request->files)) {
                $g->files = $request->files;
            }

            // $_SERVER alternative
            $g->server = [];
            if (!empty($request->server)) {
                foreach ($request->server as $key => $value) {
                    $g->server[strtoupper($key)] = $value;
                }
            }
            // Headers go into $_SERVER as HTTP_ variables
            if (!empty($request->header)) {
                foreach ($request->header as $key => $value) {
                    $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($key));
                    $g->server[$headerKey] = $value;
                }
            }


            // Common server vars typically set by web servers:
            if (!isset($g->server['REQUEST_METHOD'])) {
                $g->server['REQUEST_METHOD'] = 'GET';
            }

            // Check if X-HTTP-Method-Override header is present
            if ($g->server['REQUEST_METHOD'] === 'POST' && !empty($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
                $g->server['REQUEST_METHOD'] = $g->server['HTTP_X_HTTP_METHOD_OVERRIDE'];
            }

            if (!isset($g->server['REQUEST_URI'])) {
                $g->server['REQUEST_URI'] = '/';
            }
            if (!isset($g->server['SCRIPT_NAME'])) {
                $g->server['SCRIPT_NAME'] = '/app.php';
            }
            if (!isset($g->server['SERVER_NAME'])) {
                $g->server['SERVER_NAME'] = $g->server['HTTP_HOST'] ?? site_host();
            }
            if (!isset($g->server['DOCUMENT_ROOT'])) {
                $g->server['DOCUMENT_ROOT'] = self::$cwd . '/public';
            }
            if (!isset($g->server['PHP_SELF'])) {
                $g->server['PHP_SELF'] = App::$default_php_self;
            }

            if (!isset($g->server['SCRIPT_FILENAME'])) {
                $g->server['SCRIPT_FILENAME'] = $g->server['DOCUMENT_ROOT'] . $g->server['PHP_SELF'];
            }

            if (!isset($g->server['SERVER_SOFTWARE'])) {
                $g->server['SERVER_SOFTWARE'] = 'ZealPHP/dev (' . php_uname('s') . ') PHP/' . phpversion();
            }

            $serverRequest  = \OpenSwoole\Core\Psr\ServerRequest::from($request->parent);

            try {
                $serverResponse = App::middleware()->handle($serverRequest);
                access_log($serverResponse->getStatusCode(), strlen($serverResponse->getBody()));
                // Streaming responses (stream(), sse(), Generator yield) call
                // $response->parent->end() themselves — skip emit() to avoid the
                // "http response is unavailable" warning on an already-ended response.
                if ($response->parent->isWritable()) {
                    $response->flush();
                    \OpenSwoole\Core\Psr\Response::emit($response->parent, $serverResponse->withHeader('X-Powered-By', 'ZealPHP + OpenSwoole'));
                }
            } catch (\Throwable|\OpenSwoole\ExitException $e) {
                elog(jTraceEx($e), "error");
                $response->parent->status(500);                    
                if (App::$display_errors) {
                    $g->status = 500;
                    $response->parent->end("<pre>".jTraceEx($e)."</pre>");
                } else {
                    $g->status = 500;
                    $response->parent->end("<pre> Internal Server Error </pre>");
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
            $route = App::instance()->wsRoutes()[$path] ?? null;
            if ($route && $route['open']) {
                ($route['open'])($server, $request, $g);
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
                $g->zealphp_response->flush();
                foreach ($object as $chunk) {
                    if (!$g->openswoole_response->isWritable()) break;
                    $g->openswoole_response->write((string)$chunk);
                    \OpenSwoole\Coroutine::sleep(0);
                }
                if ($g->openswoole_response->isWritable()) {
                    $g->openswoole_response->end();
                }
                return (new Response('', $g->status ?? 200));
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
                    $body = json_encode($object, JSON_PRETTY_PRINT);
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
                    return (new Response(''))->withStatus(500);
                }
            }
            if (App::$display_errors) {
                return (new Response("<pre>".jTraceEx($e)."</pre>"))->withStatus(500);
            } else {
                return (new Response("<pre>500 Internal Server Error</pre>"))->withStatus(500);
            }
        }
    }

    private function dispatchRoute(array $route, array $params, string $method): ResponseInterface
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

            // Generator streaming — yield chunks directly to the client
            if ($object instanceof \Generator) {
                ob_end_clean();
                $g->zealphp_response->flush();
                foreach ($object as $chunk) {
                    if (!$g->openswoole_response->isWritable()) break;
                    $g->openswoole_response->write((string)$chunk);
                    \OpenSwoole\Coroutine::sleep(0);
                }
                if ($g->openswoole_response->isWritable()) {
                    $g->openswoole_response->end();
                }
                return (new Response('', $g->status ?? 200));
            }

            // stream() / sse() — response body already sent via write()
            if ($g->_streaming ?? false) {
                ob_end_clean();
                return (new Response('', $g->status ?? 200));
            }

            if(is_int($object)){
                $status = (int)$object;
            } else {
                $status = $g->status ?? 200;;
            }

            if($object instanceof ResponseInterface){
                ob_end_clean();
                $body = $object->getBody();
                $body->rewind();
                elog("ResponseMiddleware process() received ResponseInterface > ".$body->getContents());
                return $object;
            }

            if(is_array($object) or is_object($object)){
                response_add_header('Content-Type', 'application/json');
                echo json_encode($object, JSON_PRETTY_PRINT);
            } else if (is_string($object)){
                echo $object;
            }

            $buffer = ob_get_clean();
            // HEAD — return headers only, body stripped (Content-Length preserved)
            if ($method === 'HEAD') {
                response_add_header('Content-Length', (string)strlen($buffer));
                return (new Response('', $status));
            }
            return (new Response($buffer, $status));
        } catch (\Throwable|\OpenSwoole\ExitException $e) {
            if($e instanceof \OpenSwoole\ExitException){
                if($e->getStatus() == 0){
                    elog("HTTP Status: ".$g->status);
                    return (new Response(ob_get_clean()))->withStatus($g->status ?? 200);
                } else {
                    return (new Response(ob_get_clean()))->withStatus(500);
                }
            }
            elog(jTraceEx($e), "error");
            if (App::$display_errors) {
                // print the error message to the error log
                return (new Response("<pre>".jTraceEx($e)."</pre>"))->withStatus(500);
            } else {
                return (new Response("<pre>500 Internal Server Error</pre>"))->withStatus(500);
            }
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $g = G::instance();
        $uri = $g->server['REQUEST_URI'];
        $method = $g->server['REQUEST_METHOD'];
        $app = App::instance();

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
        return (new Response('<pre>404 Not Found</pre>'))->withStatus(404);
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

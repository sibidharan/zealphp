<?php
namespace ZealPHP;


require_once 'API.class.php';
require_once 'REST.class.php';
require_once 'Session.class.php';
require_once 'SessionManager.class.php';

use ZealPHP\Session;
use ZealPHP\Session\SessionManager;
use ZealPHP\API;

global $__start;

function zapi($filename){
    return basename($filename, '.php');
}

function zlog($log, $tag = "system", $filter = null, $invert_filter = false)
{
    if ($filter != null and !StringUtils::str_contains($_SERVER['REQUEST_URI'], $filter)) {
        return;
    }
    if ($filter != null and $invert_filter) {
        return;
    }

    // if(get_class(Session::getUser()) == "User") {
    //     $user = Session::getUser()->getUsername();
    // } else {
    //     $user = 'worker';
    // }

    if (!isset($_SERVER['REQUEST_URI'])) {
        $_SERVER['REQUEST_URI'] = 'cli';
    }

    $bt = debug_backtrace();
    $caller = array_shift($bt);

    if ((in_array($tag, ["system", "fatal", "error", "warning", "info", "debug"]))) {
        $date = date('l jS F Y h:i:s A');
        //$date = date('h:i:s A');
        if (is_object($log)) {
            $log = purify_array($log);
        }
        if (is_array($log)) {
            $log = json_encode($log, JSON_PRETTY_PRINT);
        }
        if (error_log(
            '[*] #' . $tag . ' [' . $date . '] ' . " Request ID: $_SESSION[UNIQUE_REQUEST_ID]\n" .
                '    URL: ' . $_SERVER['REQUEST_URI'] . " \n" .
                '    Caller: ' . $caller['file'] . ':' . $caller['line'] . "\n" .
                '    Timer: ' . get_current_render_time() . ' sec' . " \n" .
                "    Message: \n" . indent($log) . "\n\n"
        )) {
        }
    }
}

class App
{
    protected $routes = [];
    protected $host;
    protected $port;
    static $cwd;
    private static $instance = null;

    private function __construct($cwd = __DIR__, $host = '0.0.0.0', $port = 8080)
    {
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
    }

    public static function init($cwd = __DIR__, $host = '0.0.0.0', $port = 8080)
    {
        if (self::$instance == null) {
            self::$instance = new App($cwd, $host, $port);
        }
        return self::$instance;
    }

    public static function instance()
    {
        return self::$instance;
    }

    // Prevent the instance from being cloned.
    private function __clone()
    {
    }

    // Prevent from being unserialized.
    public function __wakeup()
    {
    }

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
            'pattern' => $pattern,
            'methods' => array_map('strtoupper', $methods),
            'handler' => $handler,
            // You could also store other options like:
            // 'endpoint' => $options['endpoint'] ?? null,
            // 'strict_slashes' => $options['strict_slashes'] ?? true,
            // ...and handle them later in matching logic
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
            'pattern' => $pattern,
            'methods' => array_map('strtoupper', $methods),
            'handler' => $handler,
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
            'pattern' => $pattern,
            'methods' => array_map('strtoupper', $methods),
            'handler' => $handler,
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
            'pattern' => $regex,
            'methods' => array_map('strtoupper', $methods),
            'handler' => $handler,
        ];
    }

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

    public static function render($_template = 'index', $_data = [])
    {
        $_source = Session::getCurrentFile(null);
        extract($_data, EXTR_SKIP);
        //This function returns the current script to build the template path.
        $_general = strpos($_template, '/') === 0;
        if ($_template == '_error') {
            include self::$cwd . '/template/' . $_template . '.php';
        } elseif ($_general) {
            if (!file_exists(self::$cwd . '/template/' . $_template . '.php')) {
                $bt = debug_backtrace();
                $caller = array_shift($bt);
                throw new TemplateUnavailableException("The template $_template does not exist on line " . $caller['line'] . " in file " . $caller['file'] . ".");
            }
            include self::$cwd . '/template/' . $_template . '.php';
        } else {
            if (!file_exists(self::$cwd . '/template/' . $_source . '/' . $_template . '.php')) {
                $bt = debug_backtrace();
                $caller = array_shift($bt);
                throw new TemplateUnavailableException("The template $_template does not exist on line " . $caller['line'] . " in file " . $caller['file'] . ".");
            }
            include self::$cwd . '/template/' . $_source . '/' . $_template . '.php';
        }
    }

    public function includeCheck($abs_file){
        // error_log("Checking file: $abs_file inside ".self::$cwd);
        if (!$abs_file || strpos($abs_file, self::$cwd."/public") !== 0) {
            return false; //May be operating outside the public directory
        } else {
            return true;
        }
    }

    public function run($settings = null)
    {
        $default_settings = [
            'enable_static_handler' => true,
            'document_root' => self::$cwd . '/public',
            'enable_coroutine' => true,
        ];
        $server = new \Swoole\HTTP\Server($this->host, $this->port);
        if ($settings == null){
            $server->set($default_settings);
        } else {
            $settings = array_merge($default_settings, $settings);
            $server->set($settings);
        }

        # Include all files in route directory and its sub directories

        $route_files = glob(self::$cwd."/route/*.php");
        foreach ($route_files as $route_file) {
            error_log("Including route file: $route_file");
            include $route_file;
        }

        # Implicit route for including APIs
        $this->nsPathRoute('api', "{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ], function($rquest, $response, $request){
            $api = new API($request, $response, self::$cwd);
            try {
                $api->processApi("", $rquest);
            } catch (\Exception $e){
                $api->die($e);
            }
        });

        
        $this->nsPathRoute('api', "{module}/{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ], function($module, $rquest, $response, $request){
            $api = new API($request, $response, self::$cwd);
            try {
                $api->processApi($module, $rquest);
            } catch (\Exception $e){
                $api->die($e);
            }
        });

        # Implicit route for ignoring PHP extensions

        $this->patternRoute('/.*\.php', ['methods' => ['GET', 'POST']], function($response) {
            $response->status(403);
            $response->write("<h1>403 Forbidden</h1>");
        });

        # Implicit route for index.php

        $this->route('/', function($response){
            $file = 'index';
            $_SERVER['PHP_SELF'] = '/'.$file.'.php';
            $abs_file = self::$cwd."/public/".$file.".php";
            if(file_exists($abs_file)){
                include $abs_file;
            } else {
                //TODO: Can load user page here if file not found
                $response->status(404);
                echo("<pre>404 Not Found</pre>");
            }
        });

        # Gobal route for all root in the public directory
        $this->route('/{file}', function($file, $response){
            $_SERVER['PHP_SELF'] = '/'.$file.'.php';
            $abs_file = realpath(self::$cwd."/public/".$file.'.php');
            if(file_exists($abs_file)){
                if ($this->includeCheck($abs_file)){
                    include $abs_file;
                } else {
                    $response->status(403);
                    echo("<pre>403 Forbidden</pre>");
                }
            } else if(is_dir(self::$cwd."/public/".$file)){
                $abs_file = realpath(self::$cwd."/public/".$file."/index.php");
                if(file_exists($abs_file)){
                    if ($this->includeCheck($abs_file)){
                        include $abs_file;
                    } else {
                        $response->status(403);
                        echo("<pre>403 Forbidden here</pre>");
                    }
                } else {
                    $response->status(404);
                    echo("<pre>404 Not Found</pre>");
                }
            } else {
                //TODO: Can load user page here if file not found
                $response->status(404);
                echo("<pre>404 Not Found</pre>");
            }
        });

        # Gobal route for all files in the public directory
        $this->nsPathRoute('{file}', '{path}', function($file, $path, $response){
            // error_log("File: $file, Path: $path");
            $_SERVER['PHP_SELF'] = '/'.$file.'/'.$path.'.php';
            $abs_file = realpath(self::$cwd."/public/".$file.'/'.$path.'.php');
            // error_log("Abs File: $abs_file");
            if(file_exists($abs_file)){
                include $abs_file;
            } else if(is_dir(self::$cwd."/public/".$file.'/'.$path)){
                $abs_path = self::$cwd."/public/".$file.'/'.$path."/index.php";
                if(file_exists($abs_path)){
                    include $abs_path;
                } else {
                    $response->status(404);
                    echo("<pre>404 Not Found</pre>");
                }
            } else {
                //TODO: Can load user page here if file not found
                $response->status(404);
                echo("<pre>404 Not Found</pre>");
            }
        });

        $server->on("request", new SessionManager(function($request, $response) {
            // $_GET
            unset($_GET);
            $_GET = $request->get ?? [];

            // $_POST
            unset($_POST);
            $_POST = $request->post ?? [];

            //$_REQUEST
            unset($_REQUEST);
            $_REQUEST = array_merge($_GET, $_POST);

            // $_COOKIE
            unset($_COOKIE);
            $_COOKIE = $request->cookie ?? [];

            // $_FILES
            unset($_FILES);
            $_FILES = [];
            if (!empty($request->files)) {
                $_FILES = $request->files;
            }

            // $_SERVER
            unset($_SERVER);
            $_SERVER = [];
            if (!empty($request->server)) {
                foreach ($request->server as $key => $value) {
                    $_SERVER[strtoupper($key)] = $value;
                }
            }
            // Headers go into $_SERVER as HTTP_ variables
            if (!empty($request->header)) {
                foreach ($request->header as $key => $value) {
                    $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($key));
                    $_SERVER[$headerKey] = $value;
                }
            }

            // Common server vars typically set by web servers:
            if (!isset($_SERVER['REQUEST_METHOD'])) {
                $_SERVER['REQUEST_METHOD'] = 'GET';
            }
            if (!isset($_SERVER['REQUEST_URI'])) {
                $_SERVER['REQUEST_URI'] = '/';
            }
            if (!isset($_SERVER['SCRIPT_NAME'])) {
                $_SERVER['SCRIPT_NAME'] = '/app.php';
            }
            if (!isset($_SERVER['SERVER_NAME'])) {
                $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
            }
            if (!isset($_SERVER['DOCUMENT_ROOT'])) {
                $_SERVER['DOCUMENT_ROOT'] = self::$cwd . '/public';
            }
            if (!isset($_SERVER['PHP_SELF'])) {
                $_SERVER['PHP_SELF'] = '/app.php';
            }

            $uri = $_SERVER['REQUEST_URI'];
            $method = $_SERVER['REQUEST_METHOD'];

            foreach ($this->routes as $route) {
                // Check if method matches
                if (!in_array($method, $route['methods'])) {
                    continue;
                }

                // Check if URI matches
                if (preg_match($route['pattern'], $uri, $matches)) {
                    // error_log("Matched route: $uri, $route[pattern]");
                    $params = array_filter($matches, fn($k) => !is_numeric($k), ARRAY_FILTER_USE_KEY);

                    $handler = $route['handler'];

                    // Reflect the handler parameters and inject them dynamically
                    $reflection = is_array($handler)
                        ? new \ReflectionMethod($handler[0], $handler[1])
                        : new \ReflectionFunction($handler);

                    $invokeArgs = [];
                    foreach ($reflection->getParameters() as $param) {
                        $pname = $param->getName();
                        if (isset($params[$pname])) {
                            $invokeArgs[] = $params[$pname];
                        } else if ($pname == 'app' || $pname == 'self'){
                            $invokeArgs[] = $this;
                        } else if ($pname == 'request' || $pname == 'req'){
                            $invokeArgs[] = $request;
                        } else if ($pname == 'response' || $pname == 'res'){
                            $invokeArgs[] = $response;
                        } else {
                            $invokeArgs[] = $param->isDefaultValueAvailable() 
                                ? $param->getDefaultValue() 
                                : null;
                        }
                    }
                    ob_start();
                    call_user_func_array($handler, $invokeArgs);
                    $buffer = ob_get_clean();
                    $response->end($buffer);
                    return;
                }
            }

            // 404 if no match
            $response->status(404);
            $response->end("<pre>404 Not Found</pre>");
        }));
        error_log("ZealPHP server running at http://{$this->host}:{$this->port} with ".count($this->routes)." routes");
        $server->start();
    }
}

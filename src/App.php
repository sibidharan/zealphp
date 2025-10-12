<?php
namespace ZealPHP;

use ZealPHP\ZealAPI;
use ZealPHP\Session;
use function ZealPHP\elog;
use function ZealPHP\jTraceEx;

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

    public function addMiddleware(\Psr\Http\Server\MiddlewareInterface $middleware){
        self::$middleware_wait_stack[] = $middleware;
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
     * - pid_file: string (default: '/tmp/zealphp.pid')
     *
     * This method initializes the Swoole HTTP server with the provided settings or default settings.
     * It includes all route files from the route directory and sets up various implicit and global routes.
     * It also handles the request and response lifecycle, including setting up superglobals ($_GET, $_POST, etc.)
     * and matching the request URI to the defined routes.
     */
    public function run($settings = null)
    {
        App::$coproc_implicit_request_handler = App::$superglobals;
        if(!App::$superglobals){
            co::set(['hook_flags'=> \OpenSwoole\Runtime::HOOK_ALL]);
        }
        $default_settings = [
            'enable_static_handler' => true,
            'document_root' => self::$cwd . '/public',
            'enable_coroutine' =>  !self::$superglobals,
            'pid_file' => '/tmp/zealphp.pid',
            'task_worker_num' => 4,
            // 'task_enable_coroutine' => true,
        ];
        // elog("Initializing ZealPHP server at http://{$this->host}:{$this->port}");
        self::$server = $server = new \OpenSwoole\HTTP\Server($this->host, $this->port);
        if ($settings == null){
            $server->set($default_settings);
        } else {
            $settings = array_merge($default_settings, $settings);
            $settings['enable_coroutine'] = !self::$superglobals;
            $server->set($settings);
        }

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
                    if(self::$coproc_implicit_request_handler){
                        echo prefork_request_handler(function() use ($abs_file){
                            // throw new \Exception("Include: $abs_file");
                            include $abs_file;
                        });
                    } else {
                        include $abs_file;
                    }
                } else {
                    echo("<pre>403 Forbidden</pre>");
                    return(403);
                }
            } else {
                //TODO: Can load user page here if file not found
                echo("<pre>404 Not Found</pre>");
                return(404);
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
                    if(self::$coproc_implicit_request_handler){
                        echo prefork_request_handler(function() use ($abs_file){
                            // throw new \Exception("Include: $abs_file");
                            include $abs_file;
                        });
                    } else {
                        include $abs_file;
                    }
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
                        if(self::$coproc_implicit_request_handler){
                            echo prefork_request_handler(function() use ($abs_file){
                                // throw new \Exception("Include: $abs_file");
                                include $abs_file;
                            });
                        } else {
                            include $abs_file;
                        }
                    } else {
                        echo("<pre>403 Forbidden</pre>");
                        return 403;
                    }
                } else {
                    echo("<pre>404 Not Found</pre>");
                    return 404;
                }
            } else {
                //TODO: Can load user page here if file not found
                echo("<pre>404 Not Found</pre>");
                return 404;
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
                    if(self::$coproc_implicit_request_handler){
                        echo prefork_request_handler(function() use ($abs_file){
                            // throw new \Exception("Include: $abs_file");
                            include $abs_file;
                        });
                    } else {
                        include $abs_file;
                    }
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
                        if(self::$coproc_implicit_request_handler){
                            echo prefork_request_handler(function() use ($abs_path){
                                // throw new \Exception("Include: $abs_path");
                                include $abs_path;
                            });
                        } else {
                            include $abs_path;
                        }
                    } else {
                        echo("<pre>403 Forbidden</pre>");
                        return(403);
                       
                    }
                } else {
                    echo("<pre>404 Not Found</pre>");
                    return(404);
                   
                }
            } else {
                //TODO: Can load user page here if file not found
                echo("<pre>404 Not Found</pre>");
                return(404);
                
            }
        });
        
        $server->on('task', function ($server, $id, $rid, $data) {
            $handler = $data['handler'];
            $_func = basename($handler);
            if(file_exists(App::$cwd.$handler.'.php')){
                # TODO: Include check for task handlers
                include App::$cwd.$handler.'.php';

                # call the function from the included file
                $result = $$_func(...$data['args']);
                unset($$_func);
            } else {
                # TODO: Should throw exception?
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
                $g->server['SERVER_NAME'] = $g->server['HTTP_HOST'] ?? 'localhost';
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
                $response->flush();
                \OpenSwoole\Core\Psr\Response::emit($response->parent, $serverResponse->withHeader('X-Powered-By', 'ZealPHP + OpenSwoole'));
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

        elog("ZealPHP server running at http://{$this->host}:{$this->port} with ".count($this->routes)." routes");
        $server->start();
    }

    public static function middleware(){
        return self::$middleware_stack;
    }
}

class ResponseMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // elog("ResponseMiddleware process()");
        stream_wrapper_unregister("php");
        stream_wrapper_register("php", \ZealPHP\IOStreamWrapper::class);
        $g = G::instance();
        $uri = $g->server['REQUEST_URI'];
        $method = $g->server['REQUEST_METHOD'];
        $app = App::instance();
        foreach ($app->routes() as $route) {
            // Check if method matches
            if (!in_array($method, $route['methods'])) {
                continue;
            }

            // Check if URI matches
            if (preg_match($route['pattern'], $uri, $matches)) {
                // elog("Matched route: $uri, $route[pattern]");
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
                    } else if ($pname == 'app'){
                        $invokeArgs[] = $this;
                    } else if ($pname == 'request'){
                        $invokeArgs[] = $g->zealphp_request;
                    } else if ($pname == 'response'){
                        $invokeArgs[] = $g->zealphp_response;
                    } else {
                        $invokeArgs[] = $param->isDefaultValueAvailable() 
                            ? $param->getDefaultValue() 
                            : null;
                    }
                }
                try {
                    ob_start();
                    $object = call_user_func_array($handler, $invokeArgs);
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
                break;
            }
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
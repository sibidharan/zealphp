<?php
namespace ZealPHP;

use ZealPHP\ZealAPI;
use ZealPHP\Session;
use ZealPHP\Session\CoSessionManager;
use function ZealPHP\elog;
use function ZealPHP\jTraceEx;
class App
{
    protected $routes = [];
    protected $host;
    protected $port;
    static $cwd;
    static $server;
    private static $instance = null;
    public static $display_errors = true;
    public static $superglobals = true;

    private function __construct($host = '0.0.0.0', $port = 8080,$cwd = __DIR__)
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
            $cwd = dirname(debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 1)[0]['file']);
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
     * It takes PHP_SELF into account and uses it as the source folder to look for templates unless the $_template starts with /.
     * Starting the $_template with / tells the render function to look for the template from the root of the template folder.
     *
     * @param string $_template The name of the template to render. Defaults to 'index'.
     * @param array $_data An associative array of data to pass to the template. Defaults to an empty array.
     * @throws TemplateUnavailableException if the template does not exist.
     * @return void
     */
    public static function render($_template = 'index', $_data = [])
    {
        $_source = Session::getCurrentFile(null);
        elog("Rendering template: $_template from $_source");
        extract($_data, EXTR_SKIP);
        //This function returns the current script to build the template path.
        $_general = strpos($_template, '/') === 0;
        if ($_template == '_error') {
            include self::$cwd . '/template/' . $_template . '.php';
        } elseif ($_general) {
            if (!file_exists(self::$cwd . '/template/' . $_template . '.php')) {
                $bt = debug_backtrace();
                $caller = array_shift($bt);
                throw new TemplateUnavailableException("The template $_template does not exist in file " . str_replace(App::$cwd, '', $caller['file']) . ":" . $caller['line'] );
            }
            include self::$cwd . '/template/' . $_template . '.php';
        } else {
            if (!file_exists(self::$cwd . '/template/' . $_source . '/' . $_template . '.php')) {
                $bt = debug_backtrace();
                $caller = array_shift($bt);
                throw new TemplateUnavailableException("The template $_template does not exist in file " . str_replace(App::$cwd, '', $caller['file']) . ":" . $caller['line'] );
            }
            include self::$cwd . '/template/' . $_source . '/' . $_template . '.php';
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
        $g = G::getInstance();
        $default_settings = [
            'enable_static_handler' => true,
            'document_root' => self::$cwd . '/public',
            'enable_coroutine' =>  !self::$superglobals,
            'pid_file' => '/tmp/zealphp.pid',
            'task_worker_num' => 4,
            // 'task_enable_coroutine' => true,
        ];
        // elog("Initializing ZealPHP server at http://{$this->host}:{$this->port}");
        self::$server = $server = new \Swoole\HTTP\Server($this->host, $this->port);
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
            elog("Including route file: ".str_replace(App::$cwd, '', $route_file));
            include $route_file;
        }

        # Implicit route for including APIs
        $this->nsPathRoute('api', "{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ], function($rquest, $response, $request){
            $api = new ZealAPI($request, $response, self::$cwd);
            try {
                $api->processApi("", $rquest);
            } catch (\Exception $e){
                $api->die($e);
            }
        });

        
        $this->nsPathRoute('api', "{module}/{rquest}", [
            'methods' => ['GET', 'POST', 'PUT', 'DELETE']
        ], function($module, $rquest, $response, $request){
            $api = new ZealAPI($request, $response, self::$cwd);
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
            $g = G::getInstance();
            $file = 'index';
            $g->server['PHP_SELF'] = '/'.$file.'.php';
            // if(self::$superglobals){
            //     $_SERVER['PHP_SELF'] = $g->server['PHP_SELF'];
            // }
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
        $this->route('/{file}/?', function($file, $response){
            $g = G::getInstance();
            $abs_file = realpath(self::$cwd."/public/".$file.'.php');
            if(file_exists($abs_file)){
                if ($this->includeCheck($abs_file)){
                    $g->server['PHP_SELF'] = '/'.$file.'.php';
                    // if(self::$superglobals){
                    //     $_SERVER['PHP_SELF'] = $g->server['PHP_SELF'];
                    // }
                    include $abs_file;
                } else {
                    $response->status(403);
                    echo("<pre>403 Forbidden</pre>");
                }
            } else if(is_dir(self::$cwd."/public/".$file)){
                $abs_file = realpath(self::$cwd."/public/".$file."/index.php");
                if(file_exists($abs_file)){
                    if ($this->includeCheck($abs_file)){
                        $g->server['PHP_SELF'] = '/'.$file.'/index.php';
                        // if(self::$superglobals){
                        //     $_SERVER['PHP_SELF'] =  $g->server['PHP_SELF'];
                        // }
                        include $abs_file;
                    } else {
                        $response->status(403);
                        echo("<pre>403 Forbidden</pre>");
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

        # Global route for all directories in the public directory
        $this->nsPathRoute('{dir}', '{uri}/?', function($dir, $uri, $response){
            $g = G::getInstance();
            // elog("Directory: $dir, URI: $uri");
            $abs_file = realpath(self::$cwd."/public/".$dir.'/'.$uri.'.php');
            // elog("Abs File: $abs_file");
            if(file_exists($abs_file)){
                if ($this->includeCheck($abs_file)){
                    $g->server['PHP_SELF'] = '/'.$dir.'/'.$uri.'.php';
                    // if(self::$superglobals){
                    //     $_SERVER['PHP_SELF'] =  $g->server['PHP_SELF'];
                    // }
                    include $abs_file;
                } else {
                    $response->status(403);
                    echo("<pre>403 Forbidden</pre>");
                }
            } else if(is_dir(self::$cwd."/public/".$dir.'/'.$uri)){
                $abs_path = self::$cwd."/public/".$dir.'/'.$uri."/index.php";
                if(file_exists($abs_path)){
                    if ($this->includeCheck($abs_path)){
                        $g->server['PHP_SELF'] = '/'.$dir.'/'.$uri.'/index.php';
                        // if(self::$superglobals){
                        //     $_SERVER['PHP_SELF'] =  $g->server['PHP_SELF'];
                        // }
                        include $abs_path;
                    } else {
                        $response->status(403);
                        echo("<pre>403 Forbidden</pre>");
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
        $server->on("request",new $SessionManager(function($request, $response) use ($server) {
            // Set up superglobals
            //if(self::$superglobals){
            //     // $_GET
            //     unset($_GET);
            //     $_GET = $request->get ?? [];

            //     // $_POST
            //     unset($_POST);
            //     $_POST = $request->post ?? [];

            //     //$_REQUEST
            //     unset($_REQUEST);
            //     $_REQUEST = array_merge($_GET, $_POST);

            //     // $_COOKIE
            //     unset($_COOKIE);
            //     $_COOKIE = $request->cookie ?? [];

            //     // $_FILES
            //     unset($_FILES);
            //     $_FILES = [];
            //     if (!empty($request->files)) {
            //         $_FILES = $request->files;
            //     }

            //     // $_SERVER
            //     unset($_SERVER);
            //     $_SERVER = [];
            //     if (!empty($request->server)) {
            //         foreach ($request->server as $key => $value) {
            //             $_SERVER[strtoupper($key)] = $value;
            //         }
            //     }
            //     // Headers go into $_SERVER as HTTP_ variables
            //     if (!empty($request->header)) {
            //         foreach ($request->header as $key => $value) {
            //             $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($key));
            //             $_SERVER[$headerKey] = $value;
            //         }
            //     }

            //     // Common server vars typically set by web servers:
            //     if (!isset($_SERVER['REQUEST_METHOD'])) {
            //         $_SERVER['REQUEST_METHOD'] = 'GET';
            //     }
            //     if (!isset($_SERVER['REQUEST_URI'])) {
            //         $_SERVER['REQUEST_URI'] = '/';
            //     }
            //     if (!isset($_SERVER['SCRIPT_NAME'])) {
            //         $_SERVER['SCRIPT_NAME'] = '/app.php';
            //     }
            //     if (!isset($_SERVER['SERVER_NAME'])) {
            //         $_SERVER['SERVER_NAME'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
            //     }
            //     if (!isset($_SERVER['DOCUMENT_ROOT'])) {
            //         $_SERVER['DOCUMENT_ROOT'] = self::$cwd . '/public';
            //     }
            //     if (!isset($_SERVER['PHP_SELF'])) {
            //         $_SERVER['PHP_SELF'] = '/app.php';
            //     }

            //     $uri = $_SERVER['REQUEST_URI'];
            //     $method = $_SERVER['REQUEST_METHOD'];
            // } 

            $g = G::getInstance();
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
                $g->server['PHP_SELF'] = '/app.php';
            }

            $uri = $request->server['request_uri'];
            $method = $request->server['request_method'];


            foreach ($this->routes as $route) {
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
                            $invokeArgs[] = $request;
                        } else if ($pname == 'response'){
                            $invokeArgs[] = $response;
                        } else if ($pname == 'server'){
                            $invokeArgs[] = $server;
                        } else {
                            $invokeArgs[] = $param->isDefaultValueAvailable() 
                                ? $param->getDefaultValue() 
                                : null;
                        }
                    }
                    try {
                        ob_start();
                        call_user_func_array($handler, $invokeArgs);
                        $buffer = ob_get_clean();
                        $response->end($buffer);
                        return;
                    } catch (\Exception $e) {
                        $response->status(500);
                        elog(jTraceEx($e), "error");
                        if (self::$display_errors) {
                            // print the error message to the error log
                            $response->end("<pre>".jTraceEx($e)."</pre>");
                        } else {
                            $response->end("<pre>500 Internal Server Error</pre>");
                        }
                        return;

                    }
                }
            }

            // 404 if no match
            $response->status(404);
            $response->end("<pre>404 Not Found</pre>");
        }));
        elog("ZealPHP server running at http://{$this->host}:{$this->port} with ".count($this->routes)." routes");
        $server->start();
    }
}

<?php

namespace ZealPHP;

class App
{
    protected $routes = [];
    protected $host;
    protected $port;
    protected $cwd;

    public function __construct($cwd = __DIR__, $host = '0.0.0.0', $port = 9501)
    {
        $this->host = $host;
        $this->port = $port;
        $this->cwd = $cwd;

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
     * Define a route under a specific namespace.
     * e.g. $app->namespaceRoute('api', '/users', ['methods' => ['GET']], fn() => "User list");
     * This will create a route at /api/users
     */
    public function namespaceRoute($namespace, $path, $options = [], $handler = null)
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

        // Convert {param} style placeholders
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = "#^" . $pattern . "$#";

        $this->routes[] = [
            'pattern' => $pattern,
            'methods' => array_map('strtoupper', $methods),
            'handler' => $handler,
            // endpoint, strict_slashes etc. can also be handled similarly as in route()
        ];
    }

    public function run($settings = null)
    {
        $default_settings = [
            'enable_static_handler' => true,
            'document_root' => $this->cwd . '/public',
        ];
        $server = new \Swoole\HTTP\Server($this->host, $this->port);
        if ($settings == null){
            $server->set($default_settings);
        } else {
            $settings = array_merge($default_settings, $settings);
            $server->set($settings);
        }

        $server->on("request", function($request, $response) {
            // Fill PHP superglobals with Swoole request object

            // $_GET
            $_GET = $request->get ?? [];

            // $_POST
            $_POST = $request->post ?? [];

            //$_REQUEST
            $_REQUEST = array_merge($_GET, $_POST);

            // $_COOKIE
            $_COOKIE = $request->cookie ?? [];

            // $_FILES
            $_FILES = [];
            if (!empty($request->files)) {
                $_FILES = $request->files; // Structure is similar enough to PHP’s $_FILES
            }

            // $_SERVER
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
            // If not already set, define some defaults
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

            $uri = $_SERVER['REQUEST_URI'];
            $method = $_SERVER['REQUEST_METHOD'];

            foreach ($this->routes as $route) {
                // Check if method matches
                if (!in_array($method, $route['methods'])) {
                    continue;
                }

                // Check if URI matches
                if (preg_match($route['pattern'], $uri, $matches)) {
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
                    $result = call_user_func_array($handler, $invokeArgs);

                    // Determine response type
                    if (is_array($result)) {
                        $response->header('Content-Type', 'application/json');
                        $response->end(json_encode($result));
                    } else {
                        $response->header('Content-Type', 'text/html; charset=UTF-8');
                        $response->end($result);
                    }
                    return;
                }
            }

            // 404 if no match
            $response->status(404);
            $response->end("<h1>404 Not Found</h1>");
        });

        echo "ZealPHP server running at http://{$this->host}:{$this->port}\n";
        $server->start();
    }
}

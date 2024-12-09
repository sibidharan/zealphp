<?php

namespace ZealPHP;

class App
{
    protected $routes = [];
    protected $host;
    protected $port;

    public function __construct($host = '0.0.0.0', $port = 9501)
    {
        $this->host = $host;
        $this->port = $port;
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

    public function run()
    {
        $server = new \Swoole\HTTP\Server($this->host, $this->port);

        $server->on("request", function($request, $response) {
            $uri = $request->server['request_uri'] ?? '/';
            $method = strtoupper($request->server['request_method'] ?? 'GET');

            foreach ($this->routes as $route) {
                // Check if method matches
                if (!in_array($method, $route['methods'])) {
                    continue;
                }

                // Check if URI matches
                if (preg_match($route['pattern'], $uri, $matches)) {
                    $params = array_filter($matches, fn($k) => !is_numeric($k), ARRAY_FILTER_USE_KEY);

                    $handler = $route['handler'];

                    // Reflect the handler parameters
                    $reflection = is_array($handler)
                        ? new \ReflectionMethod($handler[0], $handler[1])
                        : new \ReflectionFunction($handler);

                    $invokeArgs = [];
                    foreach ($reflection->getParameters() as $param) {
                        $pname = $param->getName();
                        if (isset($params[$pname])) {
                            $invokeArgs[] = $params[$pname];
                        } else {
                            // Default or null
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

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

    public function route($method, $path, $handler)
    {
        // Convert flask-like {param} to named regex group
        $pattern = preg_replace('/\{([^}]+)\}/', '(?P<$1>[^/]+)', $path);
        $pattern = "#^" . $pattern . "$#";

        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $pattern,
            'handler' => $handler
        ];
    }

    public function run()
    {
        $server = new \Swoole\HTTP\Server($this->host, $this->port);

        $server->on("request", function($request, $response) {
            $uri = $request->server['request_uri'] ?? '/';
            $method = strtoupper($request->server['request_method'] ?? 'GET');

            foreach ($this->routes as $route) {
                if ($route['method'] === $method && preg_match($route['pattern'], $uri, $matches)) {
                    $params = array_filter($matches, fn($k) => !is_numeric($k), ARRAY_FILTER_USE_KEY);

                    $handler = $route['handler'];

                    // Reflect the function to map parameters
                    $reflection = is_array($handler)
                        ? new \ReflectionMethod($handler[0], $handler[1])
                        : new \ReflectionFunction($handler);

                    $invokeArgs = [];
                    foreach ($reflection->getParameters() as $param) {
                        $paramName = $param->getName();
                        if (isset($params[$paramName])) {
                            $invokeArgs[] = $params[$paramName];
                        } else {
                            // If param is missing and has default value, use it. Otherwise, null.
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

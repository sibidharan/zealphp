
def inject_methods(file_path):
    with open(file_path, "r") as f:
        content = f.read()

    methods_str = """
    public function get(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['GET'], $raw, $middleware, $backend);
    }

    public function post(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['POST'], $raw, $middleware, $backend);
    }

    public function put(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['PUT'], $raw, $middleware, $backend);
    }

    public function patch(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['PATCH'], $raw, $middleware, $backend);
    }

    public function delete(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['DELETE'], $raw, $middleware, $backend);
    }

    public function options(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['OPTIONS'], $raw, $middleware, $backend);
    }

    public function any(string $path, callable|array $handler, array $options = [], bool $raw = false, array $middleware = [], array|string|null $backend = null): void
    {
        $this->route($path, $options, $handler, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], $raw, $middleware, $backend);
    }
"""

    if "public function get(" not in content:
        # Insert before public function route(
        content = content.replace("    public function route(", methods_str + "\n    public function route(")

    with open(file_path, "w") as f:
        f.write(content)

inject_methods("/home/sibidharan/zealphp/src/App.php")
inject_methods("/home/sibidharan/zealphp/src/RouteGroup.php")

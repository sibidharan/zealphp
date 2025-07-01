# 04 – Middleware

## Table of Contents

- [4.1 Response helpers inside middleware](#41-response-helpers-inside-middleware)

ZealPHP ships with a PSR-15 compatible middleware stack powered by OpenSwoole’s `StackHandler`.  This means every community middleware for **Slim**, **Laminas** or **Mezzio** can be reused with little to no change.

```php
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

class AuthenticationMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // your auth logic here…

        // continue the chain
        return $handler->handle($request);
    }
}

$app->addMiddleware(new AuthenticationMiddleware());
```

Middlewares are executed **in the order they were added** before the request reaches the route handler.

## 4.1 Response helpers inside middleware

ZealPHP provides lightweight shims around common response helpers:

* `ZealPHP\response_add_header($name, $value)`
* `ZealPHP\response_set_status($code)`

These functions manipulate the PSR-7 `Response` instance that travels along the chain.

---

Next up: [Coroutines →](05-coroutines.md)


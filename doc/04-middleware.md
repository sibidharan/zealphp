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

## 4.2 Practical authentication examples

The bundled `app.php` now includes an `AuthenticationMiddleware` that supports **three** common credential styles:

1. **Bearer token** via the `Authorization: Bearer …` header.
2. **Query token** via a simple `?token=` URL parameter.
3. **Default PHP session** via the `PHPSESSID` cookie.

Below is an abbreviated snippet that shows the relevant control-flow.  Check the real source for complete details:

```php
class AuthenticationMiddleware implements MiddlewareInterface
{
    private const VALID_BEARER_TOKENS = ['zeal-secret-123', 'zeal-secret-456'];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cred = $this->extractCredential($request);
        if ($cred === null) {
            return $this->unauthorised();
        }

        switch ($cred['type']) {
            case 'bearer':
                // static allow-list check
                if (!in_array($cred['value'], self::VALID_BEARER_TOKENS, true)) {
                    return $this->unauthorised();
                }
                $user = ['token' => $cred['value']];
                break;

            case 'session':
                // resume PHP session and read user payload
                $user = $this->resumeSession($cred['value']);
                if ($user === null) {
                    return $this->unauthorised();
                }
                break;
        }

        return $handler->handle($request->withAttribute('user', $user));
    }
}
```

Swap the in-memory array for a DB lookup or JWT verification to move from demo
to production – the pattern remains the same.

---

Next up: [Coroutines →](05-coroutines.md)

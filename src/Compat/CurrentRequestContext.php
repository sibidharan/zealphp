<?php
declare(strict_types=1);

namespace ZealPHP\Compat;

use ZealPHP\RequestContext;

/**
 * `$GLOBALS['g']` under ZealPHP: forwards every access to the current
 * request's context.
 *
 * `$GLOBALS` belongs to the whole worker process, which serves many requests
 * (concurrently, in coroutine mode). Storing one request's `RequestContext`
 * there handed it to every later request, so one visitor read and wrote
 * another's session. This object holds no request state: each property read,
 * write, `isset()` or `unset()` resolves `RequestContext::instance()` at that
 * moment, so `$GLOBALS['g']->session['user']` is always this request's.
 *
 * Installed by `compat/g.php`; application code never constructs it.
 */
final class CurrentRequestContext
{
    /**
     * By reference, so `$GLOBALS['g']->session['k'] = $v` writes into the
     * current request's array rather than a copy.
     */
    public function &__get(string $name): mixed
    {
        $context = RequestContext::instance();
        return $context->$name;
    }

    public function __set(string $name, mixed $value): void
    {
        RequestContext::instance()->$name = $value;
    }

    public function __isset(string $name): bool
    {
        return isset(RequestContext::instance()->$name);
    }

    public function __unset(string $name): void
    {
        $context = RequestContext::instance();
        unset($context->$name);
    }
}

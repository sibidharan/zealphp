# 10 – Monkey-patched PHP functions & polyfills

## Table of Contents

- [10.1 Why monkey-patch native functions?](#101-why-monkey%E2%80%91patch-native-functions)
- [10.2 Complete list of patched functions](#102-complete-list-of-patched-functions)
- [10.3 Behavioural differences & gotchas](#103-behavioural-differences--gotchas)
- [10.4 Opting out](#104-opting-out)

ZealPHP replaces several *core* PHP functions at **runtime** using the `uopz` extension.  The goal is to recreate the classic PHP (FPM/CGI) environment **inside** an OpenSwoole coroutine so that legacy code “just works”.

---

## 10.1 Why monkey-patch native functions?

1. **Headers API** – in FPM you call `header()` at any time and it buffers until the web-server flushes.  Inside a long-living Swoole worker each request needs its *own* header store.
2. **Sessions** – the global session state has to be virtualised per request otherwise data bleeds between users.
3. **HTTP status** – `http_response_code()` must talk to the PSR-7 `Response` object, not global state.

By intercepting those calls ZealPHP can faithfully emulate FPM while still being coroutine-friendly.

---

## 10.2 Complete list of patched functions

The following functions are overridden during `App::init()`:

### Headers & cookies

* `header()` – delegated to `$response->header()`.
* `headers_list()` – returns **only** the headers set during the current request.
* `setcookie()` – maps to `$response->cookie()` with the same signature.
* `http_response_code()` – getter/setter backed by the PSR-7 response status.

### Sessions (`Session\*` namespace)

All of PHP’s session helpers are re-implemented so that they work on ZealPHP’s coroutine-aware `SessionManager` or `CoSessionManager`:

* `session_start()`
* `session_id()`
* `session_status()`
* `session_name()`
* `session_write_close()`
* `session_destroy()`
* `session_unset()`
* `session_regenerate_id()`
* `session_get_cookie_params()`
* `session_set_cookie_params()`
* `session_cache_limiter()`
* `session_cache_expire()`
* `session_commit()` (alias of `session_write_close()`)
* `session_abort()`
* `session_encode()` / `session_decode()`
* `session_save_path()`
* `session_module_name()`

> ℹ️  The replacement functions aim for **100 % signature compatibility**.  You can drop existing libraries (Symfony Auth, Laravel Passport, etc.) into ZealPHP without modification.

---

## 10.3 Behavioural differences & gotchas

1. **Headers are immutable after the body is flushed** – same as FPM.  Trying to call `header()` after output will raise a notice.
2. **Sessions are stored in memory by default**.  For shared-nothing deployments switch to `FileSessionHandler` or implement your own `Session\Handler`.
3. **`setcookie()` secure/httponly flags** – all parameters are forwarded one-to-one.  The behaviour matches PHP 8.3.
4. **`session_write_close()` inside a coroutine** serialises the data *immediately* to avoid race conditions across concurrent requests.

---

## 10.4 Opting out

If you really need the original behaviour (for example inside a CLI script) disable the patches before initialising the app:

```php
\uopz_unset_return('header');
// repeat for any function you need to un-patch

$app = ZealPHP\App::init();
```

Be aware that doing so breaks the request isolation guarantees and is **not** recommended for HTTP workers.

---

Next up: grab a coffee – you now know more about PHP internals than most developers ☕︎


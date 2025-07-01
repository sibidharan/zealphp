# 03 – Superglobals & the `G` helper

## Table of Contents

- [3.1 Enabling / disabling emulated superglobals](#31-enabling--disabling-emulated-superglobals)
- [3.2 The `G` singleton](#32-the-g-singleton)
- [3.3 Accessing superglobals inside coroutines](#33-accessing-superglobals-inside-coroutines)

Legacy PHP applications and many third-party libraries expect good old `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION` … to be populated **per request**.  ZealPHP can emulate that behaviour while still running on a persistent OpenSwoole worker.

## 3.1 Enabling / disabling emulated superglobals

Superglobals are **enabled by default**.  If you want raw coroutine power inside your route handlers you can opt-out at bootstrap:

```php
use ZealPHP\App;

App::superglobals(false);   // ⬅ disables emulation & enables full coroutine support

$app = App::init();
```

Why the trade-off?  Re-building superglobals requires the worker to process **one request at a time** (to avoid data leaks).  If you disable them each worker can serve multiple concurrent requests thanks to OpenSwoole’s coroutines.

## 3.2 The `G` singleton

When superglobals are turned *off*, the **`G`** helper provides the exact same data but lives in user-land memory:

```php
use ZealPHP\G;

$g = G::instance();

echo $g->_GET['page'];   // instead of $_GET['page']

// sessions
$g->session['user_id'] = 123;

// helper shortcuts
G::get('session');      // returns the session array
```

Internally `G` is a simple `ArrayObject` that is recycled each time the worker picks up a new HTTP request.

## 3.3 Accessing superglobals inside coroutines

If you keep superglobals **enabled**, launching a coroutine with `go()` will clone the data into the child fiber for safety.  Mutation *inside the coroutine* **will not** propagate back to the parent request by design.  Use channels, shared memory or databases to communicate.

---

Next up: [Middleware →](04-middleware.md)


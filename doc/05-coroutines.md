# 05 – Coroutines, `go()` and `coproc()`

## Table of Contents

- [5.1 Using `go()` inside a route](#51-using-go-inside-a-route)
- [5.2 The `coproc()` helper](#52-the-coproc-helper)
- [5.3 Disabling superglobals to unlock full concurrency](#53-disabling-superglobals-to-unlock-full-concurrency)

OpenSwoole brings Golang-style coroutines (fibers) to PHP.  ZealPHP adopts them while keeping a foot in the synchronous world so that existing code continues to work.

---

## 5.1 Using `go()` inside a route

```php
use OpenSwoole\Coroutine\Channel;

$app->route('/co', function () {
    $ch = new Channel(2);

    go(function () use ($ch) {
        // expensive IO
        sleep(1);
        $ch->push('first');
    });

    go(function () use ($ch) {
        sleep(2);
        $ch->push('second');
    });

    // Wait for both tasks
    $res = [$ch->pop(), $ch->pop()];
    echo json_encode($res);
});
```

If superglobals are *enabled* the route above still works but every coroutine receives its **own cloned copy** of the `$_` arrays.

---

## 5.2 The `coproc()` helper

For CPU-bound or extremely IO heavy work you can fork a **child process** that has *its own* coroutine scheduler.  ZealPHP wraps `OpenSwoole\Process` into an ergonomic helper:

```php
$pid = ZealPHP\coproc(function () {
    // we are in a separate process + event loop
    doSomethingCPUHeavy();
});
```

Data cannot be shared across processes via superglobals (they are serialised).  Use IPC, sockets, or databases for communication.

---

## 5.3 Disabling superglobals to unlock full concurrency

If your application is 100 % coroutine-safe, disable superglobals at bootstrap and enjoy massive concurrency:

```php
App::superglobals(false);
```

Each worker is then able to serve thousands of concurrent HTTP requests while keeping memory usage low.

---

Next up: [Implicit routing →](06-implicit-routes.md)


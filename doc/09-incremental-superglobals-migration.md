# 09 – Incremental migration with `App::superglobals()`

## Table of Contents

- [9.1 Why another guide?](#91-why-another-guide)
- [9.2 Zero-friction first run](#92-zero%E2%80%91friction-first-run)
- [9.3 Creating `app.php` in a legacy code-base](#93-creating-appphp-in-a-legacy-code%E2%80%91base)
- [9.4 Adding routes while you keep the old pages](#94-adding-routes-while-you-keep-the-old-pages)
- [9.5 Flipping the switch – enabling coroutines](#95-flipping-the-switch-%E2%80%93-enabling-coroutines)

Old PHP applications – WordPress plugins, bespoke admin panels, home-grown CMSes – rely on the classic **superglobal** arrays and on the assumption that *one request equals one PHP process*.  ZealPHP can reproduce this assumption while it boots on OpenSwoole and therefore lets you migrate **incrementally**.

This guide shows a practical workflow for porting such a project and ultimately unlocking coroutine performance without a Big-Bang rewrite.

---

## 9.1 Why another guide?

Chapter&nbsp;03 explains what superglobal emulation is, while chapter&nbsp;08 gives an overview of the whole migration story.  Here we zoom in on the **step-by-step** process and provide concrete snippets you can paste into your project today.

---

## 9.2 Zero-friction first run

Out of the box **`App::superglobals(true)`** (the default) makes ZealPHP behave like Apache + FPM:

* each request is executed *serially* inside a worker,
* `$_GET`, `$_POST`, `$_SERVER`, `$_SESSION`, `$_COOKIE` … are filled **exactly** as the legacy code expects,
* calls to `header()`, `setcookie()` and all `session_*` functions are transparently monkey-patched.

This means you can literally do:

```bash
composer require sibidharan/zealphp
cp -r public/  api/  vendor/  # make sure your entry points end up in the standard folders
```

and the bulk of the application will already run.

---

## 9.3 Creating `app.php` in a legacy code-base

Every ZealPHP project boots from a lightweight **`app.php`** script that lives next to `composer.json`:

```php
#!/usr/bin/env php
<?php

// file: app.php

require __DIR__ . '/vendor/autoload.php';

use ZealPHP\App;

// 👇 Keep emulated superglobals for now
App::superglobals(true);   // ← this is actually the default, but being explicit helps future-you

$app = App::init();

// --- Optional: add a minimal health-check route ---
$app->get('/health', fn() => 'OK');

// You may add more routes at any pace (see section 9.4)

$app->run();

# EOF
```

Run the HTTP server with

```bash
php app.php
```

and browse to `http://127.0.0.1:9501/your/old/page.php`.  Because of the **implicit routes** that ship with ZealPHP, all files under `public/` and `api/` behave the same as under Apache.

> 🚀 *Yes, it really is that simple.*

---

## 9.4 Adding routes while you keep the old pages

As you touch parts of the legacy project you can start replacing them with **explicit routes** without interfering with the untouched code.

```php
$app->patternRoute('GET', '/post/(\\d+)', function (array $params) {
    $id = (int) $params[1];

    // new coroutine-ready service layer
    $post = go(fn() => Posts::fetch($id));

    return view('post', compact('post'));
});
```

* New endpoints benefit from coroutines immediately (they do not rely on superglobals).
* Old scripts under `public/` keep working unchanged – they simply skip the route dispatcher and are executed as before.

This hybrid approach lets you roll forward **gradually** and measure improvements along the way.

---

## 9.5 Flipping the switch – enabling coroutines

Once the last piece of code that depends on `$_*` has been refactored (or placed behind an adapter that uses the `G` helper) you can unlock *true* concurrency:

```php
// bootstrap

App::superglobals(false);   // 🏎  workers may now handle many requests in parallel
```

Internally every worker turns into a coroutine scheduler.  Long-running I/O – database queries, HTTP calls, filesystem access – no longer blocks the whole process.  Expect **dramatic** throughput gains, especially on high-latency workloads.

If you need a **safety net** during the transition you can guard the switch behind an environment variable:

```php
App::superglobals($_ENV['LEGACY_MODE'] === '1');
```

This lets you run two sets of workers (or two staging environments) and flip traffic over when you are confident.

---

Next up: celebrate – you have just modernised a PHP application without downtime 🎉


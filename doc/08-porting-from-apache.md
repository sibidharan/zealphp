# 08 – Porting an Apache/FPM application

## Table of Contents

- [8.1 Replace the web-server layer](#81-replace-the-web-server-layer)
- [8.2 URL rewriting](#82-url-rewriting)
- [8.3 Superglobals](#83-superglobals)
- [8.4 Sessions & cookies](#84-sessions--cookies)
- [8.5 Gradual modernisation](#85-gradual-modernisation)

One of ZealPHP’s design goals is **zero-friction migration**.  You can bring an existing code-base over in the morning and enjoy coroutine performance by the afternoon.

---

## 8.1 Replace the web-server layer

1. Disable or move away Apache/Nginx for the project you are migrating.
2. Copy your source files into the ZealPHP project (or `composer require sibidharan/zealphp` inside the legacy code-base).
3. Make sure `app.php` boots the server (see chapter 01).  The default implicit routes already understand `public/` and `api/` so your existing entry points keep working.

---

## 8.2 URL rewriting

*Most* `.htaccess` setups fall into one of these categories:

* **Serve static assets** – already handled by the `public/` implicit route.
* **Rewrite everything to `index.php`** – ZealPHP will execute `public/index.php` automatically when the path does not match any explicit route.
* **Custom rewrites** – convert them into `patternRoute()` declarations (see chapter 02) which gives you the same flexibility as `RewriteRule` but lives in PHP.

> ✨ Because routes are compiled into an efficient tree, complex rewrite patterns **do not** hurt performance.

---

## 8.3 Superglobals

Keep `App::superglobals(true)` (default) while you migrate.  The legacy code will run unchanged.  When the code-base is coroutine-ready flip the switch to `false` to unlock full performance.

---

## 8.4 Sessions & cookies

ZealPHP monkey-patches `session_*`, `header`, `setcookie` so that they behave exactly like under Apache.  You do **not** have to refactor anything.

---

## 8.5 Gradual modernisation

1. **Introduce explicit routes** for new features (keep old pages under `public/`).
2. Start using `go()` for parallel API calls and DB queries.
3. Replace expensive loops with child processes created through `coproc()`.

You decide the pace – ZealPHP stays out of your way.

---

Congratulations, you are now fully caught-up with ZealPHP 🚀


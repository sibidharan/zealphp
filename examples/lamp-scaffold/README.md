# LAMP scaffold — same code, two servers

A vanilla PHP app where every file in `public/` runs **byte-identical** on
Apache (mod_php) and ZealPHP. No framework imports in user code; no rewrites
when porting between servers. This is the smallest possible proof that the
two stacks are interchangeable for legacy code.

The pattern is the same one Selfmade Ninja Labs used to migrate its
production codebase (see [`/case-studies/sna-labs`](https://php.zeal.ninja/case-studies/sna-labs)).

## Layout

    examples/lamp-scaffold/
      app.php                  # ZealPHP entrypoint (Mixed-mode lifecycle)
      composer.json
      bootstrap/
        g.php                  # The $g compat shim (the only mode-aware code)
      public/
        index.php              # Landing page — $g->session, $g->get
        about.php              # Server info — $g->server
        session-counter.php    # Session counter — proves cross-page session
        classic-php.php        # Pure $_GET / $_SESSION (no $g, no bootstrap) — v0.2.27+
        api/
          users.php            # JSON API — $g->get['id']
      apache/
        vhost.conf.example     # Apache vhost (drop into sites-available/)

## Two ways to be portable

This scaffold demonstrates **both** portability styles. Pick whichever fits
the codebase you're migrating:

| Style | What it uses | Example file | When to pick it |
|-------|--------------|--------------|-----------------|
| **`$g` compat shim** | `$g->get`, `$g->session`, ... via [bootstrap/g.php](bootstrap/g.php) | [index.php](public/index.php), [about.php](public/about.php), [session-counter.php](public/session-counter.php), [api/users.php](public/api/users.php) | New code, or migrations that go all-the-way to `$g`. Also works in ZealPHP coroutine mode (where superglobals are intentionally not populated). |
| **Pure superglobals** | `$_GET`, `$_SESSION`, ... directly — no bootstrap, no framework imports | [classic-php.php](public/classic-php.php) | Legacy LAMP code you don't want to touch. Requires **ZealPHP ≥ v0.2.27** on the ZealPHP side (earlier versions populated `$g` but not `$_GET`). |

The compat shim style is the only one that works in **all three** runtime
modes (Apache mod_php, ZealPHP coroutine, ZealPHP Mixed-mode). The pure
superglobals style works in Apache and ZealPHP Mixed-mode but not in
coroutine mode — that's by design, since populating process-wide
superglobals from inside concurrent coroutines would race.

## How the compat shim works

Each `public/*.php` that uses the shim starts with one line:

```php
require_once __DIR__ . '/../bootstrap/g.php';
```

That file ([bootstrap/g.php](bootstrap/g.php)) decides at runtime which `$g`
to provide:

| Loaded by | `class_exists('\ZealPHP\RequestContext')` | `$g` is | `$g->get` reads from |
|-----------|-------------------------------------------|---------|----------------------|
| ZealPHP   | `true`                                    | `RequestContext::instance()` — declared properties populated from the OpenSwoole request | The framework's per-request array (NOT `$_GET`) |
| Apache    | `false`                                   | Plain `stdClass` with `&$_GET`, `&$_SESSION`, etc. | The real PHP superglobal, by reference |

Both shapes expose the same surface: `$g->get`, `$g->post`, `$g->server`,
`$g->cookie`, `$g->files`, `$g->session`, `$g->request`. App code that uses
only `$g->*` is portable.

## Run on ZealPHP

```bash
composer install
php app.php
# → http://localhost:8080
```

ZealPHP boots in **Mixed-mode** — `superglobals(true) + processIsolation(false)`
— which means native `$_SESSION`, no per-include CGI fork cost, sequential
request handling per worker. Apache prefork-MPM semantics, in-process
execution. See [coroutines.md lifecycle modes](https://php.zeal.ninja/coroutines#lifecycle-modes)
for the full matrix.

## Run on Apache + mod_php

```bash
sudo cp apache/vhost.conf.example /etc/apache2/sites-available/lamp-scaffold.conf
# Edit the DocumentRoot path to point at your checkout
sudo a2ensite lamp-scaffold
sudo systemctl reload apache2
# → http://localhost:8081
```

Apache will load `public/index.php` directly via mod_php. No ZealPHP, no
OpenSwoole, no autoload of `vendor/` — just PHP and the bootstrap file.

## Run both at once (dual-mode)

In one terminal:

```bash
php app.php                      # ZealPHP on :8080
```

In another:

```bash
sudo systemctl start apache2     # Apache on :8081
```

Hit the same path on both and compare:

```bash
curl -s 'http://localhost:8080/api/users.php?id=42' | jq
curl -s 'http://localhost:8081/api/users.php?id=42' | jq
```

The JSON bodies differ in exactly one field — `server` — proving the code
identified itself correctly. Everything else is identical.

## Verifying session parity

Both servers use PHP's default file-backed session storage at
`/var/lib/php/sessions/`. If the cookie domain matches (e.g., both on
`localhost`), a session created on Apache survives a request to ZealPHP and
vice versa. Visit `/session-counter.php` on one, then on the other — the
counter keeps climbing.

## What this scaffold does NOT demonstrate

- **WebSocket** — Apache can't do those. ZealPHP can. See
  [`/websocket`](https://php.zeal.ninja/ws).
- **Coroutine I/O** — Mixed-mode disables the scheduler. To get
  parallel-fetch superpowers, switch to `superglobals(false)` and lose
  `$_SESSION`-as-array semantics (use `$g->session` instead). See
  [`/coroutines`](https://php.zeal.ninja/coroutines).
- **OpenSwoole's full runtime** (timers, Store, Counter) — you can use
  them in ZealPHP routes registered alongside the implicit public-file
  routing; Apache won't see them. This scaffold sticks to the
  portable surface.

## Related

- The compat shim pattern: this scaffold's [bootstrap/g.php](bootstrap/g.php) is the canonical version.
- Migration guide: [`/migration`](https://php.zeal.ninja/migration)
- Legacy apps deep dive: [`/legacy-apps`](https://php.zeal.ninja/legacy-apps)
- Production case study (this pattern in real-world use): [`/case-studies/sna-labs`](https://php.zeal.ninja/case-studies/sna-labs)
- vs PHP-FPM cost comparison: [`/vs-fpm`](https://php.zeal.ninja/vs-fpm)

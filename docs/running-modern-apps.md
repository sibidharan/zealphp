# Running modern PHP apps on ZealPHP

Practical config recipes from the 50-app coroutine-legacy sweep. The recurring
theme: **most modern apps keep their web root in a sub-directory** (`public/`,
`web/`, `upload/`) and load Composer autoloading from `../vendor/`. ZealPHP's
`App::documentRoot()` is the Apache `DocumentRoot` equivalent — point it at the
app's *real* web root, run `composer install`, configure the DB, and the app
boots. If you point it at the repo root instead, you get a 404 (no `index.php`
there) or a `Failed opening required '.../vendor/autoload.php'` 500.

All examples assume `App::mode(App::MODE_COROUTINE_LEGACY)` (superglobals +
per-coroutine isolation). One app per ZealPHP process is the supported shape
(shared-worker multi-tenancy hits dependency-version conflicts — see the psr/log
note at the end).

---

## The one rule: documentRoot = the app's web root

| App | Repo layout | `App::documentRoot()` | Notes |
|---|---|---|---|
| WordPress | root has `index.php` | `'/app/wordpress'` (the root) | works as-is |
| Laravel (BookStack, Monica, …) | `public/index.php` | `'/app/bookstack/public'` | needs `composer install` + `.env` |
| Symfony (Wallabag, …) | `public/` or `web/` | `'/app/wallabag/web'` | needs `composer install` + `APP_ENV` |
| Slim | `public/index.php` | `'/app/slim-app/public'` | `composer install` |
| Flarum | `public/index.php` | `'/app/flarum/public'` | ships `vendor/` → boots once docroot is right |
| OpenCart | `upload/` is the app | `'/app/opencart/upload'` | run the install wizard |
| phpMyAdmin | root has `index.php` | `'/app/phpmyadmin'` | needs `config.inc.php` (see caveat) |
| Drupal | root has `index.php` | `'/app/drupal'` | `composer install` for a real install |

`index.php` for any of them is the standard 3-line ZealPHP bootstrap:

```php
<?php
require __DIR__ . '/vendor/autoload.php';
use ZealPHP\App;

App::mode(App::MODE_COROUTINE_LEGACY);
App::documentRoot('/app/<the-app>/public');   // <-- the app's web root
$app = App::init('0.0.0.0', 8080);
$app->run();
```

---

## Laravel (BookStack, Monica, Koel, …)

```bash
cd /app/bookstack
composer install --no-dev --optimize-autoloader   # creates vendor/ (the 500 without it)
cp .env.example .env
# edit .env: DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD, APP_URL
php artisan key:generate
php artisan migrate --force                         # needs the DB reachable
```
ZealPHP: `App::documentRoot('/app/bookstack/public')`. Laravel's
`public/index.php` does `require __DIR__.'/../vendor/autoload.php'` — that
relative require resolves correctly because `App::executeFile()` chdir's to the
script's directory (the relative-include fix). Laravel keeps request state in
its container (not `$GLOBALS`), so coroutine-legacy isolation is a clean fit.

## Symfony (Wallabag, …)

```bash
cd /app/wallabag
composer install --no-dev                           # creates vendor/autoload_runtime.php
# configure .env / .env.local: DATABASE_URL, APP_ENV=prod, APP_SECRET
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:warmup --env=prod
```
ZealPHP: `App::documentRoot('/app/wallabag/public')` (older Symfony: `web/`).
Symfony's `public/index.php` uses `autoload_runtime.php` — same relative-require
path, handled by the chdir fix.

## Slim / micro-frameworks

```bash
cd /app/slim-app && composer install
```
`App::documentRoot('/app/slim-app/public')`. Slim apps that 500 in *every* mode
(including CGI-Pool) are app config (missing `.env`, DB), not a ZealPHP issue —
check the app's own logs.

## OpenCart

`App::documentRoot('/app/opencart/upload')`, then browse `/install/` and run the
wizard (writes `config.php` + `admin/config.php`). After install, delete
`install/` as OpenCart asks.

## phpMyAdmin

Ships `vendor/`; needs `config.inc.php` with a `blowfish_secret` and the server
host:
```php
<?php
$cfg['blowfish_secret'] = '0123456789abcdef0123456789abcdef';   // 32 chars
$cfg['Servers'][1]['host'] = 'mysql';        // your DB host
$cfg['Servers'][1]['auth_type'] = 'cookie';
```
`App::documentRoot('/app/phpmyadmin')`. **Caveat:** phpMyAdmin is the one app
that still doesn't run cleanly in full coroutine-legacy — **use
`App::cgiMode('pool')` for it** (pre-spawned, recycled subprocess pool, ~1-3ms warm; returns 200). Two
distinct issues were root-caused (see
`docs/architecture/2026-05-29-50app-sweep-findings.md` §D):
- **`Undefined constant AUTOLOAD_FILE` / `ROOT_PATH`** — only appears if you
  enable `defineIsolation(true)` *without* `includeIsolation(true)`. Clearing
  request-scoped constants is only sound when the `require_once`'d files that
  define them re-execute. `App::mode(App::MODE_COROUTINE_LEGACY)` enables both together,
  so don't hand-roll that half-combo (the framework now warns at boot if you do).
- **Bootstrap hang (000)** — phpMyAdmin's deeply-recursive Symfony DI container
  build hits a coroutine yield/resume scheduling race under HOOK_ALL (a
  Heisenbug: extra I/O makes it pass). Open; `cgiMode('pool')` is the supported
  workaround until the compile-path yield-safety work lands.

## WordPress

Works at the repo root (`App::documentRoot('/app/wordpress'`), `wp-config.php`
with DB creds). The perf-VM reference runs WordPress in coroutine mode with
`App::hookAll(0)` (see "blocking I/O" below). Concurrent WordPress also wants a
per-coroutine DB connection (one `$wpdb` per request) — two subprocess-pool
fallbacks are available:

- **`App::cgiMode('pool')`** (default) — pre-spawned worker pool, ~1–3 ms warm, recycled per `cgiPoolMaxRequests`. The recommended stable choice.
- **`App::cgiMode('fork')`** *(experimental)* — Apache MPM prefork style: a fork-master forks a FRESH child per request (~1 ms fork cost, no proc_open cold-start). Gives true global-scope isolation (no "Cannot redeclare class") because each child exits after one request. Requires `pcntl` + `posix` in the PHP build. Reachable via `App::cgiMode('fork')` only — there is no `App::mode()` preset for it. Concurrency is bounded by `App::$cgi_fork_max_concurrent` (default 16); requests beyond that cap return 503.

---

## Cross-cutting caveats (from the sweep)

- **`composer install` is not optional.** A `Failed opening required
  '.../vendor/autoload.php'` 500 means vendor/ is missing — install it. This is
  app setup, not a framework bug.
- **Blocking I/O + HOOK_ALL.** Some legacy apps' blocking ops (session `flock`,
  certain socket patterns) can deadlock the coroutine scheduler under HOOK_ALL.
  The production-tested mitigation is `App::hookAll(0)` (the perf-VM WordPress
  uses it). DB I/O then blocks the worker rather than yielding — fine for legacy
  apps; scale with more workers.
- **Database connections.** `PDO_MYSQL`/`mysqli` on mysqlnd are coroutinized
  under HOOK_ALL, but a single shared connection across concurrent coroutines
  corrupts the wire — use one connection per coroutine (a pool) or `cgiMode('pool')`.
  `libpq`-based `PDO_PGSQL` is not hooked; use `Coroutine\PostgreSQL`.
- **Dependency-version conflicts (the hard one).** An app vendoring an *older*
  version of a library the framework also ships (e.g. kanboard's psr/log v1 vs
  the framework's v3) clashes in the shared coroutine class table → a
  compile-time "must be compatible" fatal. This is fundamental to in-process
  hosting; **CGI-Pool mode (pre-spawned subprocess pool) avoids it** because only the
  app's own vendor/ loads. Modern apps on current deps don't hit it.
- **`$_SERVER['argv']`** and other CLI-only expectations: some apps (e.g.
  Nextcloud's `base.php`) read `argv` and warn/500 in a web SAPI — an app
  environment assumption, not a ZealPHP bug.

When in doubt, test the app in `cgiMode('pool')` first (Apache-equivalent,
process-isolated). If it works there but not in coroutine-legacy, it's a
framework/isolation interaction worth filing; if it fails in both, it's app
config.

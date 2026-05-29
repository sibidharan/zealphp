# ZealPHP: Application Server + Multi-SAPI Runtime

> Written: 2026-05-28  
> Environment: PHP 8.4.21 + OpenSwoole 26.2.0 + ext-zealphp 0.3.3

---

## 1. Executive Summary

ZealPHP is simultaneously an **Application Server** (a long-running PHP process with native coroutines, WebSocket, SSE, shared-memory Store/Counter, pub/sub, and scheduled tasks) and a **multi-SAPI runtime** (five distinct PHP request lifecycles that differ in process isolation, concurrency model, and superglobal semantics). The analogy is a JVM running both Tomcat (always-on app server) and standalone apps: the JVM handles the OS integration, threading, and GC; Tomcat adds the HTTP request lifecycle on top. ZealPHP handles the OS socket, coroutine scheduler, and shared memory; you choose which request lifecycle model sits above it based on your application's compatibility requirements and throughput target.

---

## 2. Why Multiple Execution Models

PHP itself ships multiple SAPIs because different runtime guarantees suit different workloads:

| PHP SAPI | Lifetime | Global state | Concurrency |
|----------|----------|--------------|-------------|
| mod_php (prefork) | Fresh process per request | Truly isolated — OS fork | N workers × 1 request |
| PHP-FPM | Persistent worker pool | Reset between requests | N workers × 1 request |
| CLI | Script lifetime | Script-scoped | 1 |
| embed | Host process lifetime | Shared with host | Host-controlled |

ZealPHP gives you all of these inside **one long-running server process** — no Apache, no nginx, no php-fpm daemon needed. The trade-off you make is choosing how much isolation you need per request versus how much overhead you are willing to pay.

The five execution models map cleanly onto the PHP SAPI analogy:

| ZealPHP mode | Closest PHP SAPI |
|---|---|
| Mode 1 CGI Proc | mod_php prefork — fresh PHP process per request |
| Mode 1 CGI Pool | PHP-FPM — persistent worker pool, FPM-style reset |
| Mode 3 Sync | FastCGI standalone — long-running, sequential, no subprocess |
| Mode 4 Hybrid | (new) FastCGI + coroutines via ext-zealphp superglobal isolation |
| Mode 5 Coroutine | (new) Native OpenSwoole — `$g` instead of `$_GET`/`$_POST` |

---

## 3. The Five Execution Models

### 3.1 Comparison Table

| | Mode 1 CGI Proc | Mode 1 CGI Pool | Mode 3 Sync | Mode 4 Hybrid | Mode 5 Coroutine |
|---|---|---|---|---|---|
| **PHP analog** | mod_php prefork | PHP-FPM | FastCGI standalone | (new) | (new) |
| **Fresh PHP state per req** | Yes — OS fork | Yes — pool reset | Optional (`functionIsolation`) | Partial (superglobals isolated, `$GLOBALS` shared) | No — shared process |
| **Subprocess overhead** | ~30–50 ms/req | ~5–10 ms/req (amortized) | 0 ms | 0 ms | 0 ms |
| **Concurrent coroutine I/O** | No | No | No | Yes | Yes |
| **Superglobals (`$_GET`)** | Yes | Yes | Yes | Yes (ext-zealphp) | No — use `$g->get` |
| **`exit()`/`die()` safety** | Yes (fresh proc) | Yes (worker respawn) | Yes (shutdown handler) | Yes (ext-zealphp) | Partial |
| **Unguarded `define()`** | Safe | Safe | Needs `defineIsolation(true)` | Needs `defineIsolation(true)` | Needs `defineIsolation(true)` |
| **`$GLOBALS` cleanup** | Yes | Yes (FPM-style snapshot/restore) | Yes (with `functionIsolation`) | **No** — process-wide race | **No** — use `$g` |
| **Function/class redeclaration** | Safe | Safe | Safe with `functionIsolation` | Safe with `functionIsolation` | Safe with `functionIsolation` |
| **Requires ext-zealphp** | No | No | No | **Yes** | No |
| **Best for** | WordPress + plugins | High-throughput legacy PHP | Symfony / Laravel / DokuWiki | Modern apps wanting concurrency with legacy code | Native ZealPHP apps |

### 3.2 Mode 1 — CGI Proc (`cgiMode('proc')`)

Each request dispatches a fresh `cgi_worker.php` subprocess via `proc_open`. The subprocess has a completely clean PHP interpreter state — no opcache sharing, no leftover globals, no leaked static properties. The process exits after serving the response. ZealPHP's master worker reads the IPC response frame and sends it to the client.

**Configuration:**

```php
App::superglobals(true);
App::processIsolation(true);
App::cgiMode('proc');
$app = App::init('0.0.0.0', 8080);
$app->run();
```

**When to use:** applications that call `exit()`/`die()` from deep inside plugin code (e.g. some WordPress plugins), or that use `define()` without `defined()` guards in top-level includes. Acceptable when throughput is not the primary concern.

**Trade-off:** ~30–50 ms spawn overhead per request. Every request pays this cost. A 100 req/s workload spawns 100 PHP processes per second. Use the pool variant below unless you specifically need the fresh-process guarantee.

### 3.3 Mode 1 — CGI Pool (`cgiMode('pool')`, default for processIsolation)

A persistent pool of `cgi_worker.php` subprocesses is created at startup (one pool per worker). Requests are dispatched via length-prefixed JSON IPC over pipes. Each pool worker handles one request at a time; after serving, it performs an FPM-style `$GLOBALS` snapshot + restore and waits for the next dispatch.

Pool workers recycle after `ZEALPHP_POOL_MAX_REQUESTS` requests (default 500), preventing slow memory leaks in poorly-written legacy apps. When a pool worker calls `exit()`/`die()`, a real PHP `register_shutdown_function` (registered before the uopz override) flushes the session, captures output, sends the IPC response frame with `_exit: true`, and exits cleanly. `WorkerPool` sees the `_exit` flag and respawns immediately.

**Configuration:**

```php
App::superglobals(true);
App::processIsolation(true);
App::cgiMode('pool');  // default when processIsolation(true)
$app = App::init('0.0.0.0', 8080);
$app->run();
```

**When to use:** WordPress, Joomla, OpenCart, Matomo, Nextcloud, FreshRSS, Cacti, Roundcube — any traditional PHP app that relies on fresh-per-request global state but can benefit from reduced spawn overhead compared to proc mode.

**Trade-off:** ~5–10 ms amortized overhead. Some apps (phpMyAdmin, DokuWiki) crash the pool worker due to specific MySQL initialization or `ob_*` interactions in the subprocess context. These apps work correctly in Mode 3 in-process.

### 3.4 Mode 3 — Sync / In-Process (`superglobals(true) + enableCoroutine(false)`)

Requests are handled sequentially inside the long-running ZealPHP worker. No subprocess is spawned. `$_GET`, `$_POST`, `$_SESSION`, `$_SERVER`, etc. are populated per request by the framework's `onRequest` handler, overwriting the previous request's values. There is no race because requests are processed one at a time per worker.

Optional `App::functionIsolation(true)` cleans function/class/constant state between requests (FPM parity for apps that redeclare functions at the top of included files). `TableSessionHandler` is auto-registered when `processIsolation(false)` to ensure sessions persist correctly across requests in the same worker.

**Configuration:**

```php
App::superglobals(true);
App::enableCoroutine(false);
App::processIsolation(false);
$app = App::init('0.0.0.0', 8080);
$app->run();
```

With optional function isolation for apps like Adminer or TinyFileManager that declare functions at file scope:

```php
App::superglobals(true);
App::enableCoroutine(false);
App::processIsolation(false);
App::functionIsolation(true);
$app = App::init('0.0.0.0', 8080);
$app->run();
```

**When to use:** Symfony, Laravel, TYPO3, CakePHP, Flarum, phpMyAdmin, DokuWiki — any app built on modern OOP where the PHP state can be reset between requests without OS fork overhead. This is the **sweet spot for the vast majority of apps**: FPM-level compatibility, zero subprocess overhead.

**Trade-off:** Sequential requests per worker — no coroutine concurrency. Scale horizontally via `ZEALPHP_WORKERS`. Each worker handles one request at a time; an I/O-bound handler (slow DB query, external HTTP call) blocks the worker until it completes.

### 3.5 Mode 4 — Hybrid (`superglobals(true) + enableCoroutine(true)`, requires ext-zealphp)

Coroutine concurrency with traditional PHP superglobals. ext-zealphp provides a two-layer C+PHP isolation mechanism: per-coroutine `EG(symbol_table)` snapshots on coroutine yield/resume, and `PG(http_globals)` updates for correct auto-global JIT resolution. `$_SESSION` uses reference binding (`$_SESSION = &$g->session`) so mutations in included files persist correctly.

`$GLOBALS` remains process-wide — concurrent coroutines share it. Use `$g` (per-coroutine `RequestContext`) for any request-scoped state that must not leak across concurrent requests.

`exit()`/`die()` is intercepted by ext-zealphp — the coroutine terminates cleanly without killing the worker process.

**Configuration:**

```php
// Requires ext-zealphp loaded (check: php -m | grep zealphp)
App::superglobals(true);
App::enableCoroutine(true);
App::processIsolation(false);
// defineIsolation recommended for apps with unguarded define() calls:
App::defineIsolation(true);
$app = App::init('0.0.0.0', 8080);
$app->run();
```

**When to use:** Modern apps that want coroutine-level I/O concurrency while keeping traditional `$_GET`/`$_POST`/`$_SESSION` semantics. Joomla, OpenCart, Kanboard, Roundcube all pass in Mode 4. Apps with process-wide singleton abuse (MediaWiki's `$wgUser`, Magento's DI container) still need Mode 1 or Mode 3.

**Trade-off:** Requires ext-zealphp C extension (PHP 8.3+, source build on 8.4). `$GLOBALS` is a shared process-wide race — any app reading/writing `$GLOBALS` directly (not `$_GET`/`$_POST` etc.) will have correctness issues under concurrency.

See [`2026-05-27-superglobal-isolation.md`](2026-05-27-superglobal-isolation.md) for the deep-dive on the C-level snapshot/restore mechanism.

### 3.6 Mode 5 — Native Coroutine (`superglobals(false)`, default)

Pure OpenSwoole coroutine mode. Each request gets an isolated `RequestContext` stored in `Coroutine::getContext()`. Use `$g->get`, `$g->post`, `$g->session`, `$g->server` instead of `$_GET`, `$_POST`, `$_SESSION`, `$_SERVER`. No superglobals are populated — process-wide writes to `$_GET` would race across coroutines and corrupt other requests.

This is the highest-throughput mode for ZealPHP-native code: zero subprocess overhead, full coroutine I/O concurrency, no per-request isolation mechanism needed (the `RequestContext` IS the isolation).

**Configuration:**

```php
App::superglobals(false);  // default — this line is optional
$app = App::init('0.0.0.0', 8080);
$app->route('/users/{id}', function(string $id) {
    $g = G::instance();  // per-coroutine instance
    return $g->get['format'] ?? 'json';
});
$app->run();
```

**When to use:** New ZealPHP applications, microservices, API backends, SSE/WebSocket-heavy apps where you control the PHP code and do not need `$_GET`/`$_POST` compatibility. Joomla, OpenCart, Kanboard pass in Mode 5 (they use request data only through framework helpers). DokuWiki, Adminer pass in Mode 5 (their PHP code uses `$_GET`/`$_POST` but ZealPHP populates `$g->get` which is aliased correctly in coroutine mode).

**Trade-off:** Legacy PHP code that reads `$_GET` / `$_POST` directly will see empty arrays. Migration is typically a search-and-replace, but ecosystem code (plugins, libraries) may not be under your control.

---

## 4. Choosing the Right Mode

```
Does your app require fresh global state per request?
├── Yes → Does it need high throughput? (> ~500 req/s)
│   ├── Yes → Mode 1 CGI Pool (persistent workers, ~5-10ms overhead)
│   └── No  → Mode 1 CGI Proc (maximum compatibility, ~30-50ms overhead)
└── No  → Does it use $_GET / $_POST / $_SESSION?
    ├── Yes → Do you want coroutine concurrency?
    │   ├── Yes, and ext-zealphp is available → Mode 4 Hybrid
    │   └── No  → Mode 3 Sync (recommended for most legacy-compatible apps)
    └── No, new ZealPHP-native app → Mode 5 Coroutine (default)
```

**Rule of thumb:** start with Mode 3. If your app breaks on the second request (function redeclaration errors), add `App::functionIsolation(true)`. If it still breaks, switch to Mode 1 CGI Pool. Reserve Mode 4 for apps that are Mode 3-compatible and need coroutine I/O throughput. Use Mode 5 for greenfield ZealPHP development.

---

## 5. Compatibility Sweep Results

The following results are from a 50-app Docker lab sweep on PHP 8.4.21 + OpenSwoole 26.2.0 + ext-zealphp 0.3.3. Full results with per-app analysis in [`docs/compatibility-database.md`](../compatibility-database.md).

### Summary by mode

| Mode | Pass rate | Notes |
|------|-----------|-------|
| **Mode 1 CGI Pool** | 38/50 | Broad compatibility — most traditional PHP apps work |
| **Mode 3 Sync** | 30/50 | Best for OOP frameworks; fails on apps with function redeclaration |
| **Mode 3 + functionIsolation** | ~38/50 | Closes the redeclaration gap; equivalent pass rate to Mode 1 with zero subprocess overhead |
| **Mode 4 Hybrid** | 25/50 | Requires ext-zealphp; limited by `$GLOBALS` race for stateful legacy apps |
| **Mode 5 Coroutine** | 15/50 | Highest performance for apps written to the ZealPHP API |

### Apps that pass all four tested modes

Kanboard, Joomla, OpenCart, Roundcube — these share a common pattern: no unguarded `define()`, no naked function declarations at file scope, no process-level singleton state that leaks between requests.

### Apps that require Mode 1

WordPress, Drupal, Magento 2, WooCommerce, phpBB, MyBB, MediaWiki, Matomo, FreshRSS, TinyFileManager, Adminer (without functionIsolation), Nextcloud. Common reasons: unguarded `define()`, function declarations without `function_exists()` guards, `$GLOBALS`-dependent static registries.

### Known subprocess crash cases (Mode 1 CGI Pool/Proc)

**phpMyAdmin** — hangs in the subprocess context in both proc and pool modes. Root cause is phpMyAdmin's MySQL initialization sequence in a non-FPM PHP subprocess (specific interaction with the `mysqli` extension initialization order). Works correctly in Mode 3 and Mode 4.

**DokuWiki** — pool worker dies during include in pool mode. DokuWiki's procedural bootstrap triggers a shutdown path incompatible with the IPC response protocol. Works correctly in Mode 3, Mode 4, and Mode 5 (tested: 200, 17–27 ms).

Both of these are Mode 1-specific CGI subprocess issues, not ZealPHP framework issues. The in-process modes handle them correctly.

---

## 6. Known Architectural Limitations

### Mode 1 (CGI Pool/Proc): subprocess constraints

The subprocess (`cgi_worker.php`) runs in a fresh PHP process communicating via IPC. Some PHP extensions initialize global C state during `MINIT` (module init) that is not re-initialized correctly when the extension's functions are invoked in a subprocess with different process-level state than the parent. `mysqli` is the documented case (phpMyAdmin). Extensions using `proc_open` or `popen` internally may recurse into the CGI dispatch path — `proc_open` and `popen` are intentionally NOT overridden by uopz for this reason.

Cookie round-trip: `Set-Cookie` headers from the CGI subprocess are not always forwarded in proc mode. This is tracked.

### Mode 4 (Hybrid): `$GLOBALS` is shared

`$GLOBALS` is a process-wide symbol table alias — it is not snapshotted per coroutine by ext-zealphp. Concurrent coroutines that write to `$GLOBALS['my_key']` directly (not via `$_GET`/`$_POST` etc.) will race. The standard PHP superglobals (`$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`, `$_SERVER`, `$_FILES`, `$_REQUEST`) ARE isolated per coroutine by ext-zealphp. Use `$g` (per-coroutine `RequestContext`) for any application-level request-scoped global state.

Apps with their own autoloaders (Composer PSR-4) that call `spl_autoload_register` once at process startup work correctly — the registered loader is process-wide and survives across requests. Function isolation cleanup is skipped for framework-registered autoloaders.

### Mode 5 (Coroutine): no superglobals

`$_GET`/`$_POST`/`$_SESSION` are not populated per request. Code that reads these directly returns empty arrays. This is intentional — populating process-wide arrays in concurrent coroutine mode would be a correctness bug. Use `$g->get`, `$g->post`, `$g->session` which are isolated per coroutine via `Coroutine::getContext()`.

### All modes: `$GLOBALS` is process-wide in coroutine contexts

In Modes 4 and 5, `$GLOBALS` is shared across all concurrent requests in a worker. Apps that store request-scoped data in `$GLOBALS` (e.g. `$GLOBALS['current_user'] = $user`) will have cross-request data leakage. In Modes 1 and 3, this is safe because requests are sequential (Mode 3) or isolated to a subprocess (Mode 1).

---

## 7. Application Server Features

All five execution models get these application server primitives — they live in the ZealPHP master process and are available to every worker regardless of the request lifecycle mode chosen.

**Connectivity:**
- Long-running HTTP/1.1 + HTTP/2 server via OpenSwoole
- WebSocket endpoints via `App::ws($path, $onMessage, $onOpen, $onClose)`
- Server-Sent Events via `$response->sse($fn)`
- TLS (pass `'ssl_cert_file'` + `'ssl_key_file'` to `$app->run()`)

**Concurrency (Modes 4/5):**
- Per-request coroutine scheduling via OpenSwoole
- `App::parallel(array $tasks): array` — fork-join concurrency helper
- `App::parallelLimit(array, callable, int $concurrency): array` — bounded fan-out

**Shared state:**
- `Store` (`OpenSwoole\Table` wrapper) — cross-worker shared-memory key-value
- `Counter` (`OpenSwoole\Atomic` wrapper) — lock-free cross-worker integer
- Redis/Valkey backend via `Store::defaultBackend('redis')` for cross-node state
- Tiered backend (L1 Table + L2 Redis) for ns-latency reads with cross-node consistency

**Messaging:**
- `Store::publish($channel, $payload)` / `App::subscribe($channel, $handler)` — fire-and-forget pub/sub
- `Store::publishReliable($stream, $payload)` / `App::subscribeReliable(...)` — Redis Streams, at-least-once delivery
- `WSRouter` + `WSRouter::room($name)` — federated WebSocket routing and rooms across nodes

**Scheduling:**
- `App::tick(int $ms, callable $fn)` — recurring per-worker timer
- `App::after(int $ms, callable $fn)` — one-shot timer
- Task workers via `App::getServer()->task([...])` with `task/` handler files

**Process management:**
- `App::addProcess($name, $fn)` — sidecar long-running process registration
- `App::onWorkerStart($fn)` / `App::onWorkerStop($fn)` — per-worker lifecycle hooks
- `App::onSignal(SIGUSR1, $fn)` — user signal hooks for config reload, stats dump, etc.
- `App::stats()` — per-worker counters (workers, store, memory, uptime)

---

## 8. SAPI-Like Features

These are the features that make ZealPHP behave like a traditional PHP SAPI rather than just a framework running on top of CLI PHP.

**Superglobals:** `$_GET`, `$_POST`, `$_SESSION`, `$_COOKIE`, `$_SERVER`, `$_FILES`, `$_REQUEST` are populated per request from OpenSwoole's request object (Modes 1/3/4). The `G::instance()` singleton aliases these to `$g->get`, `$g->post`, etc. for coroutine safety.

**Function overrides via uopz / ext-zealphp:** At startup, `uopz_set_return()` replaces:
- `header()`, `headers_list()`, `headers_sent()`, `header_remove()` — write to `$g->zealphp_response`
- `setcookie()`, `setrawcookie()` — write to the per-request response object
- `http_response_code()` — reads/writes the pending HTTP status
- `session_start()`, `session_write_close()`, `session_id()`, `session_destroy()`, etc. — coroutine-safe file-backed session implementation
- `exit()`, `die()` — intercepted per mode (shutdown handler in pool mode, coroutine termination in Mode 4/5)
- `shell_exec()`, `exec()`, `system()`, `passthru()`, backtick operator — route through `App::exec()` for coroutine-safe async execution (Modes 4/5)

**File-execution model:** `App::include('/foo.php')` runs PHP files from `public/` like a traditional SAPI:
- Populates `$_SERVER['PHP_SELF']`, `SCRIPT_NAME`, `SCRIPT_FILENAME` (Apache mod_php parity)
- Applies path traversal guard (`includeCheck()`) — refuses paths outside `public/`
- In Mode 1: dispatches to the CGI subprocess pool/proc
- In Modes 3/4/5: runs in-process via the shared `executeFile()` core

**Session lifecycle:** File-backed sessions in `/var/lib/php/sessions` by default. Pluggable backends: `TableSessionHandler` (single-node, cross-worker), `StoreSessionHandler` (pluggable: Table/Redis/Tiered), `RedisSessionHandler` (ext-redis direct). Session cookie lifecycle managed by `CoSessionManager` (Modes 1/4/5) or `SessionManager` (Modes 1-proc/3).

**Output buffering:** `ob_start()`, `ob_get_clean()`, `ob_flush()` work transparently — the framework uses `ob_start()` around every `executeFile()` call to capture echo output and route it through the universal return contract.

**PHP error handlers and shutdown functions:** `set_error_handler()`, `set_exception_handler()`, `register_shutdown_function()` are captured per-request and restored after the response is sent (Modes 3/4/5). In Mode 1, they are naturally per-process.

**`php://input`:** `IOStreamWrapper` replaces the `php://` stream wrapper in each worker. Reads from `php://input` return `$g->zealphp_request->parent->getContent()` — the OpenSwoole request body, not a process-wide stream.

---

## 9. Conclusion

ZealPHP is not just a routing layer on top of OpenSwoole — it is a runtime that combines an Application Server (always-on, long-lived, with coroutines, WebSocket, pub/sub, shared memory) with a multi-SAPI execution model (five distinct PHP request lifecycles covering the full spectrum from fresh-process isolation to native coroutine concurrency).

The right mode depends on your application:

- **WordPress, Drupal, legacy procedural apps** → Mode 1 CGI Pool: maximum compatibility, ~50 ms overhead amortized across the pool lifetime.
- **Symfony, Laravel, TYPO3, DokuWiki, phpMyAdmin** → Mode 3 Sync: FPM-level compatibility, zero subprocess overhead, sequential per worker.
- **Adminer, TinyFileManager, FreshRSS** → Mode 3 + `functionIsolation(true)`: closes the function redeclaration gap without a subprocess.
- **Joomla, OpenCart, Kanboard, Roundcube** → Mode 4 Hybrid: full coroutine concurrency with traditional PHP superglobals, requires ext-zealphp.
- **New ZealPHP apps, microservices, API backends** → Mode 5 Coroutine: highest performance, uses `$g->get`/`$g->post` API.

For 90% of cases, **Mode 3 (Sync + functionIsolation)** is the sweet spot: FPM-level compatibility without subprocess overhead, zero external dependencies, and straightforward debugging. Reach for Mode 4 when you need coroutine I/O concurrency and can install ext-zealphp. Use Mode 1 when your app genuinely needs fresh-process isolation per request.

---

## 10. Migration Ladder: `$GLOBALS` Coroutine Isolation Evolution

`App::coroutineGlobalsIsolation(true)` ships in ext-zealphp v0.3.6 as the **Option 3 deep-copy snapshot** strategy. The full evolution path:

### Stage 0 — Process-wide `$GLOBALS` (pre-v0.3.6)
- `$GLOBALS` is the shared `EG(symbol_table)` across all coroutines.
- Concurrent writes race; last-writer-wins on the leaf level.
- **Status:** the default before v0.3.6. Users had to use `$g` (per-coroutine `RequestContext`) for request-scoped state.

### Stage 1 — Deep-copy snapshot (Option 3, shipped in v0.3.6)
- ext-zealphp hooks `on_yield`/`on_resume` and snapshots the non-superglobal slots of `EG(symbol_table)` per coroutine.
- O(N keys) memory per active coroutine — for typical apps with <100 globals, this is ~50–200 KB per coroutine.
- **Replaced by Stage 2 in v0.3.7.** Stage 1 retained for reference.

### Stage 2 — Copy-on-write parent + delta (Option 2, **CURRENT** in v0.3.7)
- Shared parent `EG(symbol_table)` snapshotted once at activation (read-mostly baseline).
- Per-coroutine state has two tables:
  - **`deltas[cid]`** — slots the coroutine wrote that differ from parent
  - **`tombstones[cid]`** — parent slots the coroutine `unset()`'d (stored as `IS_LONG 1` dummies to avoid Zend's IS_UNDEF skip)
- Snapshot save (on_yield):
  1. Walk EG, compare each non-SG key against parent via `zval_identical` — emit to deltas only if different
  2. Walk parent, emit tombstone for any key absent from EG
  3. Reset EG to parent baseline so next coroutine starts clean
- Snapshot restore (on_resume):
  1. Reset EG to parent baseline
  2. Apply `deltas[cid]` over baseline
  3. `zend_hash_del` each key in `tombstones[cid]`
- **Memory:** O(deltas + tombstones) per coroutine. Verified on 50-coro × 5-unique-writes test: peak RSS stays flat at ~2 MB allocator-page granularity.
- **Caveat:** `global $foo;` across yield still rebinds against rebuilt slot (same as Stage 1). Use `$g->foo` for cross-yield references.

### Stage 3 — VM opcode handlers (theoretical, not on roadmap)
- True transparent COW with custom opcode handlers for `ZEND_FETCH_R/W/DIM_R/DIM_W` against `EG(symbol_table)`.
- Adds reference-tracking for `global $foo;` bindings across yield.
- Engineering cost: ~6000 lines of C, deep opcache integration, custom HashTable layout, fuzz testing.
- **Not planned** unless real production data shows Stage 2's tombstone+delta overhead matters.

### Memory characteristics (v0.3.7 Stage 2)

With Stage 2 COW, the boot-time advisory in `App::run()` projects peak memory based on:
- **Parent snapshot**: one-time cost, sizeof framework `$GLOBALS` at activation. Shared across all coroutines.
- **Per-coroutine delta+tombstones**: O(keys the coroutine wrote/unset that differ from parent). Typically empty for read-only coros, single-digit KB for normal writers.

For 1000 concurrent coroutines × 10 unique writes each: ~20 MB total (parent + sum of small deltas), vs Stage 1's ~200 MB (full copy × 1000).

### Why we shipped Stages 1 and 2 in the same release window

1. **Stage 1 closed the architectural gap immediately** — Mode 4/5 race on `$GLOBALS` no longer exists.
2. **Stage 2 layered on top** — same `on_yield`/`on_resume` hooks, swapped the storage strategy.
3. **Memory savings proven in lab** — 50-coro test went from ~10 MB Stage 1 to flat 2 MB Stage 2.
4. **No security regression** — same boundary semantics, same nTableMask guard, same ZVAL_COPY paired with hash table destructor.

### Known caveats (Stage 2)

- **`global $foo;` across yield**: same boundary as Stage 1. PHP's `global` keyword binds the local frame variable by-reference to `EG(symbol_table)['foo']`. When the symbol table slot is rebuilt during yield/resume, the reference is broken. Use `$g->foo` (per-coroutine `RequestContext`) for variables that must persist across yields.
- **Object identity**: PHP objects are passed by handle. Two coroutines accessing the same object via `$GLOBALS['db']` will share the object instance (this is normal PHP semantics — Stage 2 doesn't change it).
- **Coroutines launched inline via `Coroutine::create`**: OpenSwoole's `on_resume` doesn't fire for these. Stage 2's post-yield `reset_to_parent` ensures they still start from clean parent state.

---

## References

- [`docs/architecture/2026-05-27-lifecycle-matrix.md`](2026-05-27-lifecycle-matrix.md) — full configuration matrix with test results for all 10 mode/fallback combinations
- [`docs/architecture/2026-05-27-superglobal-isolation.md`](2026-05-27-superglobal-isolation.md) — deep-dive on ext-zealphp's C-level snapshot/restore mechanism (Mode 4)
- [`docs/compatibility-database.md`](../compatibility-database.md) — 50-app compatibility database with per-app grades across all modes
- [`docs/fastcgi-backends.md`](../fastcgi-backends.md) — CGI backend configuration, per-extension dispatch, ScriptAlias

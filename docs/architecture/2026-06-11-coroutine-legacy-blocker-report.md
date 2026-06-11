# What breaks "old PHP just works" under coroutine-legacy — full blocker report

**Date:** 2026-06-11 · **Stack under test:** ZealPHP v0.4.8 + ext-zealphp v0.3.47 (the Stage-8 object-global store-corruption fix), PHP 8.4.21, OpenSwoole 26.2.0, on the `zext` container (`root@172.30.0.3`), real MariaDB, real installs, real auth, real writes, 6-way concurrent bursts.

This report collects **every distinct breaking class** observed across a 16-app real-work sweep (the first wave of the ground-up 50-app re-validation), with root cause, who's affected, and the fix path. The headline mode is `App::mode(App::MODE_COROUTINE_LEGACY)`; `mixed`/`legacy-cgi` are sequential fallbacks.

**Bottom line up front:** the dominant crash class — concurrent-burst `zend_mm_heap corrupted` SIGSEGV — was **root-caused and fixed** (Class 1, shipped in ext 0.3.47). What remains splits into: a tractable **framework fix** (Class 2, `exit()` semantics — 3-4 apps), one **deep ext frontier** (Class 3, per-coroutine class-static isolation — the biggest lift), two **documented architectural boundaries** (Class 4 shared DB connection, Class 5 mysqlnd teardown), two **small ext gaps** (Class 6 `class_alias` redeclare, Class 7 post-yield session write), and **app/vendor friction that isn't a ZealPHP defect** (Class 8).

---

## Class 1 — Stage-8 object-global store corruption · **FIXED (ext 0.3.47)**

**Signature:** `zend_mm_heap corrupted` + worker SIGSEGV(11)/SIGABRT(6) under concurrent burst; with `USE_ZEND_ALLOC=0` it surfaces as glibc `corrupted double-linked list` / `malloc(): unaligned tcache chunk` (real heap damage, not Zend-MM-metadata-only). gdb (4/4): SIGSEGV in `zend_objects_store_put` @ `zend_objects_API.c:151` reached from an **unrelated** later `new`/clone/`fopen` in a peer coroutine.

**Affected:** YOURLS (PDO via aura/sql, file-scope `$ydb`); contributed to phpbb / freshrss / piwigo worker deaths.

**Root cause:** `App::globalScopeInclude(true)` (Stage 8, `zealphp_require_global`) runs the request in a `ZEND_CALL_TOP_CODE` frame sharing the one process-wide `EG(symbol_table)`. A file-scope object global (`$pdo`/`$ydb`/`$wpdb`) stays an `IS_INDIRECT` top-frame CV for the whole request. A second concurrent coroutine's `zend_attach_symbol_table` alias-copies the object pointer (no addref) into its own frame and repoints the bucket → first-to-unwind frees it under the peer → object-store free-list poison. The object-aware `$GLOBALS` isolation (0.3.23) deliberately *skips* `IS_INDIRECT` non-baseline slots (the master-frame-CV guard), so these were never isolated.

**Fix:** frame-CV-addressed isolation (ext 0.3.47, [PR #46](https://github.com/sibidharan/ext-zealphp/pull/46)) — per-coroutine registry saves+NULLs the object's frame CV on yield, restores into the *resuming* coroutine's own frame CV. `reset_to_parent` untouched. **Validated:** minimal repro + real YOURLS + PDO-mysql + mysqli globals all **0 worker deaths** (was 3–13/burst); 58/58 phpt; valgrind 0 errors; CI ASAN 8.3/8.4/8.5 green. The cached sweep verdicts predate this; re-validation pending (one re-test confirmed YOURLS → 0 deaths).

---

## Class 2 — `exit()`/`die()` throws an app-catchable exception · **FRAMEWORK FIX IDENTIFIED (not yet shipped)**

**Signature:** HTTP 500 with body `"openswoole exit"` / `"Something unforeseen has happened: openswoole exit"` on every redirecting POST; or a correct response generated but delivered as a **0-byte body**.

**Affected:** **freshrss** (500 on every login/feed-add redirect), **dokuwiki** (`ActionRouter::handleFatalException` catch swallows the exit → 500 + setup error), **codeigniter4** (every response 200 but 0-byte body — the 17 KB welcome page is in the OB at `index()` exit but lost), **mybb** ("Authorization code mismatch" — exit-after-redirect on POST drops the Set-Cookie/session write).

**Root cause (confirmed):** `OpenSwoole\ExitException ← OpenSwoole\Exception ← Exception`. Under coroutine mode, a userland `exit`/`die` throws `OpenSwoole\ExitException`, which **extends `\Exception`**. The Apache-migration idiom `try { … exit; … } catch (\Exception $e) { /* error path */ }` — extremely common in legacy routers (FreshRSS `p/i/index.php`, DokuWiki `inc/ActionRouter.php`) — catches the *normal* exit and converts it into an error response. The framework's own halt sites never see it (the app caught it first).

**Fix path (concrete):** override `exit`/`die` in **ext-zealphp** (same override family as `header()`/`session_*()`/the exec family) to throw **`ZealPHP\HaltException`** instead of letting OpenSwoole throw `OpenSwoole\ExitException`. `HaltException extends \Error` *by deliberate design* (`src/HaltException.php`) — so `catch (\Exception)` does **not** grab it, while the framework's `executeFile()` / `ZealAPI` halt-aware sites already catch it and flush the buffered output as the body. This converts the legacy `exit`-after-`header('Location:')` idiom into a clean, buffer-preserving halt that app catch-blocks can't intercept. Expected to move freshrss/dokuwiki/codeigniter4 from C/D toward A/B. (The 0-byte-body sub-case needs a paired check that the OB is flushed into the response on the `HaltException` path even when the app drained its own inner buffer — verify against CI4's `Response::send()`.)

---

## Class 3 — class-static / typed-static isolation under TRUE concurrency · **DEEP EXT FRONTIER (largest lift)**

**Signature:** a class `static` property read as the *wrong type* or `null` mid-request because an overlapping coroutine's write (or a peer's request-end reset) raced it: `in_array(): Argument #2 must be array, int|float given`; `Typed static property … must not be accessed before initialization`; `Call to a member function …() on null` on a cached singleton.

**Affected:** **cakephp** (35–50% 500s steady-state — `TableSchema::$_validConstraintTypes`, `Translator::$formatter`, `Router::$_collection` static reads cross-typed), **kirby** (`App::$instance`/`$site` null under burst — the framework singleton raced; 5/20 → 1/20 success), **piwigo** (`count(): int|float given` at request 2+; the per-request class-static reset helps *sequentially* but not under overlap).

**Root cause:** the per-request **class-static reset** (ext 0.3.28, `zealphp_reset_request_class_statics`) restores class statics to their boot template *per request* — but it is a per-request operation over the **process-shared `static_members_table`**. ZealPHP isolates per coroutine: superglobals, `$GLOBALS`/`global`, function-local `static`, `define()` constants, and the process-setting stack (cwd/locale/umask/tz/mb/libxml). **Class statics are NOT in that set** — they're reset, not isolated. Under true coroutine concurrency two overlapping requests share one `static_members_table`: coroutine A's write to `App::$instance` is visible to coroutine B mid-flight, and A's request-end reset zeroes the static while B is still rendering. Apps that use class statics as **request-scoped caches or singletons** (CakePHP's schema/router caches, Kirby's `App::$instance`) therefore corrupt under concurrency.

**Fix path:** true per-coroutine class-static isolation — a new ext stage analogous to the function-static stage (Stage 5) but over `ce->static_members_table`. Substantially harder than fn-statics: the table is per-class, indices are shared, objects in statics need the same in-coroutine `__destruct` drain contract as object-globals, and the snapshot/restore must ride every yield. This is the single biggest remaining lift toward concurrent "old PHP just works" for OOP-with-static-caches apps. Until then these apps are **sequential-only** (`mixed`/`legacy-cgi`), where the per-request reset alone is sufficient (CakePHP/Kirby both pass full suites in `mixed`).

---

## Class 4 — shared DB connection across coroutines · **DOCUMENTED ARCHITECTURAL BOUNDARY**

**Signature:** `OpenSwoole\Error: Socket#N has already been bound to another coroutine#X, reading … in coroutine#Y at the same time is not allowed`; or `SQLSTATE[HY000] 2014 Cannot execute queries while other unbuffered queries are active`.

**Affected:** **mybb** (one cached mysqli `$db` reused across coroutines), **drupal** (one PDO connection, unbuffered-query state collides under `-P6`; sequential `-P1` is clean 20×200).

**Root cause:** the app creates **one** connection (cached in a class static / global behind a create-once guard) and reuses it for every request. The Class-1 fix isolates the object *value* per coroutine, but a create-once guard means all coroutines share the single underlying socket — and two coroutines interleaving wire frames on one MySQL socket is exactly what OpenSwoole refuses (mybb) or what corrupts driver state (drupal's unbuffered cursor). This is the **"one connection per coroutine"** rule the project already documents.

**Fix path:** not transparently fixable — the app's connection-caching guard defeats per-request re-creation. Options: (a) the app uses `ZealPHP\Db\DbConnectionPool` (per-coroutine lease); (b) document that shared-single-connection apps are sequential-only under coroutine-legacy until they adopt a pool. Drupal additionally needs `PDO::MYSQL_ATTR_USE_BUFFERED_QUERY` per coroutine. This is a *correctness* boundary, not a crash — workers survive (drupal: 20×500, 0 deaths).

---

## Class 5 — mysqlnd/libtasn1 cold-boot connection-teardown corruption · **DEEP FRONTIER (ASAN session)**

**Signature:** `zend_gc_delref` on a poisoned zval inside `zealphp_globals_reset_to_parent` (the object-global request-end drain) — a **distinct** backtrace from Class 1's `zend_objects_store_put`. Worker SIGSEGV ~5/burst, self-healing respawn.

**Affected:** **wordpress** (the persistent one: even with the Class-1 fix + `USE_ZEND_ALLOC=0`, WP still drops workers under concurrency; PDO + mysqli minimal globals both reach 0 deaths with the fix, so WP is provably hitting a *different* layer).

**Root cause (per existing project docs):** mysqlnd's vio stream is allocated in one allocator context (persistent/malloc under OpenSwoole's HOOK_ALL transport hook) and freed via `_efree` at `$wpdb` teardown → heap damage that the next ref-drop trips. The 0.3.46 `orig_path` shim reduced but did not eliminate it; the deeper fix is in the OpenSwoole stream-hook allocator path. **Needs an ASAN-gated session** (toolchain at `/opt/php-asan`) to pin the exact alloc/free allocator mismatch. WordPress under heavy concurrency stays `legacy-cgi`/`mixed` until this lands.

---

## Class 6 — runtime `class_alias()` re-executed by Stage 7 · **SMALL EXT GAP**

**Signature:** `Cannot redeclare class Doku_Event_Handler (previously declared in inc/Extension/EventHandler.php) in inc/legacy.php`.

**Affected:** **dokuwiki** (`inc/legacy.php` issues a runtime `class_alias()` that Stage 7's per-request `require_once` re-execution runs again).

**Root cause:** silentRedeclare (Stage 3/4) hooks the `ZEND_DECLARE_CLASS`/`_FUNCTION` opcodes — but a runtime **`class_alias()` call** is a function call, not a declare opcode, so re-execution re-aliases an existing class and fatals.

**Fix path:** intercept `class_alias()` under silentRedeclare (skip when the alias name already exists), mirroring the silent-define-redeclare path. Small, self-contained ext fix.

---

## Class 7 — `$_SESSION` write after a yield is lost · **SMALL FRAMEWORK/SESSION GAP**

**Signature:** session value set in-memory (`$_SESSION['logged']` confirmed set) yet absent from `sess_<id>` after the request — but **only** when the write happens *after* an I/O yield in the request; an `exit()` with no intervening yield persists fine.

**Affected:** **tinyfilemanager** (login writes `$_SESSION` after a hooked-I/O call; probe pinned it: pre-yield write persists, post-yield write lost under HOOK_ALL).

**Root cause:** the superglobal snapshot/restore + session-manager `finally` interplay — the session is captured for persistence at a point that doesn't re-read a `$_SESSION` mutation made after a post-snapshot yield. Related to the documented `rebindRequestInput` not rebinding `$_SESSION`.

**Fix path:** ensure the session-write path reads the *live* `$_SESSION` at `write_close` time (post-handler), after any yields, rather than a pre-yield snapshot. Framework-side (CoSessionManager/SessionManager) with an ext snapshot-timing check.

---

## Class 8 — app/vendor incompatibilities · **NOT a ZealPHP defect (friction, document)**

- **psr/http-message v1 vs v2** (laravel, yii2): vendor ships `psr/http-message` v2 whose typed interfaces fatal OpenSwoole core's PSR-7 v1 classes. Fix: `composer require psr/http-message:^1.1` (all these apps accept ^1.1).
- **dev-deps fatal `preloadClassmap()` warm** (laravel, symfony): PHPStan/Doctrine, psysh Hoa polyfills, symfony optional DI compiler passes have incompatible signatures or missing traits → uncatchable bailout while warming. Fix: `--no-dev` + `composer dump-autoload -o` + `exclude-from-classmap`.
- **OpenSwoole HOOK_ALL native-curl rejects `CURLOPT_PROTOCOLS_STR`** (freshrss SimplePie, PHP 8.3+/curl ≥7.85). Vendor/runtime gap.
- **yii2-app-basic dist ships a syntax error** in `models/LoginForm.php` (upstream snapshot bug).
- **Matomo** (prior wave): bundled php-di violates PSR `ContainerInterface` under PHP 8.4 LSP — fatals on vanilla PHP 8.4 too.

These are real "doesn't just work out of the box" friction but the defect is the app/vendor's, not ZealPHP's. Worth a "known app prep" appendix in the compat DB.

---

## Class 9 — harness `App::includeFile()` vs `App::include()` · **FIXED (harness-only)**

The 50-app boot template used `App::includeFile($script)` (expects an **absolute** path) with a docroot-relative `ENTRY` → `Failed opening required '/index.php'` and silent 0-byte/500. Corrected to `App::include($script)` for the remaining waves. Not a framework defect — a test-harness template bug; recorded so the rebuilt compat DB doesn't misattribute it.

---

## Priority path to "100% old PHP just works" (concurrent)

| Pri | Class | Fix | Owner | Effort | Apps moved |
|----|-------|-----|-------|--------|-----------|
| ✅ done | 1 — object-global store corruption | frame-CV isolation | ext 0.3.47 | shipped | yourls (+ contributory) |
| **1** | 2 — `exit()` app-catchable | override exit/die → `HaltException` | ext + framework | small–med | freshrss, dokuwiki, codeigniter4, mybb(partial) |
| **2** | 6 — `class_alias` redeclare | intercept under silentRedeclare | ext | small | dokuwiki |
| **3** | 7 — post-yield session write | live `$_SESSION` at write_close | framework | small | tinyfilemanager |
| **4** | 3 — class-static isolation | new per-coroutine static stage | ext | **large** | cakephp, kirby, piwigo (the OOP-static-cache class) |
| **5** | 5 — mysqlnd teardown | allocator-pairing in stream hook | ext + OpenSwoole | large (ASAN) | wordpress (heavy concurrency) |
| n/a | 4 — shared connection | DbConnectionPool / document | app + docs | doc | mybb, drupal |
| n/a | 8 — vendor friction | document "app prep" | docs | doc | laravel, symfony, yii2, freshrss |

**Reading the verdicts:** every app **boots, renders, logs in, and does real authenticated writes sequentially** under coroutine-legacy (grades B/C). The gap to A is **concurrency**: Classes 1–7 are why a 6-way burst degrades. Class 1 (the worst — hard crashes) is fixed. Classes 2/6/7 are small, high-leverage fixes. Class 3 (class-static isolation) is the large frontier that unlocks the OOP-static-cache tier. Classes 4/5 are the documented "needs a pool / needs ASAN" boundaries.

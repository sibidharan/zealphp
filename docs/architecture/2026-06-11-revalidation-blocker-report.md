# Re-validation blocker report — what still blocks "100% old PHP just works"

**Date:** 2026-06-11 (evening re-grade) · **Stack:** ZealPHP master (`a8c1968`) + ext-zealphp v0.3.48, PHP 8.4.21, OpenSwoole 26.2.0, on the `zext` rig with real MariaDB.

This report is a **diagnosis snapshot taken AFTER** the day's fixes shipped (ext 0.3.47 Stage-8 object-store; ext 0.3.48 exit-hook; #376 ZealAPI scope; #379 session persistence; #386 session cluster; #387 session-security). It re-ran the session/exit-blocked apps against the fixed stack and root-classifies what remains. **No fixes are attempted here — this is the punch-list to drive the next fix+test cycle.**

---

## 1. What the fixes ACHIEVED (verified on the rig)

6-way concurrent burst (3–4 rounds × 20 req), real install + real MariaDB:

| App | homepage | burst distribution | worker deaths | vs. first sweep |
|-----|---------:|--------------------|:---:|---|
| **TinyFileManager** | 200 | **60×200** | **0** | **D → A/B** — login persists, fully clean. The #379 + cluster win. |
| **YOURLS** | 200 | 53×200 7×500 | **0** | stable, **0 deaths** (was 2–13); residual `YOURLS_SITE` constant 500s only. |
| **CodeIgniter 4** | 200 | 57×200 1×500 | 1 | **0-byte body fixed** (exit-hook) — now serves real bodies. |
| **FreshRSS** | 302 | 51×302 9×000 | 3 | **redirects work** (exit-hook) — was 500-on-every-POST; residual crash + hangs. |

These four prove the session-persistence + exit-hook fixes landed. The first two are effectively done.

---

## 2. The DOMINANT remaining blocker — concurrent-burst heap corruption

> **✅ RESOLVED 2026-06-12 — ext-zealphp v0.3.49 ([ext#52](https://github.com/sibidharan/ext-zealphp/issues/52) closed).** The ASAN session overturned the concurrent-compile theory below: the real bug was the engine's `zend_attach_symbol_table`/`zend_detach_symbol_table`/leave-reattach protocol MOVING values between frame CVs (no refcount change, single-flow assumption) while concurrent Stage-8 requests share `EG(symbol_table)` — a peer's attach hijacked `IS_INDIRECT` buckets and left stale aliases whose overwrite over-freed (ASAN 10/10: DokuWiki's top-level `$config_group`). ext 0.3.47 parked objects only; 0.3.49 parks ALL request-frame globals per yield + severs the bucket at park + parks `global $x`-bound REF slots (previously exempt by a pre/post-deref pointer bug) + restores through refs + scrubs on bailout. **Re-run of this section's matrix on v0.3.49:** DokuWiki 15–28 → **0**, phpMyAdmin 13–17 → **0**, Kanboard 0–16 → **0**, CodeIgniter4 → **0**; ASAN 0 reports, Valgrind 0 errors, pinned bidirectionally by ext `tests/062`. The section below is preserved as the original (mis)diagnosis record — note how the gdb `require_once` frame was a victim site, not the cause.

**Signature:** worker `SIGSEGV` (11) / `SIGABRT` (6) with **`zend_mm_heap corrupted`** under 6-way concurrent burst. Sequential traffic is clean.

**Affected (deaths per ~80-req burst):** phpMyAdmin 17, DokuWiki 15–28, Kanboard 0–16 (timing-sensitive), WordPress 6, Grav 3, FreshRSS 3, CodeIgniter4 1.

**gdb backtrace (Kanboard, 1 worker):**
```
zend_mm_panic ("zend_mm_heap corrupted")
  ← zend_mm_get_next_free_slot ← _emalloc(72)
  ← zend_string_init("/apps50/kanboard/public/app/Schema/Mysql.php")
  ← _php_stream_fopen ← zend_include_or_eval (require_once, type=16)
```
The crash *surfaces* at the next `require_once` allocation, but the heap was already poisoned by an earlier over-free — classic "victim site ≠ cause site."

**Differential diagnosis (the load-bearing evidence — A/B burst, deaths):**

| Config | DokuWiki | phpMyAdmin | Verdict |
|--------|:---:|:---:|---------|
| default (all isolation on) | 20–23 | 13–17 | baseline |
| `USE_ZEND_ALLOC=0` | 15 | 14 | **NOT the mysqlnd/libtasn1 vio-teardown class** (ext#49) — that one *vanishes* under USE_ZEND_ALLOC=0. Barely moves here. |
| `ZEALPHP_INCLUDE_ISOLATION_DISABLE=1` (Stage 7 off) | 28 | 11 | not a clean Stage-7 fix — disabling it doesn't drop to 0 (and worsens DokuWiki). |
| `ZEALPHP_GLOBALS_ISOLATION_DISABLE=1` (Stage 2 off) | 18 | 15 | not the globals stage either. |

**Conclusion:** the dominant remaining crash is a **concurrent-compile / require_once-bootstrap memory-safety bug that is NOT attributable to any single isolation stage and is NOT the mysqlnd allocator class.** It is the broader **cold-concurrent-autoload / duplicate-CE / silent-redeclare concurrent-compile frontier** — when multiple coroutines first-compile the same `require_once`'d (often inherited) class graph simultaneously, an over-free corrupts the heap. Timing-sensitive (Kanboard ran 0 deaths one round, 16 the next).

**Why this matters / why it's NOT a quick patch:** it's a heap corruption in the engine's compile path under coroutine concurrency. It needs an **ASAN-gated session** (toolchain at `/opt/php-asan`) to pin the exact alloc/free mismatch — the same rigor the Stage-8 fix got (minimal repro + valgrind + phpt gates). Blind-patching a heap corruption is how regressions ship.

**Mitigation that works TODAY (documented):** `App::preloadClassmap()` (Composer `--optimize`) warms the class graph single-coroutine at worker-start, dodging the concurrent first-compile. The sweep harness apps don't call it; real apps should. Pure-`require_once` apps with no autoloader (classic DokuWiki/phpMyAdmin bootstrap) can't preload → their concurrent home stays `legacy-cgi` until this lands.

**→ New ext issue to file:** "concurrent require_once-bootstrap compile heap corruption — NOT mysqlnd, NOT a single isolation stage; needs ASAN" with the Kanboard backtrace + the 4-way toggle A/B table above.

---

## 3. App-specific residual blockers (on top of §2)

- **Grav (D):** two independent issues beyond the burst crash — (a) ZealPHP's session-unserialize whitelist (`allowed_classes=['stdClass']`) can't round-trip Grav's `Messages` object → `__PHP_Incomplete_Class` → serialize-length desync → 500 on every session-carrying request (hard framework wall, not app-overridable); (b) `Invalid resource theme://` locator/`Kirby::$instance`-style singleton race under concurrency. Session-decode half is fixed by `mixed`; admin still breaks on constant re-defines.
- **phpMyAdmin:** custom session name (`phpMyAdmin`, not `PHPSESSID`) — the lazy-session gate keys on `PHPSESSID`, so its session machinery may need the name wired; re-test now that #379 landed (the CSRF-token-loop root cause may already be gone — needs a fresh login probe, not just the burst).
- **WordPress (6 deaths):** the documented mysqlnd/libtasn1 cold-boot vio-teardown corruption (ext#49) — distinct backtrace (`zend_gc_delref` in the object-global drain), the one class `USE_ZEND_ALLOC=0` *does* affect. Separate ASAN frontier.

---

## 4. Guru's open backlog — 19 remaining (none block §1/§2, but real)

**Closed this session (8):** #379, #369, #372, #373, #374, #375 (session correctness); #371, #343 (session security).

**Still open, clustered:**
- **Session security (2):** #342 CSRF prefix-match bypass, #355 unsolicited PHPSESSID on every request.
- **HTTP / Range / conditional-GET (8):** #358 sendFile full-body-on-HEAD, #360 static-handler %00/HEAD, #361 Content-Disposition `filename*`, #362 If-Range weak-ETag, #363 HTTP-date parsing, #364 PATH_INFO truncation, #365 empty byte-range-set, #366 multipart/byteranges Content-Length.
- **Routing / static (5):** #356 `$_REQUEST` precedence inverted, #357 header_register_callback in legacy-cgi, #359 `/.well-known/` unservable, #367 favicon/robots prefix, #368 dotfile status inconsistency.
- **Other (4):** #344 non-atomic Store increment, #354 SSR streaming crash in mixed mode, #370 status-code flatten, #294 Apache parity (mod_cache), #167 WordPress compat tracking.

These are well-specified protocol-parity / correctness bugs, orthogonal to the coroutine-legacy app-compat goal. Next themed clusters after §2.

---

## 5. The punch-list (priority order)

1. **§2 concurrent-compile heap corruption** — ASAN session → ext fix. Unblocks the most apps (phpMyAdmin/DokuWiki/Kanboard/+ contributes to WP/Grav/FreshRSS). **Biggest lever.**
2. **Re-grade with `preloadClassmap()`** on the Composer apps (Kanboard, CodeIgniter4) — likely lifts them without an ext fix; confirms the mitigation.
3. **phpMyAdmin custom-session-name** wiring + fresh login probe (post-#379).
4. **WordPress mysqlnd teardown** (ext#49) — separate ASAN frontier.
5. **Grav session-object whitelist** — design decision (configurable allowed_classes vs documented limitation).
6. **Guru's HTTP/Range/static clusters** (§4) — themed PRs.
7. **Pending-24 sweep** on the fully-fixed stack (capacity-gated).

**Honest status:** the session/login/exit blockers (the most common app-breaking patterns) are **fixed and verified**. The wall to "100%" is now a **single dominant memory-safety class** (§2) plus the long tail (§3–§4). §2 is the next focused fix+test cycle.

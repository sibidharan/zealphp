# CRITIC.md — Public Technical Review Log

A retrospective record of every substantive technical critique ZealPHP has received in public review, our assessment, and what we shipped in response. Maintained as an internal learning document — when the same critique surfaces twice, we want to know what we said the first time and whether anything changed.

---

## Summary

| Window | Forum | Releases triggered |
|---|---|---|
| 2026-05-15 → 2026-05-16 | r/PHP thread + #phpc Discord | v0.2.4, v0.2.5, v0.2.6, v0.2.7, v0.2.8 (framework) + v0.2.4, v0.2.5, v0.2.6, v0.2.7, v0.2.8, v0.2.9 (scaffold) |

Five framework releases plus scaffold sync in 24 hours, all triggered by community technical review. This document captures **what was raised**, **how we assessed it**, and **what we did about it**, in one place — so the next time the same point surfaces we don't relitigate.

---

## Critics by handle (with their contribution)

| Handle | Forum | Primary contribution |
|---|---|---|
| original r/PHP critic | Reddit | God-object / `__get`/`__set` / singleton critique (most surface-level read, but framed the whole conversation) |
| 8-year Swoole vet | Reddit | "Just describes Swoole" / reverse-proxy / 50-line cURL fallback argument (sharpened our positioning) |
| memory-management commenter | Reddit | "PHP wasn't built for long-running" → drove the `max_request` default |
| **henderkes** | Discord | **Sharpest single critic.** Found the CRLF/NUL header-injection security flaw plus most of the architectural smells fixed in v0.2.6 |
| olle.haerstedt | Discord | Confirmed henderkes' return-by-ref + dynamic-properties concerns with a more compact framing |
| Tiffany | Discord | "Is it a security nightmare?" — the lurker question that mattered most to address publicly |
| PHPStan-level-1 mocker | Discord | No technical content; useful for sharpening the level-1 trade-off explanation |
| **coroutine-isolation commenter** | Reddit (latest) | **Second-sharpest critic.** Articulated the discipline-contract framing: isolation + recycling, not either alone. Raised `ini_set`/handler-stack/pool-poisoning/opcache as the four real production-trust gaps |

---

## Critiques by theme

### Security

#### CRLF / NUL header injection (HTTP response splitting)
- **Raised by:** henderkes (Discord) — flagged a "severe security flaw" without specifics
- **Assessment:** Real, high severity. PHP native `header()` rejects `\r\n\0` in values since 4.4.2 to prevent response splitting; our uopz override forgot to replicate that. Application code using `header("X-Foo: " . $userInput)` with CRLF in `$userInput` became exploitable when ported to ZealPHP — even though the same code is safe on PHP-FPM.
- **Shipped in:** **v0.2.5**
- **Code touched:**
  - `src/utils.php` — `header()`, `setcookie()`, `setrawcookie()` guards
  - `src/HTTP/Response.php` — `Response::header()`, `Response::redirect()` guards
  - `tests/Unit/SecurityTest.php` — 9 new regression tests
- **Status:** ✅ Fixed and regression-tested

#### `setrawcookie()` filter was over-aggressive
- **Found by:** internal regression discovery during smoke testing v0.2.6
- **Assessment:** v0.2.5's CRLF guard for `setrawcookie` was too strict — it rejected `,`, `;`, ` `, `\t`, `\013`, `\014` in raw cookie values. PHP native only rejects `\r\n\0` in the value (the response-splitting vector); the rest are legal cookie-octets that callers explicitly use the "raw" variant to pass through unchanged. Broke `tests/Integration/ApacheParityTest::testSetRawCookieDoesNotUrlEncode`.
- **Shipped in:** **v0.2.7**
- **Status:** ✅ Filter now matches PHP native exactly

### Architecture — RequestContext (was G)

#### Class name "G" was opaque
- **Raised by:** henderkes
- **Assessment:** Fair. Single-letter name signaled nothing about purpose.
- **Shipped in:** **v0.2.6** — renamed `class G` to `class RequestContext`, kept `\ZealPHP\G` as a `class_alias` for backward compatibility (zero break for existing code referencing the old name)
- **Status:** ✅ Both names work; new code and docs use `RequestContext`

#### Response state living on G instead of Response
- **Raised by:** henderkes (`response_headers_list`, `response_cookies_list` etc. belonged on the Response object that owned them)
- **Assessment:** Correct architectural smell. Headers/cookies are properties of a Response, not properties of a per-request global. The framework had them on G as a side effect of supporting uopz overrides.
- **Shipped in:** **v0.2.6**
- **What moved:** `$g->response_headers_list` → `$response->headersList`, `$g->response_cookies_list` → `$response->cookiesList`, `$g->response_rawcookies_list` → `$response->rawCookiesList`
- **Enabled by:** discovering `prefork_request_handler()` was dead code (no callers) — the only place that read response state outside of an active Response. Once removed, the move was safe.
- **Status:** ✅ Moved cleanly; framework internals and tests updated; uopz `header()` / `setcookie()` shims still work transparently for legacy code

#### `apache_env` / `apache_notes` placement on G
- **Raised by:** henderkes (called them "leftover")
- **Assessment:** Partially wrong — they're actively used by `apache_setenv()` / `apache_getenv()` / `apache_note()` shims (legacy code via the CGI bridge). But architectural placement was wrong: legacy shim state shouldn't live on the main per-request struct.
- **Shipped in:** **v0.2.6**
- **What moved:** `$g->apache_env` / `$g->apache_notes` → `$g->apacheContext` (a `ZealPHP\Legacy\ApacheContext` instance, lazy-allocated on first `apache_setenv()`)
- **Status:** ✅ Cleanly separated; only allocated when legacy code actually touches it

#### `#[AllowDynamicProperties]` + typed declaration mix
- **Raised by:** henderkes, echoed by olle.haerstedt
- **Assessment:** Correct. Mixing the attribute with typed slots meant typos like `$g->zealphp_reqeust = ...` silently created a dynamic property.
- **Investigation:** Only three legitimate dynamic writes existed in the entire codebase — `cache_expire`, `cache_limiter`, `session_module_name`, all in session shim code.
- **Shipped in:** **v0.2.6** — declared the three as typed properties; removed `#[AllowDynamicProperties]`; undeclared writes in coroutine mode now throw `BadMethodCallException`
- **Status:** ✅ Typos surface immediately

#### Return-by-reference autovivification
- **Raised by:** henderkes ("returning by reference from arrays lets your array grow on non-existent key access"), olle.haerstedt
- **Assessment:** Correct. `&$g->nonexistent_key` would create a dynamic property on first read because the code path called `$this->$key = null` before returning the reference.
- **Shipped in:** **v0.2.6** — coroutine-mode `__get` now returns a reference to a local `null` if the key isn't a declared property; no property creation
- **Status:** ✅ Bounded blast was per-coroutine anyway, but behavior was wrong and is now correct

#### Schizophrenic write behavior
- **Raised by:** henderkes ("some writes surface back to globals, others don't")
- **Assessment:** Correct. Three destinations for `$g->foo = $x`:
  - Declared prop → direct slot (bypasses `__set`)
  - Undeclared prop in superglobals mode → `$GLOBALS[$key]`
  - Undeclared prop in coroutine mode → dynamic property
- **Shipped in:** **v0.2.6** — strict `__set` now throws on undeclared writes in coroutine mode. Superglobals mode keeps the `$GLOBALS` bridge for legacy compatibility.
- **Status:** ✅ Coroutine mode has one and only one destination for every key

#### Redundant `isset($g->session)` check
- **Raised by:** henderkes ("using isset incorrectly")
- **Assessment:** Correct, minor. `$g->session` is a declared typed property with default `[]` — always set. The outer isset in `CoSessionManager` was always true.
- **Shipped in:** **v0.2.6**
- **Status:** ✅ Cosmetic but fixed

#### `debug_backtrace` in `G::instance()`
- **Raised by:** henderkes ("debug_backtrace to... do what?")
- **Assessment:** Mostly wrong as a performance critique — only fires when `self::$instance === null` (once per worker in superglobals mode, never in coroutine mode). It's once-per-worker init code, not a hot path. **But:** it was ugly dev tracing that shouldn't be in production.
- **Shipped in:** **v0.2.6** — removed
- **Status:** ✅ Gone

#### "Singleton in an async server"
- **Raised by:** original r/PHP critic, restated by henderkes
- **Assessment:** Surface-level read. `RequestContext::instance()` returns from `Coroutine::getContext($cid)` in coroutine mode — per-coroutine state, not a process-wide singleton. Same pattern Hyperf uses.
- **No code change needed** — the critique was about the code already doing what the critic suggested as the fix.
- **Status:** ✅ No-op; addressed by clearer docs

#### "Everything public"
- **Raised by:** henderkes
- **Assessment:** Defensible. Required for uopz overrides which write from outside the class. Performance-justified (~2ns slot access vs ~50ns through getters).
- **No change** — documented trade-off.

### Architecture — dead code & static analysis

#### `prefork_request_handler` was dead code
- **Found by:** user during v0.2.6 audit ("prefork is now all CGI right?")
- **Assessment:** Correct. Predates the CGI bridge (`src/cgi_worker.php`), has zero callers in framework, scaffold, or any documented user code. Was the only consumer of `$g->response_headers_list` that ran without an attached Response — which was blocking the response-state move to the Response class.
- **Shipped in:** **v0.2.6** — deleted
- **Status:** ✅ Gone; unblocked the response-state refactor

#### PHPStan level 1 ceiling
- **Raised by:** "snakeoil salesmen" mocker (insult only, no specifics)
- **Assessment:** Deliberate trade-off, not laziness. Higher PHPStan levels conflict with:
  - uopz overrides on PHP built-ins (PHPStan can't see that `header()` writes to `$response->headersList`)
  - Dual-mode runtime branching (`App::$superglobals`)
  - `mixed` types on proxy properties forwarding to OpenSwoole via `__call`
  - CGI bridge serializing/deserializing across processes
  - Reflection-based parameter injection
- **No code change needed** — Symfony/Laravel/Mezzio score level 9 but don't run unmodified PHP-FPM code. Different problems.

#### PHPStan failure after G→RequestContext rename
- **Discovered by:** CI run on v0.2.7 — 90 errors, "Call to static method instance() on an unknown class ZealPHP\G"
- **Assessment:** PHPStan does static analysis at parse time; `class_alias()` runs at runtime. PHPStan never sees the alias registration, so `\ZealPHP\G::instance()` calls throughout framework code became "unknown class."
- **Shipped in:** **v0.2.8** — migrated 97 internal call sites across 17 files from `G::` to `RequestContext::`. Alias stays for external user code.
- **Status:** ✅ PHPStan now 0 errors

### Production trust & lifecycle

#### Memory leaks in long-running PHP workers
- **Raised by:** memory-management commenter on Reddit
- **Assessment:** Correct. PHP's engine assumes request-end = process-end. Long-running workers break that — static caches, closure captures, leaky extension state all accumulate.
- **Shipped in:** **v0.2.4** — `max_request=100000` default (bounded worker recycling, configurable via `ZEALPHP_MAX_REQUEST` env var, set `0` to disable)
- **Status:** ✅ Backstop in place by default

#### Discipline contract — user-level static state is not isolated
- **Raised by:** coroutine-isolation commenter on Reddit
- **Assessment:** Sharp and exactly right. `Coroutine::getContext($cid)` isolates state the framework stores there, but `static $foo` inside user functions and `private static $instance` on user classes live in worker process memory and survive every coroutine boundary. The isolation guarantee is a **discipline contract**, not a runtime guarantee. Hyperf and RoadRunner ship worker-recycling for exactly this reason.
- **Already partially shipped:** v0.2.4's `max_request` default IS the backstop the commenter described
- **Gap:** *visibility* — users don't know about the discipline contract until something bites them
- **Outstanding work (this is the next sprint):**
  - "What survives a request" docs page with the coroutine safety matrix
  - `RequestContext::once($key, $fn)` helper to give users a safe alternative to `static $cache`
  - Worker-recycle access-log line ("worker N recycled after K reqs, peak RSS X MB")

#### `ini_set()` changes survive across requests
- **Raised by:** coroutine-isolation commenter
- **Assessment:** True, and ZealPHP currently doesn't snapshot/restore ini values around requests. `date.timezone`, `error_reporting`, `display_errors`, `memory_limit`, etc. mutated by request N still affect request N+1.
- **Shipped:** not yet
- **Recommended:** opt-in middleware that snapshots `ini_get_all('', false)` at request start, restores changed keys at end. ~30 LOC.

#### Error / exception / shutdown handler stacks accumulate
- **Raised by:** coroutine-isolation commenter
- **Assessment:** True in superglobals mode, **false in coroutine mode**. Coroutine mode: `$g->error_handlers_stack`, `$g->exception_handlers_stack`, `$g->shutdown_functions` live on the per-coroutine `RequestContext` and die with the coroutine. Superglobals mode: G is a process-wide singleton, the stacks accumulate.
- **Shipped:** not yet
- **Recommended:** explicit reset in `SessionManager::__invoke` for superglobals mode. ~5 lines.

#### Pooled connections carry session state (THE BIG ONE)
- **Raised by:** coroutine-isolation commenter
- **Assessment:** This is the classic Swoole-era production fire. An unfinished `BEGIN`, a `SET SESSION sql_mode`, a `CREATE TEMPORARY TABLE` on a PDO connection at request N can poison request N+47 when the pool wraps. Same for Redis (`MULTI` without `EXEC`, `SUBSCRIBE` state, `SELECT 3` database switch).
- **Current state:** ZealPHP doesn't ship a connection pool. Users bring their own. Strictly the bug surface is in user code, but the framework hosts the runtime that makes pools viable and owns the guidance.
- **Shipped:** not yet
- **Recommended:** ship `ZealPHP\Pool\PDOPool` and `ZealPHP\Pool\RedisPool` with reset-on-checkout semantics. Default reset SQL includes `ROLLBACK`, `SET SESSION sql_mode = @@global.sql_mode`, `DEALLOCATE PREPARE ALL` etc. ~1-2 days of work; biggest production-trust win we can ship.

#### opcache `revalidate_freq` in CGI-parity mode
- **Raised by:** coroutine-isolation commenter
- **Assessment:** Real but narrower than it sounds. Main worker isn't affected (routes load once at `App::run()` startup, opcache invisible to live edits anyway). CGI bridge children are: each child reads opcache from shared memory and could serve stale bytecode for up to `revalidate_freq` seconds after a deploy.
- **Shipped:** not yet
- **Recommended:** doc note in `docs/deployment.md` recommending `opcache.validate_timestamps=0` + restart-on-deploy for production. ~15 minutes.

#### uopz `header()` / `$_GET` / `$_POST` across concurrent coroutines
- **Raised by:** coroutine-isolation commenter (earlier)
- **Assessment:** In coroutine mode (default): safe. Uopz overrides write to `$g->zealphp_response` and `$g->get`/`$g->post`, all of which live on `Coroutine::getContext()` — per-coroutine. In superglobals mode: single-coroutine-per-request by design (no `go()` inside handlers).
- **Gap:** docs don't make the per-mode safety contract crystal clear
- **Recommended:** coroutine safety matrix in the "What survives a request" docs page

#### `OpenSwoole\Table` consistency on worker crash
- **Raised by:** coroutine-isolation commenter (earlier)
- **Assessment:**
  - Per-row spinlocks at the C level — a single `$table->set($key, $row)` is atomic; readers see old or new, never partial
  - Multi-`set()` updates to same row are NOT atomic across calls
  - `incr` / `decr` / `compareAndSet` (via `Atomic`) ARE atomic
  - **SIGKILL mid-write can leave the spinlock held** — no robust mutex release on holder death
  - Graceful shutdown (SIGTERM, including the `max_request` recycle) releases locks normally
- **Recommended:** docs page on Store semantics. Set expectations correctly: best-effort cache, not a database. For ACID needs use Postgres/Redis with explicit transactions + the pool reset story.

### Positioning & framing

#### "Just describes Swoole, why the extra layer?"
- **Raised by:** 8-year Swoole vet on Reddit
- **Assessment:** Mostly framing miscommunication. ZealPHP is the framework on top of the OpenSwoole runtime — same relationship Laravel has to PHP-FPM. The vet's expectation was that a Swoole user already has all the framework pieces.
- **Resolved by:** ICP clarification — ZealPHP isn't for 8-year Swoole vets (they've built it themselves). It's for PHP-FPM-era devs who want async without rewriting their mental model.
- **No code change.** This was a positioning correction in how we describe the project.

#### "AI slop, 50 lines of cURL would replace it"
- **Raised by:** same Swoole vet
- **Assessment:** Hyperbole. The CGI bridge is 284 lines (`src/cgi_worker.php`). A correct cURL-fallback forwarder that handles real legacy code (multipart, Set-Cookie via `CURLOPT_HEADERFUNCTION`, X-Forwarded-*, SSE streaming, Range requests, WebSocket detection) is 200-400 lines. Same complexity, different boundary (one process vs two).
- **Resolved by:** restated as "one stack instead of two during migration" — a niche trade-off, not the whole product.

#### "Reverse proxy is the right answer"
- **Raised by:** same Swoole vet
- **Assessment:** Right for shops that already run FPM. The bridge is for shops that explicitly don't want two runtimes. Both are valid migration ladders.
- **Resolved by:** acknowledging the alternative; the bridge has a smaller niche than the original framing implied.

#### "Does the bridge look like the whole framework?" (calibration check, not a claim)
- **Raised by:** user (mid-conversation calibration question — *"the bridge is not the whole zealphp right!?"*)
- **Assessment:** The question itself was the value. The user wasn't asserting that the bridge was the whole framework — they were checking whether the *public framing* had narrowed to that point, because the assistant kept over-emphasizing the bridge in replies as a reaction to attackers. The check was correct: the thread had drifted into debating one optional feature.
- **Resolved by:** explicit reframe of the project — a Swoole framework with file-based routing, htmx-first templates, streaming primitives, single-binary deploy, 8 PSRs, *plus* an optional CGI bridge for the migration tail. The bridge is one feature, not the headline.

---

## Version-by-version trace

### v0.2.4 — Worker recycling + scaffold coroutine mode
**Triggered by:** memory-management commenter

- Added `max_request=100000` default to `App::run()` settings ([src/App.php:1503-1509](src/App.php#L1503-L1509))
- Added `ZEALPHP_MAX_REQUEST` env var override + documented in `docs/deployment.md` and `template/pages/deployment.php`
- Scaffold's `app.php` now ships with `App::superglobals(false)` set explicitly (coroutine mode default for new projects)

### v0.2.5 — Security: CRLF/NUL injection guards
**Triggered by:** henderkes

- CRLF/NUL guards in `header()`, `Response::header()`, `Response::redirect()`, `setcookie()`, `setrawcookie()`
- 9 new regression tests in `tests/Unit/SecurityTest.php`
- Matches PHP native behavior (emits `E_USER_WARNING` + returns `false`, or throws `InvalidArgumentException` for `redirect()`)

### v0.2.6 — Structural cleanup (largest release)
**Triggered by:** henderkes (most of it), olle.haerstedt (return-by-ref echo), user audit (prefork deletion)

- **Renamed** `class G` → `class RequestContext`; `\ZealPHP\G` preserved as `class_alias`
- **Moved response state off G:** `response_headers_list`, `response_cookies_list`, `response_rawcookies_list` now live on `Response` as `$response->headersList`, `$response->cookiesList`, `$response->rawCookiesList`
- **Moved Apache shim state off G:** `apache_env` / `apache_notes` now live on `ZealPHP\Legacy\ApacheContext` (lazy-allocated as `$g->apacheContext`)
- **Removed `#[AllowDynamicProperties]`:** three previously-dynamic props (`cache_expire`, `cache_limiter`, `session_module_name`) now declared as typed properties; undeclared writes in coroutine mode throw `BadMethodCallException`
- **Fixed return-by-reference autovivification** in coroutine-mode `__get`
- **Deleted dead `prefork_request_handler()`** (predecessor to CGI bridge, zero callers)
- **Removed `debug_backtrace()`** from `RequestContext::instance()`
- **Simplified redundant `isset($g->session)`** in `CoSessionManager`

### v0.2.7 — Relaxed setrawcookie filter
**Triggered by:** internal regression test discovery

- v0.2.5's `setrawcookie` filter was over-strict (rejected `,`, `;`, ` `, `\t`, `\013`, `\014` in value)
- Relaxed to match PHP native: rejects only `\r\n\0` in raw cookie values
- Restored compatibility with `testSetRawCookieDoesNotUrlEncode`

### v0.2.8 — PHPStan CI green
**Triggered by:** CI failure on v0.2.7

- Migrated 97 internal call sites across 17 files from `G::` → `RequestContext::` (PHPStan doesn't follow runtime `class_alias`)
- Class alias for external user code unchanged
- PHPStan: 0 errors (was 90 on v0.2.7)
- Type hints `\ZealPHP\G $g` → `\ZealPHP\RequestContext $g` in `Response.php` and `RangeMiddleware.php`

### Scaffold v0.2.9 — Packaging fix
**Triggered by:** Packagist CDN cache race during v0.2.8 scaffold sync

- Original v0.2.8 scaffold tag was published with stale vendor (Packagist CDN was caching the older p2 response when `composer update` ran)
- Force-tagging at GitHub didn't help — Packagist's tag→commit cache is sticky
- Solution: fresh `v0.2.9` scaffold tag at the corrected commit, no force-push
- Lesson: when force-tag is needed for behavior, Packagist will refuse to re-index. CLAUDE.md's "cut a new tag" guidance is correct.

### v0.2.10 — Discipline-contract sprint
**Triggered by:** coroutine-isolation commenter on Reddit (the "per-coroutine isolation only covers framework state, user statics still leak" framing)

- Added `RequestContext::once($key, $fn)` + `has()` + `forget()` — request-scoped memoization helper, mirrors Laravel 11's `once()`. Safe alternative to `static $cache = []`.
- Added worker-recycle access-log line: `[recycle] worker N exited after K requests, peak RSS X MB, uptime Ys` (silence with `ZEALPHP_RECYCLE_LOG=0`). Makes the `max_request` backstop visible in production logs.
- Added `IniIsolationMiddleware` (opt-in via `ZEALPHP_INI_ISOLATE=1`) — snapshots `ini_set()` changes at request entry, restores them at exit.
- Fixed handler-stack accumulation across requests in superglobals mode (`SessionManager::__invoke` now resets `$g->error_handlers_stack`, `$g->exception_handlers_stack`, `$g->shutdown_functions`). Coroutine mode was already safe.
- New docs sections: "What survives a request" + coroutine safety matrix on `/coroutines`, Store consistency semantics on `/store`, OPcache production tuning in `docs/deployment.md`.
- Created CRITIC.md (this file).

### v0.2.11 — Open-redirect hardening + docs cleanup
**Triggered by:** test-coverage audit that uncovered a leading-whitespace bypass of the v0.2.5 redirect scheme guard

- **Security:** `Response::redirect()` now rejects leading/trailing whitespace in the URL. v0.2.5's `preg_match('#^(javascript|data|vbscript):#i', $url)` was bypassable with `   javascript:alert(1)` — browsers strip leading whitespace from `Location` header values before parsing, so the malicious URL slipped past the scheme regex and executed.
- **Security:** Backslash anywhere in the redirect URL is also rejected (`/\evil.com`, `\\evil.com` are parsed as protocol-relative redirects by many browsers — defense in depth against same bypass class).
- 7 new redirect regression tests in `tests/Unit/SecurityTest.php`.
- 17 new tests in `tests/Unit/RequestContextInvariantsTest.php` pinning the v0.2.6 architectural contracts (G ↔ RequestContext class_alias, strict __set, response state location, ApacheContext lazy alloc, etc.).
- Website docs cleanup: deployment env var table rewritten with all 20 `ZEALPHP_*` env vars; migration page updated for v0.2.6 response-state move; sessions page notes the rename + handler-stack reset; middleware page adds `SessionStartMiddleware` + `IniIsolationMiddleware` entries; README drops the deleted `prefork_request_handler` reference.

---

## Outstanding work — v0.3 sprint

In priority order (by ROI = risk reduction × user visibility):

| Item | Effort | Outcome |
|---|---|---|
| **"What survives a request" docs page + coroutine safety matrix + Store semantics** | 2-3h | Sets expectations correctly. Closes the visibility gap on the discipline contract. |
| **`ZealPHP\Pool\PDOPool` + `RedisPool` with reset-on-checkout** | 1-2d | Closes the #1 production-trust gap (connection poisoning). |
| **`RequestContext::once($key, $fn)` helper** | 1-2h | Safe alternative to `static $cache` for request-scoped memoization. |
| **`ini_set` snapshot/restore middleware** (opt-in via `ZEALPHP_INI_ISOLATE=1`) | 1h | Catches a real footgun that doesn't manifest until under load. |
| **Worker-recycle access-log line** | 30min | Makes the `max_request` backstop *visible* in production logs. Strongest "trust story" signal. |
| **Handler stack reset in `SessionManager` (superglobals mode)** | 30min | Closes a leak that only affects legacy mode. |
| **Production opcache doc note** | 15min | Avoids a class of "stale bytecode looks like a logic bug" incidents. |

**Separately tracked (not directly from this thread):**

- Integration test isolation — rate-limiter Store table reset in `setUp`, DB fixture isolation per-class, retry tolerance on rapid sequential requests. Currently 1-3 tests rotate as flaky per run. Not a release blocker but worth a v0.3 hardening pass.
- PHP 8.4 CI flake on `test_chat_consecutive_requests_work` — confirmed environmental (Xdebug coverage instrumentation + curl timeout headroom). Fix: drop coverage on 8.4 job (only 8.3 uploads to Codecov anyway), bump curl timeout to 30s. ~5-line CI change.

---

## Lessons

1. **Public technical review is free senior code review.** henderkes' audit alone produced one security fix (v0.2.5) plus most of v0.2.6. The coroutine-isolation commenter is producing the v0.3 sprint. Treat the channel as such, not as adversarial.

2. **Iteration speed is the trust signal.** Five framework releases in 24 hours, each tagged + on Packagist + scaffolded, was the strongest argument against "AI slop" — it showed the project moves. Future critics will see this CRITIC.md as further evidence.

3. **Force-tag is a Packagist trap.** Even within seconds of the original push, force-updating a tag at GitHub does not make Packagist re-evaluate the tag content. The correct recovery is to cut a new patch tag (CLAUDE.md's release flow already says this; v0.2.8/v0.2.9 scaffold mishap was a reminder).

4. **PHPStan level isn't laziness; it's a feature surface trade-off.** Document that explicitly so the next critic can verify the trade-off rather than assume sloppiness.

5. **The discipline contract framing is honest.** "Per-coroutine isolation for framework state + worker recycling for everything else" is the trust story, not "we eliminate all leaks." Documenting that — visibly, in the migration docs — is more credible than overclaiming.

6. **Push back on the wrong critiques.** `debug_backtrace` "on hot path" wasn't true (once per worker). `apache_env` "leftover" wasn't true (actively used). The framework's not perfect, but it's also not as broken as a surface read suggests. Conceding everything erodes credibility as much as conceding nothing.

7. **Don't lead with the bridge.** The bridge is one optional feature. The framework's value extends to greenfield Swoole code (file-based routing, htmx, streaming, single-binary deploy, eight PSRs). The migration story is the closer, not the opener.

# Redis Backend Hardening Plan (H1–H10)

**Goal:** Implement every fix from the senior-eng review at `docs/architecture/2026-05-23-redis-backend-review.md`. No hacks; real scalable engineering with tests + docs. Branch: `release/v0.2.39`.

**Architecture principles for this pass:**
- **Opt-in by default.** All new behaviour preserves BC. Users opt in via constructor args, conn opts, or env vars.
- **Tests-first where the contract changes.** Every new public method gets a unit test; circuit breaker gets state-transition tests.
- **One commit per logical unit.** Reviewable, revertable, bisectable.

---

## Phase A — Quick safety wins (small LOC, big footgun-kill)

### Task H1: Throw on `mode=tracked + ttl>0` conflict

**File:** `src/Store/RedisBackend.php:42-58`

- [ ] **Step 1:** Add to `make()` after the mode validation:

```php
if ($mode === 'tracked' && isset($opts['ttl']) && (int)$opts['ttl'] > 0) {
    throw new StoreException(
        "RedisBackend::make: 'tracked' mode does not support TTL " .
        "(expired keys cannot fire SREM on the membership set, so " .
        "the tracked SET would drift). Use mode='ttl' for per-key expiry."
    );
}
```

- [ ] **Step 2:** Add unit test `tests/Unit/Store/RedisBackendMakeValidationTest.php` covering:
  - tracked + ttl=0 → ok (current default)
  - tracked + ttl=60 → throws with the explanatory message
  - ttl + ttl=60 → ok
  - ttl + ttl=0 → throws (existing behaviour, regression-proof)

- [ ] **Step 3:** `./vendor/bin/phpstan analyse --no-progress` clean.

### Task H2: `Store::getStrict()` — null-on-miss variant

**File:** `src/Store.php` after `get()`

- [ ] **Step 1:** Add the strict variant:

```php
/**
 * Strict variant of get() — returns null on miss instead of false.
 * Recommended for new code. The legacy get() keeps returning false on
 * miss for BC with code written before v0.2.39 that uses `=== false`
 * to detect misses.
 */
public static function getStrict(string $name, string $key, ?string $field = null): mixed
{
    return self::defaultBackend()->get($name, $key, $field);
}
```

- [ ] **Step 2:** Unit test `tests/Unit/StoreGetStrictTest.php` — verify null-on-miss vs false-on-miss for `get()` on Table backend.

### Task H8: `TieredBackend::existsCached()` opt-in

**File:** `src/Store/TieredBackend.php` after `exists()`

- [ ] **Step 1:** Add the L1-aware variant:

```php
/**
 * Stale-OK exists check. Returns true if L1 has a fresh entry (within
 * $l1Ttl); otherwise defers to L2. Use when "probably exists" is
 * good enough — saves a Redis round-trip in hot paths.
 *
 * The strict exists() always hits L2 (consistency); this variant
 * trades consistency for speed at the caller's request.
 */
public function existsCached(string $name, string $key): bool
{
    $l1Row = $this->l1->get($name, $key);
    if (is_array($l1Row)) {
        $cachedAt = is_numeric($l1Row[self::CACHED_AT] ?? null) ? (int) $l1Row[self::CACHED_AT] : 0;
        if ($cachedAt > 0 && (time() - $cachedAt) < $this->l1Ttl) {
            return true;
        }
    }
    return $this->l2->exists($name, $key);
}
```

- [ ] **Step 2:** Add a unit test for the L1-hit fast path + L1-miss fallback to L2.

---

## Phase B — Performance

### Task H3: Pipelined `mget`/`mset` + `UNLINK` in `clear()`

**Files:** `src/Store/RedisDriver.php`, `PhpredisDriver.php`, `PredisDriver.php`, `RedisBackend.php`

- [ ] **Step 1:** Verify `pipeline()` already on the `RedisDriver` interface — it is.
- [ ] **Step 2:** Implement `RedisBackend::mget()` via pipeline:

```php
public function mget(string $name, array $keys): array
{
    $this->assertMade($name);
    if ($keys === []) { return []; }
    $schema = $this->schemas[$name];
    return $this->pool->with(function (RedisClient $c) use ($name, $keys, $schema): array {
        $rkeys = array_map(fn(string $k): string => $this->rowKey($name, $k), $keys);
        $raws  = $c->pipeline(function (RedisClient $p) use ($rkeys): void {
            foreach ($rkeys as $rk) { $p->hgetall($rk); }
        });
        $out = [];
        foreach ($keys as $i => $k) {
            $out[$k] = $this->codec->decodeRow($schema, is_array($raws[$i] ?? null) ? $raws[$i] : []);
        }
        return $out;
    });
}
```

- [ ] **Step 3:** Pipelined `mset` — same shape; pipeline HSET + SADD + EXPIRE per row.
- [ ] **Step 4:** Add `RedisDriver::unlink(string ...$keys): int` to the interface; implement in both drivers; fall back to DEL if UNLINK unsupported.
- [ ] **Step 5:** Use UNLINK + pipelined batches of 100 in `RedisBackend::clear()`.
- [ ] **Step 6:** Add 2 unit tests (pipelined mget against mock pipeline, UNLINK against driver mock) + 1 integration test against Valkey.
- [ ] **Step 7:** Bench `tests/Performance/StoreBulkBench.php` — confirm mget(100) drops from N round-trips to 1.

---

## Phase C — Operational visibility

### Task H5: `Store::stats()` + pool/pubsub observability

**Files:** `src/Store.php`, `src/Store/RedisConnectionPool.php`, `src/Store/RedisPubSub.php`, `src/Store/RedisBackend.php`, new `src/Store/Stats.php`

- [ ] **Step 1:** Create `src/Store/Stats.php` — small value-class wrapping `array<string, int>` counters with `inc(string $key, int $n = 1): void` and `snapshot(): array`. Backed by `OpenSwoole\Atomic` for thread-safety.
- [ ] **Step 2:** Wire counters into `RedisConnectionPool`:
  - `pool_acquires_total`
  - `pool_acquire_timeouts_total`
  - `pool_clients_created_total`
- [ ] **Step 3:** Wire counters into `RedisPubSub`:
  - `pubsub_reconnects_total`
  - `pubsub_messages_dispatched_total`
  - `pubsub_handler_errors_total`
- [ ] **Step 4:** Expose via `Store::stats(): array<string, int>` returning a merged snapshot.
- [ ] **Step 5:** Unit test stats counters increment correctly across a synthetic op sequence.

### Task H9: Log `PhpredisDriver::close()` exceptions at debug

**File:** `src/Store/PhpredisDriver.php:224-227`

- [ ] **Step 1:** Replace:
  ```php
  try { $this->c->close(); } catch (\Throwable $e) { /* tolerant */ }
  ```
  with:
  ```php
  try { $this->c->close(); }
  catch (\Throwable $e) {
      if (function_exists('elog')) {
          elog('PhpredisDriver::close: ' . $e->getMessage(), 'debug');
      }
  }
  ```

---

## Phase D — Resilience

### Task H4: `CircuitBreakerBackend` decorator (opt-in)

**Files:** new `src/Store/CircuitBreakerBackend.php`, `src/Store.php`

This is the substantive design piece.

**Design:**
- Decorator implementing `StoreBackend`; wraps a primary backend + optional fallback backend.
- 3-state: `closed` (normal), `open` (fail-fast for N seconds), `half-open` (one trial call to test recovery).
- Threshold-based: opens after `$failureThreshold` consecutive failures within `$failureWindowSec`.
- Cooldown: `$openDurationSec` before going half-open.
- Per-OP categorization: reads can fall back to `TableBackend` (returning stale or empty); writes throw (no fallback semantically — a fallback Table write would diverge).
- Configurable per-table via `Store::make($name, ..., ['on_error' => 'fallback_table' | 'throw'])`.

**Public API:**
```php
new CircuitBreakerBackend(
    StoreBackend $primary,
    ?StoreBackend $fallback = null,
    int $failureThreshold = 5,
    int $failureWindowSec = 10,
    int $openDurationSec = 30,
);
```

- [ ] **Step 1:** Write the breaker state machine — `closed/open/half-open` transitions with `OpenSwoole\Atomic` for cross-coroutine visibility.
- [ ] **Step 2:** Wrap every interface method with the breaker check + fallback decision tree:
  - read methods (get/exists/count/iterate/mget) — fall back if `fallback` is set
  - write methods (set/del/incr/decr/mset) — always throw
  - control methods (make/clear) — propagate to primary; fallback also gets the schema (so reads work)
- [ ] **Step 3:** Wire `'on_error' => 'fallback_table'` opt into `Store::make()`:
  - if set + backend is Redis → wrap with `CircuitBreakerBackend` using a Table fallback.
- [ ] **Step 4:** Unit tests:
  - closed state passes through
  - N failures opens the breaker; subsequent reads use fallback
  - after `openDurationSec` a probe is sent in half-open; success closes, failure re-opens
  - writes never fall back (always throw when open)
- [ ] **Step 5:** Document the per-table opt-in pattern in `template/pages/store.php`.

### Task H6: Boot-time `Store::ping()` advisory in `App::run()`

**Files:** `src/App.php`, `src/Store.php`

- [ ] **Step 1:** In `App::run()` AFTER the backend is resolved AND before worker fork:
  ```php
  if (self::$store_backend_kind === 'redis') {
      try {
          if (!Store::ping()) {
              elog('Store: Redis backend ping returned false at boot — workers may fail on first request', 'warn');
          }
      } catch (\Throwable $e) {
          elog('Store: Redis backend ping FAILED at boot: ' . $e->getMessage(), 'warn');
      }
  }
  ```
- [ ] **Step 2:** Unit test the message format (mock the backend to throw, capture elog).

### Task H7: HOOK_ALL boot-time assertion for phpredis subscribers

**Files:** `src/App.php`, `src/Store/RedisPubSub.php`

- [ ] **Step 1:** Surface "what driver did we resolve to?" from `RedisConnectionPool` (cheaply — just inspect `prefer` + `extension_loaded('redis')`).
- [ ] **Step 2:** In `App::run()` BEFORE worker fork: if backend=redis AND resolved-driver=phpredis AND any `onPubSub` handler registered AND `hookAll()` resolves to 0 → log loud warning:
  ```
  Store: phpredis subscribe loop will BLOCK without HOOK_ALL. Either:
    - Re-enable HOOK_ALL (default in coroutine mode), OR
    - Force ZEALPHP_REDIS_PREFER=predis for the subscriber path
  ```
- [ ] **Step 3:** Unit test the warning fires with the right combo + doesn't fire with safe combos.

### Task H10: `RedisPubSub` configurable max-retry

**File:** `src/Store/RedisPubSub.php`

- [ ] **Step 1:** Add constructor param `int $maxAttempts = 0` (0 = unlimited, preserving current behaviour).
- [ ] **Step 2:** In `runner()`, after the backoff sleep, if `$maxAttempts > 0 && $attempt >= $maxAttempts`, log final `error_log` and `return`.
- [ ] **Step 3:** Unit test the give-up path with a small max (e.g. 3 attempts).

---

## Phase E — Documentation

### Task E15: User-facing "Production hardening" section in `template/pages/store.php`

Add a new H2 section with subsections:
- **Boot-time health checks** — what the ping advisory tells you, how to debug
- **Circuit breaker (opt-in)** — `['on_error' => 'fallback_table']` recipe + state-machine diagram
- **Observability** — `Store::stats()` reference, exposed counters
- **Driver choice nuance** — HOOK_ALL + phpredis interaction, when the boot assertion fires
- **Tracked vs TTL modes** — when each is appropriate, the H1 throw explanation
- **`getStrict()` vs `get()`** — null vs false; recommended for new code

### Task E16: Internal docs

- [ ] `docs/architecture/2026-05-23-redis-backend-review.md` (this commit) — already written
- [ ] `.claude/CLAUDE.md` — short pointer to the hardening pass in the Store/Counter section

### Task E17: CHANGELOG

- [ ] Add `[0.2.41]` section listing each H-item with a 1-line summary + reference to the architecture doc.

---

## Execution sequence

Commits in this order (each independently green):

1. `docs(arch): redis backend senior-eng review + hardening plan`  (commits the two docs files above)
2. `store(H1): throw on mode=tracked + ttl>0`
3. `store(H2): add Store::getStrict() — null-on-miss variant`
4. `store(H8): add TieredBackend::existsCached() opt-in fast path`
5. `store(H3): pipelined mget/mset + UNLINK in clear()`
6. `store(H9): log PhpredisDriver::close() exceptions at debug`
7. `store(H10): configurable max-retry in RedisPubSub`
8. `store(H5): Store::stats() + pool/pubsub observability`
9. `store(H6): boot-time Redis ping advisory`
10. `store(H7): HOOK_ALL boot-assertion for phpredis subscribers`
11. `store(H4): CircuitBreakerBackend decorator (opt-in fallback)`
12. `docs(store): production hardening section`
13. `docs(claude): summarize the v0.2.41 hardening pass`

## Verification

After every commit:
- `./vendor/bin/phpunit --testdox` — green
- `./vendor/bin/phpstan analyse --no-progress` — clean
- `php app.php restart && curl -fs http://127.0.0.1:8080/store >/dev/null` — site boots

At end of pass:
- Integration: `ZEALPHP_STORE_BACKEND=redis ZEALPHP_REDIS_URL=redis://127.0.0.1:6379 ./vendor/bin/phpunit tests/Integration/Store* --testdox` — green
- Visual: `/store` portfolio page renders the new Production Hardening section

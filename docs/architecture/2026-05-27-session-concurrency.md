# Session Concurrency Safety — File Locking + Redis Optimistic Locking

> Shipped 2026-05-27. Covers both session backends.

## The Problem

Two coroutines on the same OpenSwoole worker handling requests for the same
session (same PHPSESSID cookie) can silently clobber each other's writes:

```
Coroutine A: session_start() → reads {counter: 1}
Coroutine B: session_start() → reads {counter: 1}
Coroutine A: $_SESSION['counter'] = 2 → write_close → writes {counter: 2}
Coroutine B: $_SESSION['counter'] = 2 → write_close → writes {counter: 2}
Expected: counter = 3 (A increments to 2, B increments to 3)
Actual:   counter = 2 (B overwrites A's write)
```

Apache avoids this with `flock()` on the session file. PHP-FPM serializes
sessions per request via `mod_files.c` file locking. ZealPHP handles
requests concurrently — it needs its own locking.

## Solution: Two Backends, Two Strategies

### File Handler — Pessimistic Locking

```
READ:  flock(LOCK_SH) → read → unlock
       Shared lock prevents reading a partially-written file.

WRITE: flock(LOCK_EX) → re-read → merge → write → unlock
       Exclusive lock serializes the read-merge-write cycle.
       Re-reads under the lock because another coroutine may have
       written since our session_start().
```

The merge uses `array_merge($disk, $current)` — current request's keys
overwrite disk. Keys that were loaded at session_start but later `unset()`
are honored via `session_loaded_keys` (not resurrected from disk).

Lock held briefly: encode + write + flush. Negligible performance impact.

### Redis Handler — Optimistic Locking (WATCH/MULTI/EXEC)

```
READ:  WATCH key → GET → return data
       WATCH tells Redis to track modifications to this key.

WRITE: MULTI → SETEX → EXEC
       EXEC returns false if the key was modified since WATCH.
       On failure: retry loop (max 3 attempts).
```

The retry loop in `zeal_session_write_close()`:

```php
for ($attempt = 0; $attempt < 3; $attempt++) {
    $existing = $handler->read($sid);    // WATCH + GET
    $merged = array_merge($disk, $current);
    // ... honor deletions via session_loaded_keys ...
    $written = $handler->write($sid, $merged);  // MULTI + SETEX + EXEC
    if ($written !== false) break;       // success
    // WATCH conflict — another coroutine wrote; retry with fresh read
}
```

On exhaustion (3 failures): falls through to the last write attempt's
result. This is last-writer-wins — same as the pre-locking behavior.
Safe degradation, not data loss.

### Per-Coroutine Connection Isolation

`RedisSessionHandler` uses one Redis connection per coroutine (stored in
`Coroutine::getContext()`). WATCH state is per-connection, so two
coroutines' WATCH/MULTI cycles never interfere with each other.

## What This Handles

| Scenario | File | Redis |
|----------|------|-------|
| Two coroutines writing different keys | Merge preserves both | WATCH detects, retry merges |
| Two coroutines writing same key | Last-writer-wins (under lock) | Last-writer-wins (after retry) |
| Partial write visibility | flock prevents | MULTI atomic |
| Coroutine crash mid-write | flock auto-releases on fd close | WATCH auto-expires |

## What This Does NOT Handle

- **Nested array conflicts**: `$_SESSION['cart'][] = 'item-1'` vs
  `$_SESSION['cart'][] = 'item-2'` — the merge is top-level only.
  Both coroutines replace the entire `cart` key. For sub-key merging,
  use Redis hashes or a database directly.

- **Cross-node locking**: Two ZealPHP nodes behind a load balancer
  writing the same session. Redis WATCH handles this naturally (Redis
  is the single source of truth). File-based sessions need sticky
  sessions at the LB layer.

## Configuration

No configuration needed — locking is automatic for both backends.

```php
// File sessions (default): flock automatic
$app->run();

// Redis sessions: WATCH/MULTI automatic
$handler = new RedisSessionHandler('127.0.0.1', 6379);
session_set_save_handler($handler, true);
$app->run();

// Store-backed sessions (Redis backend): uses Store's own write path
StoreSessionHandler::register(1440);
$app->run();
```

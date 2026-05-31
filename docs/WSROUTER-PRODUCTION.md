# WSRouter — Production Hardening Guide

Everything you need to know before pointing `WSRouter` at real traffic.

This guide is paired with the framework code that was hardened across
commits `dbc912c` (capacity exceptions + ServerRegistry GC + graceful sweep)
and `b4a101e` (backpressure + stats + per-room rate limit). What's
automatic, what you need to configure, what stays your responsibility.

---

## 1. Capacity sizing

The two `OpenSwoole\Table` segments WSRouter allocates have **demo-grade
defaults** that you MUST size up for production:

| Table | Default | What it caps |
|---|---|---|
| `ws_owner` | 4 096 rows | concurrent connections (cluster-wide) |
| `ws_room_members` | 16 384 rows | (room × member) pairs (cluster-wide) |

When full, `Store::set()` previously returned `false` silently and the
join was lost. **Now it throws `ZealPHP\WS\CapacityException`** (a typed
subclass of `StoreException`).

### Recipe

```php
WSRouter::initOptions(
    ownerCapacity:       200_000,   // 200k concurrent connections
    roomMembersCapacity: 1_000_000, // 1M (room × member) pairs
    slowConsumerBytes:   8 * 1024 * 1024,  // 8 MB per-fd send queue cap
);
WSRouter::init();
```

### Sizing math

```
RAM ≈ maxRows × (4 × Σ column_bytes + ~32 B/row overhead)
```

For `ws_owner` (columns: `server_id STRING(192)` + `conn_id STRING(32)` = 224 bytes nominal;
`client_id` is the row **key**, not a column):
- 1k rows → ~930 KB
- 100k rows → ~93 MB
- 1M rows → ~930 MB

For `ws_room_members` (columns: `room STRING(64)` + `client_id STRING(128)` + `server_id STRING(192)`
+ `joined_at INT(8)` = 392 bytes nominal; keyed by `{room}:{client_id}`):
- 100k rows → ~160 MB
- 1M rows → ~1.6 GB

These segments are allocated **at master fork** — the RAM is committed up
front, not lazy. Size against peak, not average.

### Handling CapacityException

```php
$app->ws('/chat',
    onOpen: function ($server, $request) {
        try {
            WSRouter::own($request->get['user'] ?? '', $request->fd);
        } catch (\ZealPHP\WS\CapacityException $e) {
            // WSRouter::CLOSE_CAPACITY (4013) — semantic capacity close code.
            // 1013 (CLOSE_TRY_AGAIN_LATER) is also valid as the RFC hint.
            $server->disconnect($request->fd, WSRouter::CLOSE_CAPACITY, 'server at capacity');
            return;
        }
    },
);
```

---

## 2. Stale-row cleanup — automatic

Cleanup happens on **two paths**:

| Path | When | What it does |
|---|---|---|
| `App::onWorkerStop` sweep | Graceful shutdown / reload | Worker 0 drops all `ws_owner` + `ws_room_members` rows owned by this server, plus its own `ws_servers` row |
| `App::tick(60_000)` GC | Periodic, automatic | Worker 0 scans `ws_servers`; rows older than 90 s (no heartbeat for >2× the 30 s interval) are reaped along with their dependent `ws_owner` + `ws_room_members` rows |

**Hard-crash recovery:** the framework writes a heartbeat row to
`ws_servers` every 30 s. If a node kernel-panics, its row goes stale; the
next GC tick reaps everything it owned. Cleanup is **eventually
consistent within 90–150 s** of a crash.

Nothing for you to configure. Available for inspection:

```php
// Manually trigger a GC sweep (e.g. from an admin endpoint)
$reaped = \ZealPHP\WSRouter::runStaleServerGC();
// Returns count of rows reaped.
```

---

## 3. Heartbeat — server-side TCP health checks

The framework's stale-server GC handles server-level recovery. For
**individual dead connections** (laptop closed, network dropped without a
close frame), use OpenSwoole's built-in heartbeat:

```php
$app->run([
    'heartbeat_check_interval' => 30,  // check every 30 s
    'heartbeat_idle_time'      => 90,  // disconnect after 90 s idle
]);
```

OpenSwoole sweeps idle fds and closes them; your `onClose` handler fires
naturally, releasing local state. No application-level ping/pong needed
unless you want app-level liveness signals.

If you also want **client → server pings** (to detect application-level
hangs even when TCP says the connection is alive), the client sends a
WebSocket ping frame every 25 s; the server responds with pong
automatically. No app code needed — protocol-level.

---

## 4. Authentication — how to wire PHP session auth into WebSockets

WebSocket upgrade requests are HTTP requests; they carry cookies. Read
the session cookie in `onOpen`, resolve to the authenticated user, and
use that as the trusted identity throughout the connection's lifetime.

### Pattern: bridge HTTP session → WS identity

```php
$app->ws('/chat',
    onOpen: function ($server, $request) {
        // 1. Read session cookie
        $sid = $request->cookie['PHPSESSID'] ?? '';
        if ($sid === '') {
            $server->disconnect($request->fd, WSRouter::CLOSE_AUTH_REQUIRED, 'unauthenticated');
            return;
        }

        // 2. Resolve to user via your session backend
        session_id($sid);
        session_start();
        $user = $_SESSION['user'] ?? null;
        if (!$user) {
            $server->disconnect($request->fd, WSRouter::CLOSE_AUTH_INVALID, 'session expired');
            return;
        }

        // 3. Bind the AUTHENTICATED identity to this fd — IGNORE whatever
        //    the client sends in subsequent frames. This is the trust boundary.
        try {
            WSRouter::own($user['username'], $request->fd);
        } catch (\ZealPHP\WS\CapacityException) {
            $server->disconnect($request->fd, WSRouter::CLOSE_CAPACITY, 'server at capacity');
            return;
        }
    },

    onMessage: function ($server, $frame) {
        // 4. Look up identity from the SERVER-SIDE map — never trust the frame
        $local = WSRouter::localFds();
        $username = null;
        foreach ($local as $cid => $row) {
            if ($row['fd'] === $frame->fd) { $username = $cid; break; }
        }
        if ($username === null) {
            $server->disconnect($frame->fd, WSRouter::CLOSE_AUTH_REQUIRED, 'fd not bound');
            return;
        }
        // Now safe to use $username as the trusted sender identity
    },
);
```

### Why not just trust the frame's username?

The widget JS sends `{"type":"join", "username":"alice"}`. **A malicious
client can send `username: "bob"` and impersonate Bob.** The framework
intentionally doesn't enforce identity — it leaves auth to you. The
pattern above pins identity to the WS upgrade's session cookie, which is
HMAC-signed and tamper-resistant.

### Close codes

WSRouter ships named constants for all semantic codes. Use them instead
of hardcoded integers so client code can react specifically:

| Constant | Code | Meaning |
|---|---|---|
| `WSRouter::CLOSE_NORMAL` | 1000 | Clean close |
| `WSRouter::CLOSE_GOING_AWAY` | 1001 | Server going down / page navigating away |
| `WSRouter::CLOSE_PROTOCOL_ERROR` | 1002 | Malformed frame |
| `WSRouter::CLOSE_POLICY_VIOLATION` | 1008 | Generic policy violation (RFC 6455) |
| `WSRouter::CLOSE_MESSAGE_TOO_BIG` | 1009 | Message too large |
| `WSRouter::CLOSE_INTERNAL_ERROR` | 1011 | Server internal error |
| `WSRouter::CLOSE_TRY_AGAIN_LATER` | 1013 | Server temporarily overloaded — retry (RFC 6455) |
| `WSRouter::CLOSE_AUTH_REQUIRED` | 4001 | Not authenticated — client should log in |
| `WSRouter::CLOSE_AUTH_INVALID` | 4002 | Bad token / expired session |
| `WSRouter::CLOSE_FORBIDDEN` | 4003 | Authenticated but not permitted for this room |
| `WSRouter::CLOSE_CAPACITY` | 4013 | Server at capacity (paired with CapacityException) |
| `WSRouter::CLOSE_RATE_LIMITED` | 4029 | Client exceeded per-client rate limit (WS-4) |
| `WSRouter::CLOSE_IDLE` | 4040 | Connection idle / heartbeat missed |

Example:

```php
$server->disconnect($request->fd, WSRouter::CLOSE_AUTH_REQUIRED, 'unauthenticated');
$server->disconnect($request->fd, WSRouter::CLOSE_FORBIDDEN,     'not in room');
$server->disconnect($request->fd, WSRouter::CLOSE_CAPACITY,      'server at capacity');
```

---

## 5. Reconnect — client-side responsibility

The framework provides NO automatic rejoin. If a client's WS drops + the
JS reconnects, it must re-send any join frames + replay state.

### Pattern: client-side reconnect with re-join

```js
class ChatClient {
  constructor(url, room, user) {
    this.url = url;
    this.room = room;
    this.user = user;
    this.attempts = 0;
    this.connect();
  }

  connect() {
    this.ws = new WebSocket(this.url);
    this.ws.onopen = () => {
      this.attempts = 0;
      // Re-join immediately on every (re)connect.
      this.ws.send(JSON.stringify({ type: 'join', room: this.room, username: this.user }));
    };
    this.ws.onmessage = (ev) => { /* handle message */ };
    this.ws.onclose = (ev) => {
      // Don't retry on auth failures (close codes 4001/4003 are app-level)
      if (ev.code === 4001 || ev.code === 4003) { return; }
      const backoff = Math.min(1000 * 2 ** this.attempts, 30_000);   // exp backoff, max 30 s
      this.attempts++;
      setTimeout(() => this.connect(), backoff);
    };
    this.ws.onerror = () => { /* logged in onclose */ };
  }

  send(body) { this.ws.send(JSON.stringify({ type: 'message', body })); }
}
```

### History replay

If your handler sends a "history" frame on join (as the chatroom lesson
does), reconnect automatically gets the latest N messages — clients
catch up. Pair with SQLite-backed message history (the Lesson 22
pattern); no server-side state needs to survive the disconnect.

For at-least-once delivery semantics during a reconnect window, switch
to `Store::publishReliable` (Redis Streams) — the consumer-group machinery
handles missed messages via XPENDING + XAUTOCLAIM.

---

## 6. Backpressure — automatic

Each push runs in its own coroutine + checks `getClientInfo()['send_queue_bytes']`
before sending. If the per-fd send queue exceeds `slowConsumerBytes` (default
4 MB), the message is **dropped** for that fd and counted in
`stats()['pushes_dropped_slow_consumer']`.

Why drop instead of disconnect: a temporarily slow client (mobile on
3G) recovers. Disconnecting them means full reconnect + re-join + history
replay. Dropping a few frames preserves the connection.

If you want disconnect-instead-of-drop semantics (e.g. for trading
systems where lost frames are intolerable), check `send_queue_bytes`
yourself and call `$server->disconnect($fd, WSRouter::CLOSE_TRY_AGAIN_LATER, 'slow consumer')`.

### Tuning

```php
WSRouter::initOptions(slowConsumerBytes: 16 * 1024 * 1024);  // 16 MB before drop
```

Bigger = more lenient (mobile clients on flaky networks); smaller =
faster drop of stuck consumers.

---

## 7. Metrics — `WSRouter::stats()`

```php
$app->route('/healthz/ws', fn() => WSRouter::stats()->snapshot());
```

Counters (per-worker, accumulate since boot):

| Counter | What |
|---|---|
| `owns_total` | successful `WSRouter::own()` calls |
| `releases_total` | successful `WSRouter::release()` calls |
| `sendToClient_total` | published cross-server sends |
| `sendToClient_owner_missing` | sends where the client wasn't connected anywhere |
| `room_joins_total` | `$room->join()` calls |
| `room_leaves_total` | `$room->leave()` calls |
| `room_pushes_total` | `$room->push()` calls accepted |
| `pushes_total` | actual fd-level `$server->push()` calls that succeeded |
| `pushes_failed_total` | `$server->push()` returned false (rare) |
| `pushes_dropped_slow_consumer` | dropped due to send-queue cap |
| `pushes_to_dead_fd_total` | push attempted on an fd that wasn't `isEstablished` |
| `capacity_exceeded_owner_total` | `CapacityException` thrown on own() |
| `capacity_exceeded_room_total` | `CapacityException` thrown on join() |
| `rate_limit_drops_total` | pushes dropped by the per-room rate limit |
| `client_rate_limit_drops_total` | pushes dropped by the per-client rate limit (WS-4) |
| `hmac_verify_failures_total` | pub/sub messages rejected due to bad/missing HMAC (WS-3) |

For cluster-wide metrics, scrape each server's `/healthz/ws` and sum.

---

## 8. Rate limiting — per room and per client

### Per-room cap

```php
// 100 pushes / 60 s / room
WSRouter::setRoomRateLimit(100, 60);

// Disable
WSRouter::setRoomRateLimit(0);
```

Sliding-window via `Counter::increment` keyed by `room:bucket-id`. Old
buckets age out naturally (no explicit deletion). Over-rate pushes
silently drop + count in `rate_limit_drops_total`.

### Per-client cap (WS-4)

Per-client rate limiting is a first-class API — no hand-rolling needed:

```php
// 50 messages / 10 s / client
// (applies to sendToClient always, and to Room::push only when a
//  $fromClientId is attributed; server-originated broadcasts are not
//  per-client-capped)
WSRouter::setClientRateLimit(50, 10);
```

When a client exceeds the limit, the push is dropped and counted in
`client_rate_limit_drops_total`. If you want to disconnect the client
instead of silently dropping, check the stat in your `onMessage` handler
and call `$server->disconnect($frame->fd, WSRouter::CLOSE_RATE_LIMITED, 'rate limited')`
(close code `4029`).

```php
// Disable
WSRouter::setClientRateLimit(0);
```

---

## 9. Ordering — what's guaranteed, what's not

| Guarantee | Status |
|---|---|
| `$server->push()` to the same fd preserves order | **YES** — OpenSwoole serializes per-fd send queue |
| Pub/sub delivery preserves publisher's order on the channel | YES — Redis guarantees |
| Cross-WORKER ordering of pushes from the same publish event | **NO** — workers receive subscribers in parallel; their pushes can interleave at the receiver |
| Cross-PUBLISH ordering of rapid-fire messages | **NO** — Redis pub/sub delivers in publish order BUT each worker schedules independently |

### Mitigation: include a timestamp / seq in every message

```php
$room->push([
    'type' => 'message',
    'body' => 'hello',
    'ts'   => microtime(true),   // high-resolution timestamp
]);
```

Client reorders by `ts` before display. Two messages within a few µs
are effectively concurrent; users won't notice if displayed in
arrival-order. For trading / commit-log workloads where strict total
ordering matters, use `Store::publishReliable` (Redis Streams) — Streams
have a global monotonic message id (`XADD` returns it).

---

## 10. Trust model

WSRouter assumes the **Redis network is trusted** — anyone who can
PUBLISH on `ws:room:*` or write to `ws_owner` can spoof messages /
membership. Same threat model as `TieredBackend` invalidation (C2 in the
hardening pass).

If your Redis is NOT in a trusted network (e.g. shared Redis-as-a-service
across tenants), enable the **built-in channel HMAC** (WS-3):

```php
// Set once at boot, before App::run().
WSRouter::setChannelHmacSecret(getenv('ZEALPHP_WS_HMAC') ?: null);
```

When a secret is set, every `sendToClient()` and `Room::push()` publish
wraps the payload in a signed `{"v":1,"hmac":"...","payload":"..."}` envelope.
The receiving worker verifies the HMAC before delivering the message; bad or
missing signatures are silently dropped and counted in
`hmac_verify_failures_total` (visible via `WSRouter::stats()`). Without a
secret the channel is unauthenticated (trusted-network default).

---

## 11. Production checklist

Before pointing real traffic at WSRouter:

- [ ] `WSRouter::initOptions(ownerCapacity, roomMembersCapacity, slowConsumerBytes)` sized for peak
- [ ] `App::run(['heartbeat_check_interval' => 30, 'heartbeat_idle_time' => 90])`
- [ ] `onOpen` reads session cookie → resolves identity → `WSRouter::own($trustedId, $fd)`
- [ ] `onMessage` looks up identity from `WSRouter::localFds()`, never trusts frame
- [ ] Client-side reconnect logic with exponential backoff + 4001/4003 stop conditions
- [ ] `WSRouter::setRoomRateLimit($n, $window)` for per-room spam protection
- [ ] `WSRouter::setClientRateLimit($n, $window)` for per-client spam protection (WS-4)
- [ ] Expose `/healthz/ws` returning `WSRouter::stats()->snapshot()`
- [ ] `Store::defaultBackend(Store::BACKEND_REDIS)` if you want cross-server federation
- [ ] If using Redis: capacity for `ws_owner` / `ws_room_members` keys; monitor Redis memory
- [ ] If using Table backend: capacity sized against `OpenSwoole\Table` RAM budget at master fork
- [ ] Document close codes your app uses — use `WSRouter::CLOSE_*` constants (4001 unauth, 4002 bad token, 4003 forbidden, 4013 capacity, 4029 rate-limited, 4040 idle)
- [ ] Load-test at expected peak — connections × broadcasts/sec × room sizes
- [ ] Decide ordering strategy — `ts` field + client reorder OR `publishReliable` Streams
- [ ] Trust model — is your Redis network trusted? If not, set `WSRouter::setChannelHmacSecret(getenv('ZEALPHP_WS_HMAC') ?: null)` (WS-3)
- [ ] Sweep test: kill -9 a server; verify the GC reaps its rows within 150 s

The first 8 items are configuration / wiring — under 50 LOC total. The
last 5 are operational tests + decisions.

---

## What WSRouter does NOT do (you own these)

| | |
|---|---|
| Identity / auth | You read session/cookie in `onOpen` |
| ACL on join | You check "can alice join chat:42?" before `$room->join()` |
| Message persistence | Use SQLite (Lesson 22 pattern) or your DB |
| Cross-region federation | Redis pub/sub is in-cluster; for multi-region run a Redis replica per region + dedicated pub/sub bus |
| At-least-once delivery | Use `Store::publishReliable` (Streams) instead of `Store::publish` |
| Client SDK | Ship your own; the JS in `_chatroom_widget.php` is the reference shape |

The framework gives you the **transport + cluster-wide state + recovery
+ observability**. The semantics on top are yours.

# Store/Counter Pub/Sub — Public API Design

**Status:** Approved — handler dispatch concurrent (go() per message), reliable variant via Redis Streams INCLUDED, ships in release/v0.2.39
**Date:** 2026-05-23
**Spike validation:** `docs/superpowers/specs/2026-05-23-phase3-pubsub-spike-result.md` (in-process, cross-process, cross-host all PASS; ~0.5 ms loopback delivery)

## Goal

Expose `Store::publish($channel, $payload): int` and `App::onPubSub($channel, callable)` as first-class public API on top of the validated Redis pub/sub machinery. One primitive, multiple consumers:

1. **Cross-server WebSocket routing** — store `client_id → server_id`; PUBLISH to owner; owner's subscriber does `$server->push($localFd, ...)`.
2. **Tiered backend L1 invalidation** (when Phase 2 lands) — publish-on-write to invalidate peer-node caches.
3. **Custom application events** — any cross-process notification users want.

The primitive is identical for all three. Phase 3 L1 invalidation becomes a thin layer on top of the same primitive, not a parallel mechanism.

## Out of scope (Phase 4+)

- Higher-level `App::wsBroadcast($clientId, $payload)` / `App::wsRoom(...)->broadcast(...)` helpers — separable; build on top of this primitive in a later release if useful.
- Authenticated subscribers, ACL, per-channel permissions — out of scope for v0.x.
- Cross-language interop docs — the wire protocol IS just Redis pub/sub, so any client (redis-py, ioredis, hiredis) can publish/subscribe naturally. Worth one paragraph in the learn page.
- Auto-claim from dead consumers (Streams `XAUTOCLAIM`) — Phase 2 of the reliable path. Phase 1 leaves pending messages pending until a consumer comes back; documented.

## API

```php
// Publish — returns the number of receivers Redis delivered to.
// Works from any context (request handler, coroutine, timer, sync).
// Routes through RedisConnectionPool so each op uses a private socket.
Store::publish(string $channel, string $payload): int;

// Subscribe — registers a handler that fires for every message on $channel.
// MUST be called BEFORE App::run() — typically in app.php or a boot file.
// Multiple handlers per channel are allowed; all fire in registration order.
// Pattern channels (Redis PSUBSCRIBE syntax: 'ws:server:*') accepted —
// the * matches segments between : separators.
App::onPubSub(string $channelOrPattern, callable $handler): void;

// Handler signature:
//   function (string $payload, string $channel, ?string $pattern = null): void
// - $payload: raw bytes from the PUBLISH
// - $channel: exact channel that received the message
// - $pattern: the pattern matched (or null for exact subscribes)
```

`Store::publish` uses the same `RedisConnectionPool` as the rest of Store. The publisher path is one PUBLISH per call — no special connection management. PUBLISH is one-shot; pool clients are returned immediately.

`App::onPubSub` registers the handler on the static `$pubsubRegistry`. At `App::run()` time, if any handlers are registered, the framework wires a subscriber lifecycle into `App::onWorkerStart`.

## Subscriber lifecycle (per worker)

```
app.php          : App::onPubSub('ws:server:A', $handler)    [adds to registry]
                 : App::onPubSub('chat:*',      $otherHandler)
                 : App::run()

worker fork → onWorkerStart hook fires:
    spawn a dedicated coroutine "pubsub.runner":
        build a dedicated RedisClient (separate from pool — SUBSCRIBE monopolises)
        partition registry: exact channels vs patterns
        SUBSCRIBE all exact channels in one call
        PSUBSCRIBE all patterns in one call
        loop:
            read next frame from pubSubLoop
            switch on frame.kind:
                'message'  → dispatch to handlers whose channel matches frame.channel
                'pmessage' → dispatch to handlers whose pattern matches frame.pattern
                'subscribe' / 'psubscribe' → log @ debug
                connection drop → backoff + reconnect + re-subscribe everything

worker stop → onWorkerStop hook fires:
    runner cor receives a "stop" signal (via Coroutine\Channel)
    unsubscribe; close connection; exit
```

### Handler dispatch — concurrent vs sequential

Each received message dispatches handlers in a **fresh `go()` coroutine**. Two reasons:

1. A slow handler (e.g. fanning out to N WS clients) MUST NOT block the next message from being read.
2. Two PUBLISHes on different channels arrive sequentially via TCP; with `go()` per dispatch they execute concurrently — sub-millisecond gap stays sub-millisecond.

If users need ordering guarantees per channel, they implement it in user code with a Channel queue. The framework doesn't impose it.

### Reconnect policy

Connection drop → close current client → wait `min(0.1 * 2^N, 5.0)` seconds → reconnect → re-SUBSCRIBE / re-PSUBSCRIBE everything → resume. Logs the drop + recovery via `elog`. Bounded exponential backoff caps at 5 s; never gives up (a long-running server should self-heal).

A message published DURING the reconnect window via `Store::publish()` is lost — Redis pub/sub has no buffering. For at-least-once delivery use `Store::publishReliable()` (Streams-backed; see below).

## Reliable variant — Redis Streams

The fire-and-forget primitive (`Store::publish` / `App::onPubSub`) is the right tool for "broadcast NOW, drops are acceptable" use cases: WS routing, cache invalidation, presence beats. For "every consumer must process every message exactly once even across restarts and disconnects," ZealPHP ships a parallel primitive on top of Redis Streams.

### API

```php
// Append a message to a Redis stream. Returns the Redis-generated message ID
// (e.g. "1716438923456-0"). Durable: survives Redis restart if AOF/RDB enabled.
Store::publishReliable(string $stream, string $payload): string;

// Register a handler with a consumer group. The framework spawns a per-worker
// consumer in this group; messages are distributed round-robin across all
// consumers in the same group (across all servers + workers).
//
// Default group name: 'zealphp' + sha1 of the app's canonical name (so all
// servers in a cluster share one group). Override with $groupName.
//
// Handler must return true to ACK (message removed from pending list), false
// to NACK (left pending; another consumer or this one's reconnect can re-deliver).
App::onReliableMessage(
    string $stream,
    callable $handler,           // function(string $payload, string $messageId, string $stream): bool
    ?string $groupName = null,
    int $blockMs = 1000,         // XREADGROUP BLOCK timeout
    int $batchSize = 16,         // COUNT per fetch
): void;
```

### Subscriber lifecycle

```
worker fork → onWorkerStart hook:
    if any onReliableMessage handlers registered:
        spawn dedicated coroutine "streams.runner":
            for each (stream, group): XGROUP CREATE $stream $group $ '$' MKSTREAM (idempotent)
            consumer name: '{$workerHash}-{$pid}'  [stable across reconnect]
            loop:
                XREADGROUP GROUP $group $consumer COUNT $batch BLOCK $blockMs STREAMS $stream >
                for each entry:
                    go(function() use ($entry, $handler, $stream, $group) {
                        try {
                            $ok = $handler($entry->payload, $entry->id, $stream);
                            if ($ok) XACK $stream $group $entry->id
                        } catch (\Throwable $e) {
                            elog("reliable handler threw: $e", "error");
                            // No XACK → stays pending → eventually retried.
                        }
                    });
```

`XAUTOCLAIM` (Phase 2) would steal stale pending messages from dead consumers. Phase 1 leaves them pending; on operator action (`XPENDING` inspection + manual `XCLAIM`) they can be moved to a fresh consumer. For most apps the worker recycle gives the same consumer name back so they auto-resume.

### Trade-offs (documented in CLAUDE.md + the learn page)

| Property | `Store::publish` (pub/sub) | `Store::publishReliable` (Streams) |
|---|---|---|
| Latency | ~0.5 ms loopback | ~1–2 ms (XADD + XREADGROUP roundtrips) |
| Throughput | very high (one PUBLISH = one PUBLISH) | high but heavier per message (write + ack) |
| Durability | none (fire-and-forget) | AOF/RDB-backed |
| Delivery | best-effort, no buffering during reconnects | at-least-once via consumer groups |
| Memory in Redis | none (transient) | grows with backlog; trim with `XADD ~ MAXLEN` |
| Fan-out (N subscribers all get the message) | yes, native | use N consumer groups, one per receiver class |
| Round-robin within a group | n/a | yes, native |

Pick `publish` for cache invalidation + WS routing (drops are OK; speed matters). Pick `publishReliable` for command/event sourcing (drops are not OK).

## Multiple handlers per channel

```php
App::onPubSub('events', $logToFile);
App::onPubSub('events', $emitMetric);
App::onPubSub('events', $pushToWebhook);
```

All three fire per message, in registration order, each in its own `go()`. Failure in one (uncaught throw) is caught and logged via `elog`, the other two still fire. Matches Node.js EventEmitter semantics.

## Use case shape — cross-server WS routing

```php
// app.php — once at boot:
App::onPubSub("ws:server:{$myServerId}", function (string $payload) use ($server) {
    $msg = json_decode($payload, true);
    $localFd = $localFdMap->get($msg['client_id']);
    if ($localFd !== null) {
        $server->push($localFd, $msg['data']);
    }
});

// Anywhere — message a client by id:
$owner = Store::get('client_locations', $clientId, 'server');
Store::publish("ws:server:$owner", json_encode([
    'client_id' => $clientId,
    'data'      => $payload,
]));
```

This is the exact pattern the spike validated end-to-end (committed at `52c3cac` and `257f7e4`). The framework just provides the registration + lifecycle wiring.

## Backend story

Pub/sub is **Redis-backend-only**. `Store::publish` throws `StoreException` when the current `Store::defaultBackend()` is `TableBackend` — the Table backend has no pub/sub semantics. Documented in CLAUDE.md; the OSS website's `/store#backends` section adds the call-out.

If the user wants pub/sub semantics without committing to Redis as the storage backend, they instantiate `RedisBackend` directly + the new `RedisPubSub` class — the framework supports this. Documented in the API ref.

## Driver compatibility

The spike validated **predis SUBSCRIBE under HOOK_ALL**. phpredis SUBSCRIBE is callout in the open-caveats section of the spike result doc — same test needs to run with `ext-redis` loaded before we can promise both drivers ship working. The `RedisClient` adapter (Phase 1) already has the driver-shaped seam: each driver gets its own `subscribe(array $exactChannels, array $patternChannels, callable $consumer): void` method. The default `auto` selection still applies; users can force `prefer => predis` if their phpredis runs into trouble.

## API surface added (in `src/Store/` + `src/`)

| Class / Method | Purpose |
|---|---|
| `RedisDriver::publish(string $channel, string $payload): int` | New interface method (pub/sub). |
| `RedisDriver::subscribe(...)` | New interface method (pub/sub). Both drivers implement. |
| `RedisDriver::xadd(string $stream, array $payload, ?int $maxLen = null): string` | New interface method (Streams). |
| `RedisDriver::xreadGroup(string $group, string $consumer, array $streams, int $count, int $blockMs): array` | New interface method (Streams). |
| `RedisDriver::xack(string $stream, string $group, string $id): int` | New interface method (Streams). |
| `RedisDriver::xgroupCreate(string $stream, string $group, string $id = '$', bool $mkStream = true): void` | New interface method (Streams). |
| `RedisClient::publish/subscribe/xadd/xreadGroup/xack/xgroupCreate` | Public adapter pass-through. |
| `ZealPHP\Store\RedisPubSub` | Pub/sub subscriber lifecycle manager. Owns the dedicated connection + dispatch + reconnect. |
| `ZealPHP\Store\RedisStreams` | Streams subscriber lifecycle manager. Same shape — consumer group, XREADGROUP loop, ACK on handler success. |
| `Store::publish(string $channel, string $payload): int` | Public facade. Routes through `Store::defaultBackend()`; throws on Table backend. |
| `Store::publishReliable(string $stream, string $payload): string` | Public facade for Streams append. Throws on Table backend. |
| `App::onPubSub(string $channel, callable $handler): void` | Public registry + auto-wiring. Idempotent — re-calling after `App::run()` is a no-op with a debug log. |
| `App::onReliableMessage(string $stream, callable $handler, ?string $group, int $blockMs, int $batchSize): void` | Public registry + auto-wiring for Streams consumers. |
| `App::offPubSub(string $channel, ?callable $handler = null): int` | Optional unregister. Returns count removed. |

## Tests

- `tests/Unit/Store/RedisPubSubTest.php` — exact-channel pub/sub, pattern channels, multiple handlers, handler-throws-doesn't-crash-runner, reconnect after force-kill of redis (skipped if no valkey).
- `tests/Unit/Store/RedisStreamsTest.php` — XADD round-trip, consumer group creation idempotent, XREADGROUP delivers, XACK removes from pending, NACK leaves pending, handler-throws-leaves-pending.
- `tests/Unit/Store/StorePublishTest.php` — facade-level: `publish`/`publishReliable` from any context, error on Table backend, receiver count / message id returned.
- `tests/Integration/PubSubIntegrationTest.php` — full request-lifecycle: handler runs in a real worker, message routes via PUBLISH from one route to another route's subscriber.
- `tests/Integration/ReliableMessageIntegrationTest.php` — same but for Streams.
- Reuse existing `scripts/spike-*` as smoke harnesses.

## Implementation phases (single PR — bundled on top of release/v0.2.39)

1. **`RedisDriver` extensions** — `publish/subscribe/xadd/xreadGroup/xack/xgroupCreate` interface methods + impl in both `PredisDriver` and `PhpredisDriver`. Adapter `RedisClient` pass-through. Unit tests against live valkey.
2. **`RedisPubSub` lifecycle class** — dedicated cor, reconnect, dispatch with `go()` per message. Unit tests + repro of the spike scenarios.
3. **`RedisStreams` lifecycle class** — dedicated cor, XREADGROUP loop, ACK on handler-true, NACK on handler-false-or-throw. Unit tests.
4. **`Store::publish` / `Store::publishReliable` facade methods + `App::onPubSub` / `App::onReliableMessage` registries + boot wiring in `App::run()`** — public API + autostart of subscriber cors in `onWorkerStart`, clean shutdown in `onWorkerStop`. Unit + integration tests.
5. **Demo routes + integration test** — `/demo/pubsub/route?to=X&msg=Y` exercises the public API end-to-end on both primitives.
6. **Docs** — CLAUDE.md section, `template/pages/learn/pubsub.php` walkthrough, `/store#pubsub` and `/store#streams` anchors on the existing page, README Features-table row mentions cross-node messaging + reliable variant.

Each phase commits separately. Branch is `release/v0.2.39` (existing), this work piles on top. Final PR ships pluggable backends + pub/sub + Streams together as v0.2.39.

## Risks / open questions

- **phpredis subscribe under HOOK_ALL** — same caveat as the spike result doc. We need to install `ext-redis` somewhere and re-run the spike with a phpredis driver. The implementation defaults to `prefer => 'auto'` (phpredis preferred when loaded), so production deploys with `ext-redis` need that validated before v0.2.40 ships.
- **Lost messages during reconnect window** — fundamental property of Redis pub/sub. Documented; recommend Streams for must-not-drop. Worth adding a `Store::publishReliable()` Stream-based variant in a future release if user demand emerges.
- **Memory growth from runaway handlers** — each PUBLISH spawns a `go()`. A handler that hangs accumulates cors. Coroutine count is bounded by OpenSwoole's `max_coroutine` setting; misuse is a user bug. Document the recommendation.
- **`App::onPubSub` registry timing** — same lifecycle constraint as `App::route()`: must be called before `App::run()`. Calling after is a debug-logged no-op (matches `App::route()` behaviour).
- **Pattern matching across drivers** — Redis pattern syntax (`*` matches everything between `:` separators; `?` matches one char; `[abc]` character classes). Both phpredis and predis pass the pattern through unchanged, so behaviour is the Redis behaviour. Just document it.

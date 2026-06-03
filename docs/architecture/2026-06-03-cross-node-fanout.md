# Cross-node pub/sub fan-out — design (scalability blockers)

Status: **design / not yet implemented.** This is the plan for the two
blocker-severity fan-out concerns from `2026-06-03-scalability-audit.json`. It
rewires core pub/sub and changes a documented contract, so it is written up for
review before implementation rather than landed blind.

## The two problems

### A. `Store::publish` fan-out is O(workers × nodes) per message

Every `App::subscribe()` handler today spawns **one dedicated Redis SUBSCRIBE
connection per worker** (`App::wirePubSubBoot()` → `RedisPubSub` per worker in
`onWorkerStart`). So for W workers × N nodes:

- **Subscriber connections:** `W × N` (plus the pool). On 32 workers × 4 nodes
  that's 128 idle SUBSCRIBE connections against one Redis just for one channel.
- **Deliveries per publish:** Redis copies the payload to all `W × N`
  subscribed connections. A single `Store::publish` becomes `W × N` network
  sends + `W × N` PHP-side decodes.

`Store::publish()` returns that `W × N` receiver count today — and the docs
explicitly document it ("32 workers on one node + 32 on a peer = `receivers:
64`"). **Changing this is a documented-contract change.**

### B. WS room broadcast wakes every worker on every node

`WSRouter::init()` subscribes every worker to the cluster-wide
`ws:room:*` PSUBSCRIBE (`src/WSRouter.php:450`). `Room::publish()` publishes to
`ws:room:{name}` (`src/WS/Room.php`). So **every** room message is delivered to
**every worker on every node**, each of which JSON-decodes it and checks its
local membership — even nodes/workers with **zero** members of that room.

## Design A — per-node subscriber aggregator (W×N → N)

Run **one** SUBSCRIBE connection **per node** (not per worker) that re-fans-out
to the node's workers over OpenSwoole's inter-process pipe.

- **Aggregator placement:** a dedicated sidecar via `App::addProcess('pubsub-aggregator', …)`
  (already supported), OR designate worker 0. A sidecar is cleaner (survives
  worker recycle, isolated). It holds the single `RedisPubSub` runner for the
  node and subscribes to every registered channel/pattern once.
- **Re-fan-out:** on each received message the aggregator calls
  `$server->sendMessage([$channel, $payload], $workerId)` to each worker (pipe
  IPC). Each worker registers `onPipeMessage` → dispatch to its local
  `App::subscribe` handlers (the same dispatch `RedisPubSub::dispatch` does now,
  minus the Redis read).
- **Targeting within node:** the aggregator can be smarter than broadcast-to-all-workers
  for WS (see Design B) — it only needs to message workers that own a relevant
  fd. For generic `App::subscribe` handlers, it messages all workers (the handler
  is registered per-worker).
- **Connections after:** `N` subscriber connections (one per node) + the per-worker
  data pools. From `W × N` to `N`.

### BC / receiver-count contract

`Store::publish()` returns the Redis `PUBLISH` reply = number of subscriber
connections that received it. With the aggregator that becomes **N** (one per
node), not `W × N`. Options:

1. **Document the new semantics** ("receiver count = nodes, not workers") — a
   breaking change to the documented contract, but arguably the *correct*
   number (a node is the delivery unit).
2. **Keep `W × N` reporting** by having each aggregator, on a publish it cares
   about, report its local worker count back — complex, not worth it.
3. **Opt-in:** `App::pubsubAggregator(true)` (default off initially) so existing
   apps keep the per-worker semantics until they opt in. **Recommended for the
   first release** — ships the scale win without breaking the contract, lets it
   bake, then flips the default in a later major.

### Risks

- IPC correctness (pipe back-pressure, large payloads — OpenSwoole pipe message
  size limits → may need a Table/shared-mem hand-off for big payloads).
- Aggregator is a single point of failure per node — needs the same
  reconnect/backoff `RedisPubSub` already has, plus restart-on-crash (sidecars
  are auto-restarted by OpenSwoole).
- Ordering: pipe delivery is per-worker FIFO, fine for pub/sub (no cross-channel
  ordering guarantee today anyway).
- **Cannot be fully validated on a single box** — needs a real 2-node + 1-Redis
  integration rig. A local 2-`php app.php`-process + 1-Valkey smoke is the
  minimum bar before merge.

## Design B — WS room targeting (only nodes with members)

Stop broadcasting room messages cluster-wide; route each room message only to
the servers that actually hold ≥1 member.

### Per-room server-set

Maintain `ws:room:{name}:servers` — a Redis SET of `server_id`s holding ≥1
member of room `{name}`. Maintenance must be **refcount-correct under
concurrency**, so use a per-`(room, server)` counter with the SADD/SREM riding
the 0↔1 boundary, done **atomically** (Lua), to avoid the classic
set-membership race (a `DECR→0→SREM` interleaving a `INCR→1→SADD` can otherwise
leave a server with members but absent from the set → dropped broadcasts):

```
-- join(room, server):  KEYS[1]=count:{room}:{server}  KEYS[2]=servers:{room}  ARGV[1]=server
local n = redis.call('INCR', KEYS[1])
if n == 1 then redis.call('SADD', KEYS[2], ARGV[1]) end
return n
-- leave(room, server):
local n = redis.call('DECR', KEYS[1])
if n <= 0 then redis.call('DEL', KEYS[1]); redis.call('SREM', KEYS[2], ARGV[1]) end
return n
```

Hooked into `Room::join`/`Room::leave` (only for locally-owned clients, like the
`noteLocalRoomJoin` reverse index added in the WS edge batch).

### Targeted channels — delimiter-safe encoding

Channel: **`ws:roomsrv:{serverId}:{roomName}`** (serverId is a fixed prefix, the
room name is the final `*`-matched segment, so a `:` in either the room name OR
the serverId is unambiguous). Each worker subscribes to
**`ws:roomsrv:{thisServerId}:*`** instead of `ws:room:*`. `Room::publish` reads
the room's server-set and `PUBLISH`es to `ws:roomsrv:{S}:{name}` for each server
`S` in the set.

Result: a room message reaches only nodes holding members (cross-node fan-out
fixed). Within a node every worker still gets it (per-worker subscriber) — that
residual `W` factor is closed by **Design A** (the aggregator messages only the
workers owning a member fd). A and B compose.

### Receiver count

`Room::push()` would return the sum of the per-server `PUBLISH` replies. With
per-worker subscribers that's `Σ workers-per-targeted-server`; with the
aggregator it's the targeted-server count. Document accordingly.

## Incremental, test-gated plan

1. **B1 — server-set maintenance** (Lua refcount + `Room::join`/`leave` hooks),
   no routing change yet. Unit-testable with Valkey by faking multiple
   `server_id`s. Low risk (additive bookkeeping).
2. **B2 — targeted publish + scoped subscribe** behind `App::wsRoomTargeting(true)`
   (default off). Flip the room channel scheme; keep a migration note. Test the
   delivery matrix (member on A only → B never receives) with two fake servers
   on one Valkey.
3. **A1 — aggregator** behind `App::pubsubAggregator(true)` (default off).
   Sidecar + `onPipeMessage` dispatch. Needs the 2-process + Valkey smoke rig.
4. Flip defaults in a later major once baked; update the `Store::publish`
   receiver-count docs.

Each step is its own PR, opt-in, and reversible.

## Why this is design-first

Both designs change **core, correctness-critical** paths (every pub/sub message,
every WS room broadcast) and the documented `Store::publish` receiver-count
contract, and they can't be fully validated on a single box. Landing them
blind risks dropped messages (a refcount-set race) or silent delivery gaps
(targeting bug) in the hardest-to-debug part of the system. The opt-in,
incremental, test-gated plan above is the safe path; this document is the
checkpoint before B1 starts.

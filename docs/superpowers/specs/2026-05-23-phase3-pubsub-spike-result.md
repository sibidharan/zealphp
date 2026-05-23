# Phase 3 Pub/Sub Spike — Result

**Date:** 2026-05-23
**Spec gate:** `docs/superpowers/specs/2026-05-22-store-redis-backend-design.md` §Phase 3 (lines 124-128) — "phpredis/predis SUBSCRIBE must yield under `Runtime::enableCoroutine(HOOK_ALL)` inside a dedicated coroutine, doesn't block the worker"

## Verdict

**PASS** — predis SUBSCRIBE yields cleanly under HOOK_ALL. The Phase 3 design ships unchanged on predis without falling back to `OpenSwoole\Coroutine\Redis`. (phpredis still needs the same spike with `ext-redis` loaded — see "Remaining work" below.)

## What was tested

`scripts/spike-predis-subscribe.php` runs a single coroutine scheduler with:

- **1 subscriber coroutine** holding its own predis client in `pubSubLoop()`/`subscribe('spike:phase3')`.
- **8 op-worker coroutines** — each owning its own predis client, running 50 × (HSET + HGETALL) on a private hash. Simulates request handlers running while the subscriber is open.
- **1 publisher coroutine** — fires 10 `PUBLISH spike:phase3 <microtime>` at 50ms intervals.

All under `Runtime::enableCoroutine(Runtime::HOOK_ALL)`. Target valkey: `127.0.0.1:16379` (the test-only instance from `make valkey-up`).

## Measurements (two runs, deterministic)

| Metric | Run 1 | Run 2 |
|---|---|---|
| Wall clock total | 526.6 ms | 526 ms |
| Worker ops completed | 400 / 400 | 400 / 400 |
| Aggregate throughput | 760 ops/sec | ~760 ops/sec |
| Per-op-cor median wall time | 23.3 ms | 23.3 ms |
| Per-op-cor max wall time | 23.8 ms | ~23 ms |
| Messages received | 10 / 10 | 10 / 10 |
| Publish-to-receive median latency | 0.40 ms | 0.38 ms |
| Publish-to-receive p95 | 1.15 ms | 0.38 ms |

## Interpretation

| Hypothesis | Empirical |
|---|---|
| **Subscriber blocks the worker** (catastrophic case the spec was guarding against) | False. Op-cors complete at ~23ms each; if subscribe had blocked them, they'd serialize behind the publisher's 50ms-spaced messages and total ~500ms+. They finished in parallel. |
| **Subscriber misses messages while ops run** | False. 10/10 received in every run. |
| **Receive latency is sane** | True. ~0.4ms median on loopback — that's "predis read-loop got woken up, parsed the frame, returned in the next event-loop tick." No queuing. |
| **Worker ops are slowed by an active subscriber** | Negligible. ~23ms for 100 RTTs per cor = ~230µs per RTT — close to predis baseline against local valkey. |

## What this proves about the design

The Phase 3 architecture as described in the spec (per-worker dedicated subscriber coroutine + dedicated connection + origin-tagged invalidation messages) is **viable on predis** without modification. Specifically:

1. `pubSubLoop()` / `subscribe()` integrates with OpenSwoole's hooked socket I/O — the underlying `stream_socket_recvfrom`/`fread` calls yield when no data is available.
2. The publisher path can use a regular pool client (PUBLISH is one-shot, fits the pool model perfectly).
3. No coroutine starvation under contention.

## What this does NOT yet validate

1. **phpredis SUBSCRIBE under HOOK_ALL.** This spike runs predis. phpredis is the *preferred* driver when `ext-redis` is loaded — its subscribe loop is implemented in C, not PHP, so the yielding behaviour could differ. Same spike needs to run with `ext-redis` installed before the Phase 3 implementation can promise both drivers work. **Next step:** install `ext-redis` (locally or on a sysbox container with `pecl install redis`) and re-run `scripts/spike-predis-subscribe.php` after swapping `new PredisClient(...)` for a phpredis client.
2. **~~Multi-process / cross-node delivery.~~** *Validated — see "Cross-node spike" below.*
3. **Subscriber graceful shutdown.** The spike unsubscribes when the message budget is hit. Real Phase 3 needs `onWorkerStop` lifecycle hooks to close the subscriber cleanly; the current `App::onWorkerStop` hook (already shipped) covers this.

## Cross-node spike

`scripts/spike-crossnode-server.php` + `scripts/spike-crossnode-run.sh` boot two actual ZealPHP server instances on different ports (8090 + 8091) against a shared valkey. Each registers a dedicated subscriber coroutine on its identity channel (`ws:server:A` / `ws:server:B`) inside `App::onWorkerStart`. The runner curls `/publish?to=X&msg=Y` on one server, the other's subscriber receives.

**Test cases**

| # | Direction | Result | One-way latency (loopback) |
|---|---|---|---|
| 1 | A → B (cross-process) | PASS | **0.35 ms** |
| 2 | B → A (cross-process) | PASS | **0.29 ms** |
| 3 | B → B (intra-process self-publish, sanity) | PASS | **0.17 ms** |
| 4 | Publisher `PUBLISH` reports `receivers=1` for every send | PASS | — |

**What this proves**

The full architecture from the cross-server WS routing diagram works end-to-end on two ZealPHP processes:

- `App::onWorkerStart` is a viable place to spawn the subscriber cor — it survives the lifecycle.
- The subscriber cor coexists with the HTTP request handlers in the same worker — `/publish` requests on the same server keep responding while the subscriber loop runs.
- Sub-millisecond cross-process delivery on loopback. Cross-host across a LAN adds typical Linux TCP latency (~0.1–1 ms); cross-region adds the WAN one-way (~1–80 ms depending on geo). The architecture absorbs all three.
- The same machinery would route a WebSocket push: replace "append to log" in the subscriber handler with `$server->push($localFd, $payload)` and the cross-server WS routing pattern lights up.

**Open caveat:** this spike runs both servers on the same host. Cross-HOST (different machines, real NIC) is the same lib-level operation but adds physical network latency; worth a final spike against a remote valkey or remote ZealPHP for the production deploy story. Same script, just two hosts.

## Conclusion for Phase 3 planning

The spec's central assumption holds: **subscribe yields, the dedicated-subscriber-coroutine pattern works**. The spike-gate is lifted for the predis path; ship phpredis with the same spike re-run as the gate for that driver. The `RedisClient` adapter's swap-able driver shape (Phase 1) already accommodates a per-driver subscribe override if phpredis ever fails the spike, without disturbing the rest of the framework.

Implementation can proceed when prioritized: extend `RedisClient` with `subscribe(channels, handler): never` and `publish(channel, payload): int`, then build `TieredBackend` on top with the publish-on-write + subscribe-on-startup pattern documented in the original spec.

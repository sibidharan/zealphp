# Store/Counter Pub/Sub + Streams — Implementation Plan

> **For agentic workers:** Use `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship public pub/sub API (`Store::publish` / `App::onPubSub`) + reliable variant via Redis Streams (`Store::publishReliable` / `App::onReliableMessage`) on top of the Phase 1 pluggable-backend infrastructure already on `release/v0.2.39`. All decisions and trade-offs are captured in `docs/superpowers/specs/2026-05-23-store-pubsub-design.md`.

**Architecture:** Two new lifecycle classes (`RedisPubSub` and `RedisStreams`) each own a dedicated Redis connection per worker (separate from the `RedisConnectionPool` because SUBSCRIBE and XREADGROUP monopolise the connection). Both spawn their own coroutine in `App::onWorkerStart`, dispatch handlers via `go()` (per-message concurrency), reconnect on drop with bounded backoff. The driver layer gains 6 new methods (`publish`, `subscribe`, `xadd`, `xreadGroup`, `xack`, `xgroupCreate`) on the `RedisDriver` interface; both `PredisDriver` and `PhpredisDriver` implement.

**Tech stack:** Same as Phase 1 — PHP 8.3, OpenSwoole 26.2, predis 2.2 (dev), optional phpredis ext, valkey-server for tests.

**Spec reference:** `docs/superpowers/specs/2026-05-23-store-pubsub-design.md`

---

## Conventions (apply to every task)

- **Strict types** at the top of every new `src/` file.
- **Namespace:** `ZealPHP\Store` for the new lifecycle classes.
- **PHPStan level 10**: no `mixed` widening to silence, no `@phpstan-ignore`, no `assert()` overrides. Narrow at the boundary.
- **TDD:** failing test first, then impl, then green.
- **Commit per task** with descriptive message (release/v0.2.39 piles up; squash-rebase at PR open if needed).
- **Tests skip gracefully** when valkey is unreachable (`RedisTestCase::markTestSkipped`) — keeps the suite green where it isn't.
- **No HOOK_ALL toggling in unit tests** — that's process-global; use deterministic Channel-signalled tests instead (same pattern as the de-flaked pool test).

---

## Task 1: Driver interface extensions

**Files:**
- Modify: `src/Store/RedisDriver.php`

- [ ] **1.1 — Add pub/sub methods to the interface:**
  - `publish(string $channel, string $payload): int` — returns receiver count.
  - `subscribe(array $exactChannels, array $patternChannels, callable $consumer): void` — blocking; calls `$consumer($payload, $channel, ?$pattern)` per frame; returns when consumer signals stop (via thrown `\ZealPHP\Store\PubSubStopException`).

- [ ] **1.2 — Add Streams methods:**
  - `xadd(string $stream, array $fields, ?int $maxLen = null): string` — returns message ID.
  - `xgroupCreate(string $stream, string $group, string $id = '$', bool $mkStream = true): bool` — idempotent (catches BUSYGROUP).
  - `xreadGroup(string $group, string $consumer, array $streams, int $count, int $blockMs): array` — returns `array<string, list<array{id:string, payload:array<string,string>}>>` keyed by stream.
  - `xack(string $stream, string $group, string ...$ids): int` — returns count ACK'd.

- [ ] **1.3 — Create `src/Store/PubSubStopException.php`** (extends `\RuntimeException`) — the sentinel used by RedisPubSub/RedisStreams runners to break out of the blocking loops cleanly on worker shutdown.

---

## Task 2: PredisDriver implementations

**Files:**
- Modify: `src/Store/PredisDriver.php`
- Modify: `tests/Unit/Store/RedisClientTest.php` (add publish/subscribe round-trip)

- [ ] **2.1 — Failing test:** `testPublishSubscribeRoundTrip` — spawn subscriber + publisher cor in a `Coroutine\Channel`-gated sequence, assert message received with the right payload + channel + pattern=null.

- [ ] **2.2 — `publish()`:** delegate to `$this->c->publish($channel, $payload)`; narrow return via `asInt`.

- [ ] **2.3 — `subscribe()`:** use predis 2.x `pubSubLoop()` + `subscribe()`/`psubscribe()`; iterate; route by `$msg->kind` (`message` / `pmessage` / `subscribe` / `psubscribe`); call `$consumer($payload, $channel, $pattern)` only for `message`/`pmessage`. Catch `PubSubStopException` to break cleanly; let `PredisException` propagate (caller handles reconnect).

- [ ] **2.4 — Failing test:** `testXaddXreadGroupRoundTrip` — XGROUP CREATE (MKSTREAM), XADD, XREADGROUP (BLOCK 100ms), assert message visible; XACK, assert XPENDING shrinks.

- [ ] **2.5 — Streams methods:**
  - `xadd`: `$this->c->xadd($stream, $maxLen ? ['NOMKSTREAM' => false, 'MAXLEN' => ['~', $maxLen]] : [], '*', $fields)` — return ID.
  - `xgroupCreate`: `try { xgroup CREATE ... MKSTREAM; return true; } catch (PredisException $e if BUSYGROUP) { return false; }`.
  - `xreadGroup`: `$this->c->xreadgroup($group, $consumer, $streams, count, blockMs)` → normalize to `array<string, list<{id, payload}>>`.
  - `xack`: `$this->c->xack($stream, $group, ...$ids)` → int.

- [ ] **2.6 — PHPStan + commit.**

---

## Task 3: PhpredisDriver implementations

**Files:**
- Modify: `src/Store/PhpredisDriver.php`
- The bundled `\Redis` PHPStan stub already covers these methods.

- [ ] **3.1 — Mirror Task 2 method-by-method:** phpredis `publish/subscribe/psubscribe/xAdd/xGroup/xReadGroup/xAck`. Note that phpredis `subscribe()` takes a callback `function($redis, $channel, $payload)` (no kind field — phpredis splits subscribe/psubscribe into two methods). Unify by registering both `subscribe()` and `psubscribe()` on parallel goroutines OR by adapting the callback shape. **The cleanest path:** call `subscribe()` and `psubscribe()` separately in two child cors; both feed a shared `Coroutine\Channel` of `[payload, channel, pattern]` tuples that the runner reads from.

- [ ] **3.2 — Document the open caveat in the class docblock:** phpredis SUBSCRIBE under HOOK_ALL still needs the same in-process spike as predis. Until that runs (locally with `ext-redis` installed or on a CI run that has the ext + a valkey), the implementation ships behind the `auto`-detect default. Users who hit issues can force `prefer => predis`.

- [ ] **3.3 — PHPStan + commit.**

---

## Task 4: RedisClient adapter pass-throughs

**Files:**
- Modify: `src/Store/RedisClient.php`
- Modify: `tests/Unit/Store/RedisClientTest.php` (cover both driver paths via `prefer` overrides)

- [ ] **4.1 — Add public methods on `RedisClient`:** `publish`, `subscribe`, `xadd`, `xgroupCreate`, `xreadGroup`, `xack`. All delegate to `$this->driver->...`.

- [ ] **4.2 — Tests:** explicit `prefer => 'predis'` round-trip already covered; if `ext-redis` available, also exercise `prefer => 'phpredis'`. Skip phpredis tests cleanly when the ext isn't loaded.

- [ ] **4.3 — PHPStan + commit.**

---

## Task 5: `RedisPubSub` lifecycle class

**Files:**
- Create: `src/Store/RedisPubSub.php`
- Create: `tests/Unit/Store/RedisPubSubTest.php`

The runner: holds the registry, runs in its own coroutine, owns a dedicated `RedisClient`, dispatches handlers via `go()`, reconnects with bounded exponential backoff.

- [ ] **5.1 — Failing test:** `testRegistersHandlerAndReceivesPublish` — register handler, publish via a separate pool client, assert handler ran with the right payload. (Uses a Channel to signal handler-ran-with-X back to the test.)

- [ ] **5.2 — Failing test:** `testMultipleHandlersAllFireInRegistrationOrder`.

- [ ] **5.3 — Failing test:** `testPatternSubscriptionMatchesByPrefix` (`'ws:server:*'`).

- [ ] **5.4 — Failing test:** `testHandlerThrowDoesNotCrashRunner` — first handler throws, second still runs.

- [ ] **5.5 — Class skeleton:**
  ```php
  final class RedisPubSub {
      /** @var array<string, list<callable>> */ private array $exactHandlers   = [];
      /** @var array<string, list<callable>> */ private array $patternHandlers = [];
      private ?Channel $stopSignal = null;
      private bool $running = false;
      public function __construct(private string $url, private array $opts = []) {}
      public function register(string $channelOrPattern, callable $handler): void { /* contains '*'? pattern : exact */ }
      public function start(): void { /* spawn the runner cor; track started so re-start is no-op */ }
      public function stop(): void { /* push to stopSignal */ }
      private function runner(): void { /* loop: connect → subscribe(exacts, patterns, consumer) → on PubSubStopException break → on PredisException backoff+retry */ }
      private function dispatch(string $payload, string $channel, ?string $pattern): void { /* go() per handler that matches */ }
  }
  ```

- [ ] **5.6 — Backoff helper:** `private static function backoffSeconds(int $attempt): float { return min(0.1 * (2 ** $attempt), 5.0); }`.

- [ ] **5.7 — PHPStan + commit.**

---

## Task 6: `RedisStreams` lifecycle class

**Files:**
- Create: `src/Store/RedisStreams.php`
- Create: `tests/Unit/Store/RedisStreamsTest.php`

Mirror of RedisPubSub but for Streams. Loop: `XREADGROUP COUNT N BLOCK ms` → dispatch each entry via `go()` → ACK on handler-true.

- [ ] **6.1 — Failing tests:**
  - `testHandlerReceivesMessageAndAcks` — XADD then assert handler fires.
  - `testHandlerReturningFalseLeavesPending` — XPENDING shows the message.
  - `testHandlerThrowLeavesPending` — same.
  - `testConsumerGroupCreatedIdempotently` — start twice, no error.

- [ ] **6.2 — Class skeleton:**
  ```php
  final class RedisStreams {
      /** @var list<array{stream:string, group:string, handler:callable, blockMs:int, batchSize:int}> */
      private array $consumers = [];
      private ?Channel $stopSignal = null;
      private string $consumerName;
      public function __construct(private string $url, ?string $consumerName = null, private array $opts = []) {
          $this->consumerName = $consumerName ?? gethostname() . '-' . getmypid();
      }
      public function register(string $stream, string $group, callable $handler, int $blockMs = 1000, int $batchSize = 16): void { /* ... */ }
      public function start(): void { /* spawn runner */ }
      public function stop(): void { /* signal */ }
      private function runner(): void {
          // for each consumer: XGROUP CREATE (idempotent)
          // loop: XREADGROUP COUNT batch BLOCK blockMs STREAMS <all streams> >>>
          //   for each entry: go(handle($entry))
      }
      private function handle(array $entry, string $stream, string $group, callable $handler): void {
          try { if ($handler($payload, $entry['id'], $stream)) { $client->xack($stream, $group, $entry['id']); } }
          catch (\Throwable $e) { elog("reliable handler threw: $e", 'error'); }
      }
  }
  ```

- [ ] **6.3 — PHPStan + commit.**

---

## Task 7: `Store::publish` + `Store::publishReliable` facade

**Files:**
- Modify: `src/Store.php`
- Create: `tests/Unit/StorePublishTest.php`

- [ ] **7.1 — Failing tests:**
  - `testPublishOnRedisBackendReturnsReceiverCount` — register subscriber, publish, assert count = 1.
  - `testPublishOnTableBackendThrowsStoreException` — `Store::defaultBackend('table'); Store::publish(...)` throws.
  - `testPublishReliableOnRedisBackendReturnsMessageId` — XADD round-trip via facade.
  - `testPublishReliableOnTableBackendThrowsStoreException`.

- [ ] **7.2 — Facade methods on `Store`:**
  ```php
  public static function publish(string $channel, string $payload): int {
      $b = self::defaultBackend();
      if (!($b instanceof RedisBackend)) {
          throw new StoreException("Store::publish requires the redis backend (current: " . self::$backendConfig['kind'] . ")");
      }
      return $b->publish($channel, $payload);
  }
  public static function publishReliable(string $stream, string $payload): string {
      $b = self::defaultBackend();
      if (!($b instanceof RedisBackend)) {
          throw new StoreException("Store::publishReliable requires the redis backend (current: " . self::$backendConfig['kind'] . ")");
      }
      return $b->publishReliable($stream, $payload);
  }
  ```

- [ ] **7.3 — Backend methods on `RedisBackend`:** small wrappers around the pool — `publish` does `pool->with(fn($c) => $c->publish(...))`; `publishReliable` does `pool->with(fn($c) => $c->xadd($stream, ['payload' => $payload]))`.

- [ ] **7.4 — PHPStan + commit.**

---

## Task 8: `App::onPubSub` / `App::onReliableMessage` registry + boot wiring

**Files:**
- Modify: `src/App.php`
- Create: `tests/Integration/PubSubIntegrationTest.php`
- Create: `tests/Integration/ReliableMessageIntegrationTest.php`

- [ ] **8.1 — Add static registries on `App`:** `$pubsubRegistry: array<string, list<callable>>` and `$reliableRegistry: array<string, list<array{group,handler,blockMs,batchSize}>>`.

- [ ] **8.2 — Public registration methods:**
  ```php
  public static function onPubSub(string $channelOrPattern, callable $handler): void {
      self::$pubsubRegistry[$channelOrPattern][] = $handler;
  }
  public static function onReliableMessage(string $stream, callable $handler, ?string $group = null, int $blockMs = 1000, int $batchSize = 16): void {
      $group ??= 'zealphp-' . substr(sha1((string) self::$canonical_name), 0, 8);
      self::$reliableRegistry[$stream][] = compact('group', 'handler', 'blockMs', 'batchSize');
  }
  public static function offPubSub(string $channelOrPattern, ?callable $handler = null): int { /* remove from registry; return count */ }
  ```

- [ ] **8.3 — Boot wiring in `App::run()`:** if either registry is non-empty AND backend is Redis, register `App::onWorkerStart` hooks that instantiate `RedisPubSub` and/or `RedisStreams`, call `register()` for each entry, then `start()`. Register matching `onWorkerStop` hooks that call `stop()`.

- [ ] **8.4 — Integration test (pub/sub):** boot a small fixture server, register a handler in `onWorkerStart` that writes to a tmp file, hit a route that publishes, assert tmp file has the message.

- [ ] **8.5 — Integration test (reliable):** same shape, XADD via a route, consumer group handler appends to log.

- [ ] **8.6 — PHPStan + commit.**

---

## Task 9: Demo routes + integration smoke

**Files:**
- Modify: `route/demo.php`
- Add fixture: `scripts/spike-pubsub-server.php` (reuses the Task 8 pattern as a CLI demo).

- [ ] **9.1 — Two new demo routes:**
  - `GET /demo/pubsub/publish?channel=X&msg=Y` — `Store::publish($channel, $msg)`; returns JSON `{receivers: N}`.
  - `GET /demo/pubsub/publish-reliable?stream=X&msg=Y` — `Store::publishReliable($stream, $msg)`; returns JSON `{message_id: '...'}`.

- [ ] **9.2 — `App::onPubSub('demo:pubsub', fn($p) => ...)` registered in `app.php` (gated on `ZEALPHP_STORE_BACKEND=redis`)** — appends each received message to `/tmp/zealphp/demo-pubsub.log`. Smoke test reads that file.

- [ ] **9.3 — PHPStan + commit.**

---

## Task 10: Docs

**Files:**
- Modify: `.claude/CLAUDE.md`
- Modify: `template/pages/store.php` (add `#pubsub` + `#streams` anchors)
- Modify: `README.md` (Features table row mentions cross-node messaging + reliable variant)
- Create: `template/pages/learn/pubsub.php` (walkthrough with the WS-routing recipe)

- [ ] **10.1 — CLAUDE.md:** add a "Pub/Sub + Streams" subsection under the Pluggable backends entry. Document the two primitives, when to pick each, the reconnect-window message-loss caveat, the phpredis-spike-still-open caveat, the cross-language interop note.

- [ ] **10.2 — store.php:** new `#pubsub` section after `#backends`. Snippet for `Store::publish` + `App::onPubSub`. Comparison table from the spec (latency / throughput / durability / use-case). Cross-link to the learn page.

- [ ] **10.3 — store.php:** new `#streams` section. Snippet for `Store::publishReliable` + `App::onReliableMessage`. Note about Phase 2 auto-claim.

- [ ] **10.4 — README:** update the Features row to mention pub/sub + reliable.

- [ ] **10.5 — learn/pubsub.php:** full walkthrough — when to use, the WS-routing recipe (verbatim from the spec), the cross-language note, the operator-debugging tips (`SUBSCRIBE` from `valkey-cli`, `XPENDING` inspection).

- [ ] **10.6 — PHPStan + commit.**

---

## Task 11: CHANGELOG

**Files:**
- Modify: `CHANGELOG.md` — extend the [0.2.39] entry already drafted.

- [ ] **11.1 — Append to the existing [0.2.39] Added section:**
  - `Store::publish` / `App::onPubSub` — fire-and-forget pub/sub.
  - `Store::publishReliable` / `App::onReliableMessage` — Streams-backed at-least-once.
  - `ZealPHP\Store\RedisPubSub`, `RedisStreams` lifecycle classes.

- [ ] **11.2 — Commit.**

---

## Verification (before opening PR)

1. `./vendor/bin/phpunit tests/Unit/` — all green; new Store/Counter pubsub/streams tests pass.
2. `./vendor/bin/phpunit tests/Integration/` — all green with server up.
3. `./vendor/bin/phpstan analyse --no-progress` — 0 errors level 10.
4. `make valkey-up && ./vendor/bin/phpunit tests/Unit/Store/RedisPubSubTest.php tests/Unit/Store/RedisStreamsTest.php` — green.
5. Three repeats of the full unit suite — no flakes (subscriber/runner tests can be tricky).
6. Live smoke: `make valkey-up; ZEALPHP_STORE_BACKEND=redis php app.php start -d; curl /demo/pubsub/publish?channel=demo:pubsub&msg=hello; cat /tmp/zealphp/demo-pubsub.log` shows the message.
7. PR includes the link to the spike result doc (`2026-05-23-phase3-pubsub-spike-result.md`) as the empirical foundation.

---

## Risks specific to this implementation

| Risk | Mitigation |
|---|---|
| phpredis SUBSCRIBE under HOOK_ALL is untested in CI | Default `auto` picks phpredis when loaded; document the open caveat; offer `prefer => predis` escape. Add a separate phpredis spike to the CI matrix once `ext-redis` is available there. |
| Subscriber cor eats memory if a handler hangs | Each handler runs in its own `go()`; if a user handler hangs, the cor accumulates. Document the recommendation. OpenSwoole's `max_coroutine` setting bounds the damage. |
| Streams consumer group naming collisions across cluster | Default group = `'zealphp-' . sha1(canonical_name)`. Users with multiple apps on one Redis must override or set distinct `App::canonicalHost()`. Document. |
| Stream backlog grows unbounded | `xadd` accepts `?int $maxLen` for `MAXLEN ~` trimming. Wire it through. Default unlimited; document the operator concern. |
| Subscriber runner crashes silently | `runner()` is the cor body; uncaught throws would lose the runner. Wrap the loop body in `try { ... } catch (\Throwable $e) { elog($e); }` so the cor stays alive even on malformed messages. |
| Tests are flaky under load | Use Channel-signalled tests (handler pushes a sentinel to a Channel the test pops with timeout). No sleep/yield races. Same de-flake pattern as the Pool tests. |

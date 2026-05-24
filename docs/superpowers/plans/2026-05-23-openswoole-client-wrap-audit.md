# OpenSwoole client wrap audit — Phase 2 planning

> Status: planning doc (no code yet). The decision matrix here drives Phase 2 implementation work in v0.3.0 / v0.4.0.

OpenSwoole 22.x ships a set of `OpenSwoole\Coroutine\*` clients that all yield to the scheduler when called inside a coroutine. The question for ZealPHP is, **for each client**:

1. **Wrap** — provide a typed `ZealPHP\*` facade with framework-shaped ergonomics (auto-JSON, typed responses, retry/error envelopes, integrated metrics).
2. **Passthrough** — document the raw client as the canonical surface; users `new OpenSwoole\Coroutine\X\Client(...)` directly.
3. **Hybrid** — provide a wrap for the common-case 80%; expose `->raw()` for advanced users.

The decision is informed by:
- **Surface mismatch** — is the raw API ergonomically painful for the common use case?
- **Ecosystem parity** — does PHP-FPM users expect a Guzzle / pecl-style shape they don't get from the raw OpenSwoole client?
- **Cross-cutting concerns** — do we want to inject `App::stats()` counters, tracing IDs, retry logic, etc.?
- **Stability** — is the raw API stable enough across OpenSwoole versions that direct user code doesn't break?

---

## The inventory

| Client | Current state | Recommendation | Rationale |
|---|---|---|---|
| **HTTP** (`OpenSwoole\Coroutine\Http\Client`) | Wrapped as `ZealPHP\HTTP::get/post/put/delete/request` + `HTTPResponse` (P1.11, v0.2.40) | Keep wrapped | Raw API requires manual JSON encode/decode, body/header dance per request. Wrap auto-encodes arrays, sets Accept, returns typed `HTTPResponse{ok,status,body,headers,error}`. |
| **FastCGI** (`OpenSwoole\Coroutine\FastCGI\Client`) | Currently raw — used inside `App::cgiFcgi()` only | Wrap as `ZealPHP\FastCGI::request($address, $params, $body)` + `FCGIResponse{status,headers,body,stderr}`. Phase 2 task #45 depends on this. | Raw client has 6-arg `execute()` signature; the protocol-level details (params dictionary, stdin streaming) are repetitive across call sites. Wrap also lets us thread metrics + per-pool connection re-use. |
| **MySQL** (`OpenSwoole\Coroutine\MySQL`) | Direct — no ZealPHP layer | Don't wrap — recommend PDO | OpenSwoole 22 doesn't hook PDO, but the team has been promising hooks. Wrapping MySQL would compete with PDO + create a parallel SQL layer apps would have to migrate from later. Document PDO as canonical; the OpenSwoole client is a power-user escape hatch only. |
| **PostgreSQL** (`OpenSwoole\Coroutine\PostgreSQL`) | Direct | Don't wrap | Same reasoning as MySQL — defer to PDO. |
| **Redis** (`OpenSwoole\Coroutine\Redis`) | Hidden inside `ZealPHP\Store\RedisClient` (delegates to phpredis OR predis, not the OpenSwoole client) | Reconsider in v0.4.0 | We deliberately chose phpredis/predis over OpenSwoole's `Coroutine\Redis` because they're battle-tested + have more idiomatic PHP APIs. Worth re-evaluating once OpenSwoole's Redis client has more adoption signal. **No action this phase.** |
| **WebSocket\Client** | Direct via `Coroutine\Http\Client::upgrade()` | Don't wrap | Already small surface; a wrap would just add indirection. The smoke scripts (`scripts/smoke-v0.2.40.php`) use it directly. |
| **System ops** (`Coroutine\System::sleep/readFile`) | Partially wrapped — `App` provides shell-execution helper for the common case, raw for the rest | Keep current shape | Shell helper covers the common case (shell-out from handlers). `::readFile` / `::sleep` are simple enough; users invoke directly. |
| **gRPC** (n/a — not in OpenSwoole 22) | n/a | Future wrap — Phase 2/3 roadmap | Phase 1 only if a real workload needs it. |
| **Channel** (`OpenSwoole\Coroutine\Channel`) | Direct | Don't wrap | Primitive. Wrap would lose the type clarity. |
| **WaitGroup** | n/a in OpenSwoole 22 | Replaced by `App::parallel` / `parallelLimit` (P1.4, v0.2.40) | Already shipped wrap. |
| **Process** (`OpenSwoole\Process`) | Wrapped via `App::addProcess` (P2.1, v0.2.40) | Keep wrapped | Lifecycle ownership is framework concern. |
| **Server** (`OpenSwoole\WebSocket\Server`, `OpenSwoole\Http\Server`) | Wrapped as `App::ws()`, route registration etc. | Keep wrapped | Heart of the framework. Direct access still available via `App::getServer()`. |
| **Timer** (`OpenSwoole\Timer::tick`/`::after`) | Wrapped as `App::tick` / `App::after` | Keep wrapped | Cleaner ergonomics + scheduled cleanup in worker stop. |

---

## The split, summarised

**Wrapped (framework-shaped):** HTTP, FastCGI [Phase 2], Process, Server, Timer, shell helper, parallel.

**Passthrough (raw OpenSwoole canonical):** System::readFile/sleep, Channel, MySQL, PostgreSQL, Coroutine::create/getCid, Atomic, Table (via Store backend).

**Reconsider later:** Coroutine\Redis (v0.4.0), Coroutine\WebSocket\Client.

**Future:** gRPC (Phase 3+).

---

## Implementation notes for Phase 2 task #45 (FCGI wrap)

The wrap exposes a one-shot `ZealPHP\FastCGI::request($params, $body, $address)` returning a typed `FastCGIResponse{status,headers,body,stderr}`. Also a per-worker connection pool (`FastCGI::pool($address, $size)`) for routes that forward every request to an upstream FPM pool (the `cgiMode='fcgi'` story). `App::cgiFcgi()` then delegates to the wrap, keeping its current public signature for BC. This is the foundation for **task #45 (FCGI as default `cgiMode`)**.

---

## Open questions

1. **Coroutine\Redis** — re-evaluate after OpenSwoole 26.x. If their client gains adoption + the SUBSCRIBE shape is cleaner than phpredis/predis, swap the underlying driver in `RedisClient`.
2. **Coroutine\WebSocket\Client** — used in smoke scripts; not yet on the user-facing API. If chat-style apps need to call OTHER WS servers from inside ZealPHP handlers (federated chat, bot integrations), a `ZealPHP\WS\Client` wrap would be ergonomically valuable.
3. **MySQL / PostgreSQL** — track the OpenSwoole PDO-hook roadmap. If/when hooked PDO lands, the existing `PDO` doctrine is the canonical path; OpenSwoole's direct clients fade.

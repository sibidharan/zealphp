# Server-driven components — design plan (#509, phase 5)

Status: **phase 1 implemented** (`src/Component/`, guide: `docs/components.md`). Phases 2–5 are proposals. The #509 render fixes (`renderHtmx()` precedence, `e()`/`Escape`, `render()` echo semantics) landed separately in #510.

## Goal

Let a ZealPHP app build interactive UI from PHP classes and plain PHP templates. The server owns the state and renders HTML; htmx swaps it in; a WebSocket tells the page when server-side data changed. No client-side framework, no virtual DOM, no JSON API to keep in sync.

Constraints that hold through every phase:

- **Opt-in.** Plain templates, `App::fragment()` and `renderHtmx()` keep working unchanged. A component is just another thing a template can render.
- **Everything works without JavaScript.** Components render as normal SSR. An action submitted as a plain form gets a 303 back to the page.
- **No per-session server memory by default.** OpenSwoole workers are long-lived and shared by many users; state that lives in a worker is a leak or a crash waiting to happen (see the isolation sections of `CLAUDE.md`).
- **Every byte of HTML a user receives was rendered in that user's own request**, with their session, their auth and their middleware. That rule is what keeps one user's data out of another user's page.

## The three designs considered

| | A. Signed snapshot (Livewire-style) | B. Stateful connection (LiveView-style) | C. A + push invalidation |
|---|---|---|---|
| Where state lives | In the page, as an HMAC-signed snapshot sent back with each action | In worker memory, per WebSocket connection | In the page (as A) |
| Server memory per user | ~0 | One component tree per open tab | ~0, plus one subscription row per tab |
| Reconnect | Nothing to recover | State is lost unless persisted | Refetch visible components |
| Multi-node | Any node can serve any action | Sticky to the node holding the fd | Any node; push fans out via rooms |
| Server-initiated updates | No | Yes | Yes |
| Fit with ZealPHP isolation model | Good: one fresh object per request | Poor: long-lived objects in workers, reload loses them | Good |

**Recommendation: C.** Build A first (it's useful on its own and has no push infrastructure), then add push as invalidation signals. Keep B as a possible later mode only if benchmarks show snapshots are too costly — unlikely for typical props.

## What a component looks like

```php
namespace App\Components;

use ZealPHP\Component\{Component, Action};

final class Counter extends Component
{
    public int $count = 0;                 // state: lives in the signed snapshot

    #[Action]
    public function increment(int $by = 1): void
    {
        $this->count += $by;
    }

    public function render(): mixed
    {
        return $this->view('components/counter');   // template/components/counter.php
    }
}
```

```php
<?php /* template/components/counter.php — $c is the component, props are extracted */ ?>
<div <?= $c->root() ?>>
  <span><?= e($count) ?></span>
  <button <?= $c->action('increment', ['by' => 1]) ?>>+1</button>
</div>
```

```php
<?php /* any page template */ ?>
<?= App::component(Counter::class, ['count' => 5], key: 'main') ?>
```

- `App::component()` **returns** a `SafeHtml` and never echoes, so `<?= ?>` is correct — it avoids the `render()` double-echo pitfall pinned in `RenderEchoSemanticsTest`.
- `$c->root()` emits the escaped root attributes: a stable `id`, the snapshot in `data-zc`, `hx-sync="this:queue all"`.
- `$c->action()` emits the trigger for the configured binder — with `HtmxBinder`, `hx-post="/_zeal/c/counter/increment"`, `hx-vals` with the args, and `hx-target`/`hx-swap` pointing at the root. Without JS, it's a `<form method="post">` fallback. See "Transport".

## Request lifecycle for an action

```
browser                     ZealPHP worker (any node)
  │  POST /_zeal/c/counter/increment
  │  snapshot + args + X-CSRF-Token
  ├──────────────────────────────▶ global middleware → App::when('/_zeal/c', …) → CsrfMiddleware
  │                                1. look up "counter" in the boot-time registry (never a class name from the request)
  │                                2. verify snapshot HMAC, version, session binding
  │                                3. new Counter() → hydrate props        (fresh object, this request only)
  │                                4. authorize('increment') → bind args by name, typed, scalars only
  │                                5. run increment()
  │                                6. render() → HTML + new snapshot
  │◀────────────────────────────── 200, outerHTML (or morph) swap of #counter-main
```

**Direct addressability.** The endpoint renders the component class directly — no page template runs. That is the answer to #509 item 3: `App::fragment()` has to run the page up to the region it wants; a component never touches the page. Keep `App::fragment()` as the zero-config path.

## Snapshot format and security

```json
{"c":"counter","id":"counter-main","p":{"count":6},"v":"<build>","s":"<session-hash>","t":1730900000}
```
signed as `base64url(json) . "." . hmac_sha256(json, ZEALPHP_APP_KEY)`.

| Threat | Defence |
|---|---|
| Tampered props (`count: 999999`) | HMAC over the whole snapshot; reject on mismatch with 400 |
| Replaying another user's snapshot | Snapshot binds a hash of the session id; mismatch → page reload (409 / `HX-Refresh`), never another user's state |
| Replaying your own older snapshot | Accepted until `App::componentMaxAge()` (default 24 h). Authoritative state belongs in the DB/session, not props |
| Old snapshot after a deploy changes the class | `v` (build/class-shape hash) mismatch → 409, client refetches the component fresh |
| Instantiating arbitrary classes | Name → class only via the registry built at boot from explicit `App::components([...])` |
| Calling non-action methods (`__destruct`, `render`, setters) | Only public methods with `#[Action]` declared on the component class |
| Malicious args | Bound by parameter name with type checks; scalars and arrays of scalars only; never objects |
| CSRF | Existing `CsrfMiddleware`; the token rides `hx-headers` |
| Authorization | Normal route/`App::when` middleware on `/_zeal/c`, plus `authorize(string $action): bool` on the component |
| XSS | Templates use `e()`; `root()`/`action()` escape every attribute they emit |
| Secrets in props | Props are signed, **not encrypted** — readable in page source. Documented rule: no secrets in props; keep ids and load data in `render()`. Optional encryption later if needed |

### Signing key (decided)

- `ZEALPHP_APP_KEY` — 32 random bytes, base64. New: the framework has no app-wide secret today.
- `php app.php key:generate` prints a fresh key.
- `ZEALPHP_APP_KEY_PREVIOUS` — comma-separated old keys still accepted for **verifying** (never for signing), so a key rotation doesn't break open pages.
- The snapshot key is derived with HKDF (`hash_hkdf('sha256', $appKey, 32, 'zealphp-component-snapshot')`), so future uses of the app key can't forge snapshots.
- With components registered and no key set, `App::run()` refuses to boot. Dev mode (`--dev` / `ZEALPHP_DEV=1`) instead generates an ephemeral key in the master before fork — every worker shares it, and a restart just makes open pages refetch.
- Comparison uses `hash_equals()`.

## Input binding (decided)

The model is **LiveView's form-level binding, expressed as htmx attributes, with Livewire's ergonomics.** A change sends the field (or the whole form) plus the snapshot; the server applies it to allow-listed props, validates, runs hooks and re-renders.

```php
final class Signup extends Component
{
    #[Model(rules: 'required|email')]           public string $email = '';
    #[Model(live: true, rules: 'min:3|max:20')] public string $username = '';
    public bool $saved = false;   // no #[Model] → never writable from the client

    public function updatedUsername(string $value): void { /* e.g. availability check */ }

    #[Action]
    public function save(): void
    {
        $this->validate();
        // … persist …
        $this->saved = true;
    }
}
```

```php
<form <?= $c->root() ?> <?= $c->action('save') ?>>
  <input <?= $c->model('email') ?>>     <?= $c->error('email') ?>
  <input <?= $c->model('username') ?>>  <?= $c->error('username') ?>
  <button>Sign up</button>
</form>
```

`$c->model('username')` emits `name="username"`, the current value (escaped), and — for `live` props, with `HtmxBinder` — `hx-post="/_zeal/c/signup/$update" hx-trigger="input changed delay:300ms" hx-include="closest form"`. A deferred prop emits only `name` + `value`; it rides along with the next action as an ordinary form field.

| Timing | Trigger | When to use |
|---|---|---|
| deferred (default) | sent with the next action | most fields |
| `live: true` | `input changed delay:300ms` | search boxes, live validation |
| `blur: true` | `change` | expensive validation |

Rules:

- **Allow-list, not deny-list.** Only `#[Model]` props accept client input. (Livewire is the reverse: every public prop is writable unless `#[Locked]`. Its July 2025 RCE advisory was in that property-update hydration path.)
- **Scalar types only:** `string`, `int`, `float`, `bool`, backed enums, and arrays of those. The client can never cause an object to be constructed. Type coercion follows the declared property type; a failed cast is a validation error, not a 500.
- **Server validates every update** with the prop's rules; errors re-render inline via `$c->error()`. `updated{Prop}()` hooks run after a successful update.
- **No `$update` without the snapshot** — the same HMAC, session-binding and version checks as actions.
- **Focus must survive a swap.** A live update re-renders the form while the user types, and an `outerHTML` swap would replace the focused input. Phase 1 therefore ships morph swaps (htmx's idiomorph extension for `HtmxBinder`, a bundled DOM-merge for `ZealJsBinder`), which were previously phase 4.
- **Client-only UI state** (dropdown open, tab selected) never round-trips. Plain HTML/CSS or a little Alpine.js is fine for that; the component layer doesn't manage it.
- Without JS, the form is a normal POST: the action endpoint applies every `#[Model]` field, runs the action, and 303s back.

## Transport: a plain-HTTP protocol, htmx first-class (decided)

htmx is **not** a hard requirement. The component endpoint speaks a small, documented HTTP protocol that any client can use; htmx gets first-class helpers on top of it.

**The protocol** (what the server accepts and returns, independent of any JS library):

| | Request | Response |
|---|---|---|
| Action | `POST /_zeal/c/{name}/{action}` — form-encoded or JSON body: `_zc` (signed snapshot), `_zc_args[...]`, plus `#[Model]` fields; CSRF header or `_csrf_token` | `200 text/html`: the component's root element, re-rendered, carrying its new snapshot in `data-zc` |
| Update (binding) | `POST /_zeal/c/{name}/$update` — same body | Same |
| Refetch | `GET /_zeal/c/{name}?_zc=…` | Same |
| Plain form (no JS) | Ordinary `<form method="post">` to the action URL | `303` back to the page (`Referer`, validated same-origin) |
| Errors | — | `400` bad/tampered snapshot, `403` session mismatch or `authorize()` false, `404` unknown component/action, `409` version skew (refetch), `422` validation errors (body is the re-rendered component showing them) |

Extra response headers describe side effects in a library-neutral way: `Zeal-Events` (JSON events to dispatch), `Zeal-Redirect`.

**Attribute rendering is pluggable.** `$c->root()`, `$c->action()` and `$c->model()` delegate to a `ComponentBinder` chosen once at boot:

| Binder | Emits | When |
|---|---|---|
| `HtmxBinder` (the default) | `hx-post`, `hx-trigger`, `hx-include`, `hx-target`, `hx-swap="morph:outerHTML"`, `hx-sync`; maps `Zeal-Events` → `HX-Trigger` and `Zeal-Redirect` → `HX-Redirect` | The first-class path |
| `FormBinder` | Plain `<form>`/`<button formaction>` markup only | No JS at all; also the fallback inside every other binder |
| `ZealJsBinder` | `data-zc-*` attributes for a ~2 KB vanilla `fetch()` client that ships with the framework (morph via a small bundled DOM-merge) | Apps that don't use htmx |
| Custom | Anything — implement `ComponentBinder` | Alpine, Datastar, Turbo, a custom SPA shell |

```php
App::componentBinder(new HtmxBinder());   // or ZealJsBinder, FormBinder, your own
```

The htmx helpers are first-class in the sense that they're tested and documented as the primary path, and the `HtmxResponse` builder (`$response->htmx()`) works inside actions. But nothing in the component core imports or assumes htmx: core tests run against the raw protocol, and each binder has its own tests.

The WebSocket push message (`{"refresh": [ids]}`) is likewise library-neutral: the htmx binder turns it into `htmx.trigger(el, 'zeal:refresh')`, and the vanilla client calls its own refetch.

## Push: SSE and WebSockets, one model (phase 3, revised 2026-11-09)

Both transports carry the same thing, so an app picks the transport per deployment, not per component. A component declares what data it depends on:

```php
final class ContactList extends Component
{
    public function topics(): array { return ['contacts']; }
}

// in any handler, after a write:
Component::invalidate('contacts');
```

### What travels

| Mode | Message | Who renders | When to use |
|---|---|---|---|
| **Refresh signal** (default, both transports) | the ids of stale components | the tab, by re-requesting `_refresh` with its current snapshot, session and auth | always correct: the tab's own snapshot is the latest state |
| **Rendered HTML** (SSE only, opt-in per component) | `<hx-partial hx-target="#id">…</hx-partial>` per stale component | the server, inside the tab's own SSE request | components whose state the browser can't change (no `#[Model]`, no actions that mutate props): notification lists, dashboards, feeds |

Neither mode ever sends one user's view to another user. The signal carries ids only. Rendered HTML is produced inside the subscriber's own request, which has that visitor's session and auth, never in the request that called `invalidate()`. A component that renders the same for everyone (`#[Shared]`) may later render once per topic and fan the same HTML out.

### Transports

| | SSE | WebSocket |
|---|---|---|
| Connection | one `/_zeal/c/stream` request per tab, opened by a push root the layout renders: `<?= App::componentPush() ?>` | one `/_zeal/c/ws` socket per tab |
| Client | htmx 4: the `hx-sse` extension, `hx-sse:connect` (fetch, so the subscription can be a POST body; automatic reconnect 500 ms → 60 s with jitter). htmx 2: `htmx-ext-sse` (EventSource, GET, so subscribe first and connect with a short token) | a ~1 KB script (both htmx versions) |
| Auth | the request's own session cookie, normal middleware | the upgrade request's session; re-checked on subscribe |
| Rendered-HTML mode | yes: the stream runs in the visitor's request context | no: the socket runs outside a request; signal only |
| Best for | push-only updates, simplest deploy, works through every proxy that passes streaming responses | two-way traffic (presence, typing indicators, collaborative edits), very many tabs per server |

`App::componentPush('sse' | 'ws')` picks the transport (default `sse`); the push root renders the matching attributes or script.

### Subscription

1. The push root opens the connection and sends the **signed snapshots** of the components on the page (htmx 4: in the POST body; htmx 2 and WebSockets: `POST /_zeal/c/subscribe` first, which answers a short-lived subscription token).
2. The server verifies each snapshot, as the endpoint does, and derives the topics from the components' `topics()`. The client never names a topic, so it can't subscribe to `contacts:42` without having been shown a component that depends on it. `WSRouter::roomAuthorizer()` is the extra hook for WebSockets; an `authorizeTopic()` hook covers both.
3. When the user acts on a component, its new snapshot replaces the subscribed one (the endpoint's response carries it, and the push root re-sends it), so rendered-HTML mode renders from the latest state.

### Fan-out

`invalidate($topic)` has to reach every connection subscribed to the topic, wherever it lives:

| Scope | Mechanism |
|---|---|
| Same worker | per-worker registry `topic → [connection]` (each SSE stream is a coroutine; each socket an fd) |
| Other workers, same node | OpenSwoole `sendMessage()` to every worker (`onPipeMessage`); WebSockets can also `$server->push()` to any fd directly. No Redis needed |
| Other nodes | `Store::publish('zc:' . $topic)` with one `App::subscribe('zc:*')` per worker (Redis backend); WebSockets can use the existing federated `WSRouter::room()` |

### Ordering, bursts and backpressure

- Invalidation is idempotent, so coalesce: at most one message per topic per tick (~50 ms) per connection.
- SSE: a stream whose output buffer is over a limit drops its pending messages and sends one "refresh everything" signal; WebSockets do the same with the socket buffer.
- Reconnect: the client re-subscribes with its current snapshots and refreshes every mounted component. Correct and simple; add version stamps only if measurements say so.
- A held SSE connection costs one coroutine and its buffers, not a worker; cap connections per worker (`App::componentPush(maxConnections: …)`) so streams can't starve request handling.

## Isolation in long-running workers

- A component instance exists for one request and is never stored in a class static, a `Store` row or a global.
- The registry (name → class, reflected action metadata) is built at boot in the master and is read-only afterwards — COW-shared, same pattern as the route table.
- Component classes are preloaded at boot (`App::preloadClasses()` from `App::components()`), which avoids the coroutine-legacy cold-concurrent autoload race.
- The per-tab subscription is a `Store` row keyed by fd with a `conn_id` nonce, the same FD-reuse protection `WSRouter` uses. It's removed on close.
- A test fires concurrent actions from many sessions and asserts that no response contains another session's props. It's modelled on `TrustBarIsolationTest` and runs in coroutine and coroutine-legacy modes.

## Phases

| Phase | Scope | Done when |
|---|---|---|
| **1. Core** | Wire protocol, `ComponentBinder` + `HtmxBinder` + `FormBinder`, `Component` base, `#[Action]`, `#[Model]` input binding with validation, registry (`App::components()`), `App::component()`, `/_zeal/c` endpoint, signed snapshots, `ZEALPHP_APP_KEY` + `key:generate` + rotation, morph swaps, no-JS form fallback | Counter, todo-list and signup-form examples work with and without JS; focus survives live updates; tests for tampering, cross-session replay, version skew, unknown component/action/prop, writes to non-`#[Model]` props, type coercion, CSRF, concurrent cross-user isolation; PHPStan 10 |
| **2. Vanilla client + benchmark** | `ZealJsBinder` and its ~2 KB client; compare a component action against `renderHtmx()` on a page with expensive pre-fragment work (#509 item 3) | The signup example works identically with `HtmxBinder` and `ZealJsBinder`; published numbers: latency p50/p99, snapshot size, CPU per request |
| **3. Push** | `Component::invalidate()`, topics, snapshot-derived subscriptions, SSE transport (htmx 4 `hx-sse` extension, htmx 2 `htmx-ext-sse`) and WebSocket transport, refresh-signal and rendered-HTML modes, worker/node fan-out, coalescing, backpressure, reconnect | Live list example updating across tabs and users; tests for subscription authorization, fd reuse, reconnect; fan-out benchmark for 1k / 10k subscribers, memory per connection |
| **4. Composition** | Child components, out-of-band updates of other components from one action, component events — design and decisions: [component composition](2026-11-09-component-composition.md) | Nested list/row example; tests that a parent re-render keeps child identity and child input state |
| **5. Maybe** | `#[Shared]` HTML broadcast; stateful connection mode (design B) | Only if phase 2–3 benchmarks justify it |

## Decisions

1. **Signing key — decided:** `ZEALPHP_APP_KEY` with HKDF-derived snapshot key and rotation (see "Signing key").
2. **Input binding — decided:** yes, form-level, allow-listed `#[Model]` props (see "Input binding").
3. **htmx — decided:** not a hard requirement. The core speaks a plain-HTTP protocol; htmx gets a first-class `HtmxBinder` (see "Transport").
4. **Naming:** `Component` / `/_zeal/c`, or something else?

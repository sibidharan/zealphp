# Server-driven components — design plan (#509, phase 5)

Status: **proposal**. Nothing here is implemented yet. Phases 0–1 of #509 (the `renderHtmx()` fix, `e()`/`Escape`, documented `render()` echo semantics) are landed separately.

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
| Replaying another user's snapshot | Snapshot binds a hash of the session id; mismatch → 403 |
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

## Push: invalidation, not HTML

A component declares what data it depends on:

```php
final class ContactList extends Component
{
    public function topics(): array { return ['contacts']; }
}

// in any handler, after a write:
Component::invalidate('contacts');
```

1. When a page with components loads, a ~1–2 KB script opens one WebSocket per tab and sends the **signed snapshots** of the components on the page. The server derives topics from those — the client never names a topic itself, so it can't subscribe to `contacts:42` without having been shown a component that depends on it. `WSRouter::roomAuthorizer()` is the hook for extra checks.
2. `invalidate('contacts')` pushes a tiny message: `{"refresh":["contactlist-main"]}`. **No HTML, no data.**
3. The client fires `htmx.trigger(el, 'zeal:refresh')`; the component refetches through the normal HTTP endpoint with that user's own session and auth.

Pushing invalidation rather than rendered HTML costs one extra round trip per update. In exchange, a broadcast can never carry one user's view to another user, and auth, CSRF and middleware aren't duplicated on the WS path. Later, a component explicitly marked `#[Shared]` (renders the same for everyone, e.g. a public stock ticker) may broadcast its rendered HTML once to all subscribers.

**Transport per deployment:**

| Deployment | Fan-out |
|---|---|
| Single node, Table backend | A `Store` table `topic → fd` + `$server->push($fd)` — OpenSwoole lets any worker push to any WS fd on the same server, so no Redis is needed. `WSRouter::room()` requires Redis today, so this is a new local path |
| Multi-node, Redis backend | `WSRouter::room('zc:' . $topic)` — federated rooms already exist |

**Ordering, bursts and backpressure:**

- Invalidation is idempotent, so coalesce: at most one push per topic per tick (~50 ms) per connection.
- If a connection's output buffer is over a limit, drop its pending messages and mark it stale; it refetches everything on its next message or reconnect.
- Concurrent actions on one component: `hx-sync="this:queue all"` serializes them in the client. Across users the database is the source of truth; snapshot props are a view, not authority.
- Reconnect: the client refetches every mounted component. Simple and correct; optimize with per-component version stamps only if it measures as a problem.

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
| **3. Push** | WS client script, snapshot-derived subscriptions, `Component::invalidate()`, local Table fan-out, Redis room fan-out, coalescing, backpressure, reconnect | Live list example updating across tabs and users; tests for subscription authorization, fd reuse, reconnect; fan-out benchmark for 1k / 10k subscribers, memory per connection |
| **4. Composition** | Child components, out-of-band updates of other components from one action, component events | Nested list/row example; tests that a parent re-render keeps child identity and child input state |
| **5. Maybe** | `#[Shared]` HTML broadcast; stateful connection mode (design B) | Only if phase 2–3 benchmarks justify it |

## Decisions

1. **Signing key — decided:** `ZEALPHP_APP_KEY` with HKDF-derived snapshot key and rotation (see "Signing key").
2. **Input binding — decided:** yes, form-level, allow-listed `#[Model]` props (see "Input binding").
3. **htmx — decided:** not a hard requirement. The core speaks a plain-HTTP protocol; htmx gets a first-class `HtmxBinder` (see "Transport").
4. **Naming:** `Component` / `/_zeal/c`, or something else?

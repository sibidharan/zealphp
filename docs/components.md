# Components

ZealPHP builds pages from components, and one call renders both kinds:

```php
<?= App::component('card', ['title' => 'Hello']) ?>      // stateless: a template with props
<?= App::component(Counter::class, ['count' => 5]) ?>     // reactive: state, actions, input binding
```

- A **stateless component** is a PHP template in `template/components/`. Same props in, same HTML out — like a React function component without hooks.
- A **reactive component** is a PHP class. Public properties are its **state**, `#[Action]` methods are what buttons **call**, and `#[Model]` properties **bind** to form inputs, with validation. The server owns the state and renders HTML; the browser swaps it in. There's no client-side framework and no JSON API to keep in sync, and pages still work with JavaScript turned off.

Start stateless; make a component reactive when it needs to respond to the user. Live demo: [`/components`](/components). For `App::render()`, streaming and fragments, see [Templates and Rendering](templates-and-rendering.md).

## Stateless components

Put a template in `template/components/` and render it by name:

```php
<?php /* template/components/card.php */ use function ZealPHP\e; ?>
<article class="card">
  <h3><?= e($title) ?></h3>
  <?= $slot ?? '' ?>
</article>
```

```php
<?= App::component('card', [
    'title' => 'Invoices',
    'slot'  => App::component('ui/button', ['text' => 'New invoice']),
]) ?>
```

- **Lookup:** `'card'` renders `components/card.php`, else `components/_card.php` (so existing underscore partials work as they are). `'ui/button'` looks in `components/ui/`. Names are lowercase letters, digits, `-` and `_`, with `/` for folders.
- **Any template:** a leading `/` renders any template, absolute from `template/`: `App::component('/emails/welcome', ['user' => $u])`. This is how you get markup from a template that isn't a component; it replaces the deprecated `App::renderToString()`.
- **Props** become template variables; `$props` holds them all. Pass child markup as `slot` — an `App::component()` result or `Escape::raw()` is printed as-is, anything else should go through `e()`.
- **Returns markup, never echoes**, so `<?= ?>` is correct. A route can `return App::component(...)` too: the response is the HTML. `App::render()` is for whole pages; see [Templates and Rendering](templates-and-rendering.md).
- **Registered names win.** If a reactive component is registered as `card`, `App::component('card')` renders that.
- Stateless components can be used anywhere, including inside reactive components. They have no state, so they need no key and no `ZEALPHP_APP_KEY`.

## Reactive components

State travels in the page as a signed snapshot. Each request builds a fresh component from it, so workers keep nothing between requests — no per-user memory, nothing to leak between users, nothing lost when a worker restarts. The rest of this guide is about reactive components.

## Setup

Reactive components sign their state with `ZEALPHP_APP_KEY`. Generate one and export it:

```bash
php app.php key:generate
# ZEALPHP_APP_KEY=base64:3q2+7w...
export ZEALPHP_APP_KEY=base64:3q2+7w...
```

Register your components before `App::run()`, in `app.php` or a `route/*.php` file:

```php
use App\Components\{Counter, SignupForm};

App::components([Counter::class, SignupForm::class]);
```

The name defaults to the kebab-case class name (`SignupForm` → `signup-form`). To choose it, pass `'name' => Class::class`. Only registered components can be reached from the browser.

If reactive components are registered and there's no key, `php app.php` refuses to start. In dev mode (`php app.php --dev` or `ZEALPHP_DEV=1`) it uses a random key for that boot instead, and open pages need a reload after a restart.

The component templates use htmx attributes by default, so the page needs htmx 2 loaded. See [Clients](#clients) to use something else.

## A first component

```php
namespace App\Components;

use ZealPHP\Component\{Action, Component};

final class Counter extends Component
{
    protected string $tag = 'form';   // the outer element; 'form' also works without JS

    public int $count = 0;

    #[Action]
    public function add(int $by = 1): void
    {
        $this->count += $by;
    }

    public function render(): mixed
    {
        return $this->view('components/counter');
    }
}
```

```php
<?php /* template/components/counter.php */ use function ZealPHP\e; ?>
<span><?= e($count) ?></span>
<button <?= $c->action('add') ?>>+1</button>
<button <?= $c->action('add', ['by' => 10]) ?>>+10</button>
```

Put it on a page:

```php
<?= App::component(Counter::class, ['count' => 5], key: 'main') ?>
```

The template is only the inside of the component. The framework wraps it in the `$tag` element (with `id="counter-main"` and `data-zc="counter"`) and adds a hidden `_zc` input holding the signed snapshot.

`App::component()` **returns** the markup and never echoes it, so `<?= ?>` is correct. That's unlike `App::render()`; see the echo side effect described in [Templates and Rendering](templates-and-rendering.md).

### What templates receive

`$this->view($template, $extra = [])` renders `template/{$template}.php` with:

| Variable | Value |
|---|---|
| each public property | its current value (`$count`) |
| `$c` | the component, for the helpers below |
| `$errors` | `field => message` validation errors |
| anything in `$extra` | as given |

The template path is always absolute from `template/`, so the page and the endpoint render the same file.

### Keys and ids

The instance id is `{name}-{key}`; it's also the outer element's DOM id. Without a key, instances are numbered per request (`counter-1`, `counter-2`). Give a stable `key:` when the same component appears more than once, or when it must keep its state across a no-JS form submission.

## State

Public properties declared on your class are the state. Each must have one declared type:

- `string`, `int`, `float`, `bool`
- `array` — of scalars and nested arrays (up to 4 levels)
- a backed enum
- any of these nullable (`?int`)

Anything else — objects, union types, `mixed`, untyped — is refused when you register the component, so the browser can never cause an object to be constructed. Names `c`, `errors`, and names starting with `_` are reserved.

**State is signed, not encrypted.** Anyone who can see the page can read it. Keep ids in state and load the records in `render()`:

```php
final class InvoiceRow extends Component
{
    public int $invoiceId = 0;

    public function render(): mixed
    {
        return $this->view('invoices/row', ['invoice' => Invoice::find($this->invoiceId)]);
    }
}
```

Protected and private properties are not state; they're reset on every request. Use them for configuration (`$tag`, `$submit`, `$attributes`) or per-request caches.

### Lifecycle

| Hook | When |
|---|---|
| `mount()` | Once, when `App::component()` first places the component, after initial props are set |
| `hydrate()` | On every endpoint request, after state is restored, before input is applied |
| `updated{Prop}($value)` / `updated($prop, $value)` | After a `#[Model]` property changes from input (define them `protected` or `public`) |
| `authorize(string $action): bool` | Before input is applied; return false to answer 403 |
| `render()` | Every time the component is displayed |

`authorize()` receives the action name, or `_update` / `_refresh` for input-only requests.

## Actions

Mark a public method `#[Action]` to let the browser call it. `$c->action('name', $args)` prints the attributes for a button:

```php
#[Action]
public function move(int $position, Direction $direction = Direction::Up): void { /* ... */ }
```

```php
<button <?= $c->action('move', ['position' => $i, 'direction' => 'down']) ?>>Down</button>
```

Arguments are bound by parameter name and converted to the declared type; a value that doesn't convert, or a missing required argument, is a 400. Parameter types follow the same rules as state. Methods without `#[Action]` — including `render()`, `mount()` and anything inherited from `Component` — answer 404.

Inside an action:

| Call | Effect |
|---|---|
| `$this->validate()` | Validate `#[Model]` fields; on failure, stop the action and re-render with errors |
| `$this->addError($field, $msg)` / `clearErrors()` | Set or clear errors directly |
| `$this->dispatch('event', $detail)` | Fire a browser event after the swap (htmx: `HX-Trigger`) |
| `$this->redirect('/path')` | Navigate after the action (same-site paths only) |

`$submit` names the action that runs when the component's form is submitted (Enter key, or a `<button type="submit">` with no `action()`):

```php
protected string $tag = 'form';
protected ?string $submit = 'save';
```

## Input binding

Mark a property `#[Model]` to bind it to a form control, and use `$c->model('prop')` on the control:

```php
#[Model(rules: 'required|email')]
public string $email = '';

#[Model(live: true, rules: 'required|min:3')]
public string $username = '';

#[Model(blur: true)]
public int $age = 0;
```

```php
<input <?= $c->model('email') ?>>
<?= $c->error('email') ?>
```

`model()` prints `name`, `id` (`{instance id}-{prop}`), the current `value`, `aria-invalid`/`aria-describedby` when the field has an error, and the client attributes for live or blur fields.

| Option | When the value is sent |
|---|---|
| (default) | With the next action |
| `live: true` | As you type, after `debounce` ms (default 300) |
| `blur: true` | When the field changes and loses focus |

A live or blur change posts to the component's `_update` endpoint: input is applied, **only the changed fields are validated**, and the component re-renders. Errors stay on fields until they're fixed. The control you're typing in is left untouched by the swap (htmx `hx-preserve`), so focus, cursor and in-flight keystrokes survive.

Only `#[Model]` properties accept input, and only those the last render printed a control for. Everything else in the request is ignored — including values for real properties that have no `#[Model]`.

### Controls other than text inputs

| Control | Template |
|---|---|
| `<textarea>` | `<textarea <?= $c->model('bio', false) ?>><?= $c->value('bio') ?></textarea>` |
| `<select>` | `<select <?= $c->model('plan', false) ?>><option value="pro" <?= $c->selected('plan', 'pro') ?>>Pro</option></select>` |
| checkbox (`bool`) | `<input type="checkbox" <?= $c->model('terms') ?> <?= $c->checked('terms') ?>>` |
| checkbox group (`array`) | `<input type="checkbox" <?= $c->model('tags') ?> value="php" <?= $c->checked('tags', 'php') ?>>` |
| radio | `<input type="radio" <?= $c->model('size', false) ?> value="l" <?= $c->checked('size', 'l') ?>>` |

An unchecked checkbox sends nothing, so a missing `bool` field means `false` and a missing `array` field means `[]`. Checkbox groups get no `id` (it would repeat).

### Conversion errors

A submitted value that can't convert to the property's type (`"old"` for an `int`) becomes a field error ("Age must be a whole number."). The control shows back what the user typed, the property keeps its last valid value, and **no action runs** in that request.

## Validation

Rules come from `#[Model(rules: …)]` and from an optional `rules()` method, which wins for the same field:

```php
protected function rules(): array
{
    return [
        'terms' => 'accepted',
        'code'  => ['required', fn($v) => $v === 'zeal' ? null : 'Wrong code.'],
    ];
}

protected function messages(): array
{
    return ['terms.accepted' => 'Please accept the terms.', 'required' => ':field is needed.'];
}
```

| Rule | Passes when |
|---|---|
| `required` | not `null`, `''` or `[]` |
| `accepted` | `true`, `1`, `'1'`, `'on'`, `'yes'` |
| `email`, `url` | a valid email / an http(s) URL |
| `min:n`, `max:n`, `between:a,b` | string length, number value, or array count is in range |
| `in:a,b`, `not_in:a,b` | the value is / isn't one of these |
| `regex:/pattern/` | matches (put it last, or use the list form if the pattern has `\|`) |
| `numeric`, `integer` | a number / a whole number |
| `alpha`, `alpha_num`, `alpha_dash` | letters / plus digits / plus `-` and `_` |
| closure `fn($value, $field)` | returns `null` (an error message string fails) |

Every rule except `required` and `accepted` skips empty values, so `'email'` allows an empty field and `'required|email'` doesn't. `:field` in a message becomes the field's label (`first_name` → "First name").

`$c->error('field')` prints `<span class="zc-error" role="alert">` with the message, or nothing. `$c->hasError()` and `$c->errors()` are there for custom markup.

## Concurrency in the browser

With `HtmxBinder`:

- **An action always goes out.** If a live update is still in flight on the same component, the action replaces it (the action carries every field anyway).
- **The clicked button is disabled until its response arrives**, so a double-click can't re-send the state the first click started from. Clicking a *different* action while one is in flight replaces the first one's response: the result is computed from the state the page showed.
- **Live updates queue** behind one another, and the field being typed in is kept across the swap (htmx 2: `hx-preserve`; htmx 4: morphing). If you type in field B while field A's update is in flight, B is re-rendered from the server's copy when A's response lands; its next keystroke sends it again.

Elements inside a `$tag = 'form'` component inherit its `hx-target` and `hx-sync`. If you add your own `hx-get` inside a component, set `hx-target` on it explicitly, or its response replaces the whole component.

## Without JavaScript

With `$tag = 'form'`, every component is a real form: action buttons are submit buttons with `formaction`, and the form's `action` is the `$submit` action. A plain form post runs the action, stores the resulting snapshot in the session, and answers `303` back to the page (the `Referer`, if it's on the same host). The next `App::component()` with the same key picks the stored state up once.

Use a stable `key:` for components that must work this way; numbered keys can shift between renders. The round trip needs an active session (register `SessionStartMiddleware`): without one the action still runs, but the page after the redirect shows the old state.

**Pressing Enter** submits a form with its *first* submit button. When `$submit` is set, the framework puts a hidden submit button for it first in the form, so an action button earlier in your template doesn't run instead.

With `FormBinder` and the default `$tag = 'div'`, action buttons sit outside any form and do nothing without JavaScript. Set `$tag = 'form'` for components that must work without it — but don't put a form component inside another `<form>` (nested forms aren't valid HTML).

## Security

| The browser tries to… | Result |
|---|---|
| Edit state in the snapshot | 400 — the HMAC doesn't match |
| Use another visitor's snapshot | Refused like a stale snapshot (the page reloads and shows the visitor their own state) — snapshots are bound to the session with an HMAC of the session id, never the id itself |
| Use a snapshot from before a deploy that changed the class, or older than `App::componentMaxAge()` (default 24 h) | htmx: `HX-Refresh: true` (page reloads); protocol: 409 |
| Re-send an older snapshot of their own | **Accepted** until it expires — see below |
| Call a method without `#[Action]` | 404 |
| Write a property without `#[Model]`, or one the page didn't render | ignored |
| Send an object or a value of the wrong type | 400 for action arguments; a field error for input |
| Post from another site | 403 — `Sec-Fetch-Site` must be `same-origin`, or `Origin` must match `Host` |
| Name a class to instantiate | impossible — only registered names are looked up |

### Replay: signed isn't the same as latest

A snapshot proves that this session was shown that state; it doesn't prove it's the newest one. A visitor can re-send an earlier snapshot of their own (until it expires) and get the earlier state back. That's harmless for UI state — a counter, a form, a selected tab — but **never use component state as the record of something that happened**: votes cast, attempts used, coupons claimed, a wizard step that grants something. Keep those in the database or the session and read them in `render()` or the action. `App::componentMaxAge(seconds)` bounds how long a snapshot can be re-sent (default 86400; 0 = no limit).

### Without a session

Snapshots are bound to the PHP session. When no session is active (no `SessionStartMiddleware` and no `PHPSESSID` cookie yet, or an app that authenticates with tokens instead), snapshots aren't bound to anyone. The endpoint then refuses requests that carry neither `Sec-Fetch-Site` nor `Origin`, so cross-site requests can't use them, but any snapshot works for any sessionless visitor. Start a session (`SessionStartMiddleware`) for anything user-specific. A session that starts or is regenerated after a page was rendered (login) makes that page reload on its next component request.

Things to do yourself:

- **Authorize.** The snapshot proves *this session* rendered *this state*; it doesn't prove the user may act on it. Check permissions in `authorize()` or in the action, and scope queries by the current user. Middleware on the endpoint works too: `App::when('/_zeal/c', ['auth'])`.
- **No secrets in state.** It's readable in the page source.
- **Escape in templates.** Use `e()` for values you print (see [Escaping output](templates-and-rendering.md#escaping-output)); the helpers escape everything they print.

### Rotating the key

Move the old key to `ZEALPHP_APP_KEY_PREVIOUS` (comma-separated for several) and set the new one. Snapshots signed with the old key keep working; new ones use the new key. Remove the old key once open pages have had time to expire.

The raw key never signs anything directly: each use derives its own key with HKDF, so a snapshot signature can't be reused for another purpose.

## Clients

The endpoint speaks plain HTTP. A **binder** decides which attributes `action()` and `model()` print and how side effects are signalled:

| Binder | Prints | Use |
|---|---|---|
| `HtmxBinder` (default) | `hx-post`, `hx-target`, `hx-include`, `hx-sync`, `hx-preserve`, plus the plain-form attributes | Pages with htmx 2 |
| `FormBinder` | Plain-form attributes only | Pages with no JavaScript |
| your own | anything — implement `ZealPHP\Component\ComponentBinder` | Alpine, Datastar, a custom `fetch()` client |

### htmx 2 and htmx 4

`HtmxBinder` supports both. The setting decides the attributes of the first page render; after that, every component request says which htmx sent it (htmx 4 adds `HX-Request-Type`), and the response uses that version's dialect. htmx 2 is the default setting (it's still npm's `latest`); pass `htmx: 4` if your pages load htmx 4:

```php
App::componentBinder(new HtmxBinder());            // htmx 2.x
App::componentBinder(new HtmxBinder(htmx: 4));     // htmx 4.x
App::componentBinder(new HtmxBinder(morph: true)); // htmx 2 + the idiomorph extension
```

| | htmx 2 | htmx 4 |
|---|---|---|
| Swap | `outerHTML` (`morph:outerHTML` with `morph: true`) | built-in `outerMorph`, always |
| Keeping the field being typed in | the response marks it `hx-preserve` | morphing never overwrites the focused input's value |
| Disabling the clicked button | `hx-disabled-elt="this"` | `hx-disable="this"` |
| Refused requests (400/403/404) | not swapped (htmx 2 default) | `HX-Reswap: none` stops htmx 4 swapping the error text |

Every attribute is set on the element that needs it, so htmx 4's explicit attribute inheritance doesn't affect components. The two dialects differ in attribute names (`hx-disable` means "ignore this element" in htmx 2), so pick the one matching the htmx on your pages. `HtmxBinder` answers validation errors with 200, maps events to `HX-Trigger`, redirects to `HX-Redirect`, and stale snapshots to `HX-Refresh`.

### Client-only state: hx-live, Alpine

Some state never needs the server: a dropdown being open, a character counter, a tab. Keep that in the browser with htmx 4's `hx-live` (or Alpine.js) inside a component's template:

```html
<textarea <?= $c->model('bio', false) ?>><?= $c->value('bio') ?></textarea>
<small :text="q('previous textarea').value.length + ' / 280'"></small>
```

Client-side state lives on DOM nodes, so it survives a re-render only where the swap keeps those nodes — always the case with htmx 4's morphing; with htmx 2's `outerHTML` it resets on each update. Anything the server must know belongs in a `#[Model]` property.

### Protocol

For writing a binder or a client.

**Request** — `POST /_zeal/c/{name}/{action}`, form-encoded (`application/x-www-form-urlencoded` or `multipart/form-data`):

| Field | Value |
|---|---|
| `_zc` | the snapshot from the component's hidden input |
| `_zc_args` | action arguments as a JSON object (`{"by": 2}`); omit for none |
| `#[Model]` fields | as a form would send them (`tags[]` for arrays) — send **every** bound control in the component, not just the changed one: a missing `bool` means unchecked and a missing `array` means empty, so a partial post clears them |

`{action}` is an `#[Action]` method name, `_update` (apply input and validate changed fields), or `_refresh` (apply input, re-render). For `_update`, name the field that changed in a `_zc_trigger` form field (the htmx binder does this); a `Zeal-Trigger` or `HX-Trigger-Name` header also works. Scripted clients send `Zeal-Request: 1`.

**Response:**

| Status | Meaning | Body |
|---|---|---|
| 200 | OK | the re-rendered component (outer element included) |
| 422 | validation errors | the re-rendered component showing them |
| 303 | plain form post (no `Zeal-Request` / `HX-Request`) | — `Location` is the page |
| 400 | malformed or tampered snapshot, bad arguments | text |
| 403 | cross-origin, sessionless without an origin header, or `authorize()` refused | text |
| 404 | unknown component or action | text |
| 409 | snapshot from another version, another session, or expired (or signed before a dev-mode restart) — reload the page | — |

Headers: `Zeal-Events` (JSON object of event → detail), `Zeal-Redirect` (a path to navigate to), `Zeal-Reload: 1` (with 409). Every response is `Cache-Control: no-store`.

## Live updates (push)

A component can stay current while it's on screen: when data it shows changes, every open page showing it re-renders it.

```php
final class SharedLikes extends Component
{
    public static ?Counter $likes = null;      // created in route/ before run()

    public function topics(): array
    {
        return ['demo:likes'];                 // what this component depends on
    }

    #[Action]
    public function like(): void
    {
        self::$likes?->increment();
        Component::invalidate('demo:likes');   // after the write
    }

    public function render(): mixed
    {
        return $this->view('components/live/likes', ['likes' => self::$likes?->get() ?? 0]);
    }
}
```

Add the push root once to your layout, after the components:

```php
<?= App::componentPushRoot() ?>
</body>
```

It renders a `<script>` only when the page rendered a component with topics. Call `Component::invalidate()` from anywhere that changes the data — an action, an API handler, a task worker, a sidecar process, a timer.

### How it works

1. While rendering, each reactive component's `topics()` are recorded. The push root signs the list into a token bound to the visitor's session, so a page can only subscribe to topics it was actually shown.
2. The page opens one connection: a WebSocket (`/_zeal/c/ws`) or an SSE stream (`/_zeal/c/stream`).
3. `Component::invalidate('demo:likes')` delivers to this worker's connections, sends the topics to the node's other workers (OpenSwoole `sendMessage`), and, with the Redis or Tiered `Store` backend, publishes them to other nodes. From a task worker or a sidecar process it reaches every HTTP worker.
4. A connection receives only the **ids** of its stale components, never HTML or data. The page then re-requests each one through the normal endpoint (`_refresh`), with its own cookies, so each visitor sees what *their* session is allowed to see.

Invalidations to one connection are coalesced (50 ms by default), so a burst of writes costs one refresh, and what a worker invalidates within a tick goes to the other workers and nodes as one message. The page skips a component while an htmx request from it is running (an action's response already carries its new state) or while the visitor is typing in it, and runs at most one refresh per component at a time. After a reconnect it refreshes all its components, because invalidations may have been missed.

### Configuration

```php
App::componentPush(transport: 'sse', maxConnections: 2000, coalesce: 0.1);   // before App::run()
```

| Setting | Default | |
|---|---|---|
| `transport` | `'ws'` (or `ZEALPHP_COMPONENT_PUSH=sse`) | `'ws'`: one WebSocket per page. `'sse'`: one streamed response per page. |
| `maxConnections` | 1000 | open push connections per worker; beyond it a WebSocket is closed with 1013 (retry later) and an SSE stream asks the browser to come back in 15–45 s |
| `coalesce` | 0.05 | seconds to gather invalidations into one message |

**WebSocket or SSE.** WebSockets are the default because browsers allow only six HTTP/1.1 connections per host: with SSE, each open tab holds one, so a visitor's seventh tab of your site would stall. Use SSE over HTTP/2 (one connection carries every stream) or where a proxy blocks WebSockets. SSE holds a coroutine per open page and needs coroutine mode; without coroutines the push root uses the WebSocket regardless.

Both reconnect on their own: SSE after a jittered 1–4 s, the WebSocket client with backoff (0.5 s to 60 s, jittered). A stream ends when the browser leaves (noticed at the next 15 s heartbeat) and after an hour. Navigating with hx-boost to a page without the components closes the connection.

### Topics

Topic names are 1–128 characters of letters, digits, `:`, `_`, `.` and `-` (`contacts`, `contact:42`, `org.7.billing`). They're coarse on purpose: a topic says *something here changed*, and the re-render decides what the visitor sees. Use a topic per record (`contact:42`) when a list of many items would otherwise refresh for every edit.

- **Topics are fixed when the page renders.** A component whose topics depend on state that its actions change (`contacts:page:2` after paging) keeps the topics it was first rendered with until the page reloads. Prefer topics that don't change for an instance.
- **Topics are visible.** They're in the page (the token is signed, not encrypted) and in the access log (the token travels in the connection URL). Don't put secrets in them; `user:42` is fine, an email address isn't.
- An invalid topic throws in dev mode and is logged and dropped otherwise, so a bad topic built from data doesn't break the page.

### Security

- The token is HMAC-signed with a key derived from `ZEALPHP_APP_KEY` and bound to the session. A token from another session, or signed with a key no longer configured, is refused; the page then refreshes its components, which reload the page when their snapshots are stale too.
- Tokens don't expire on their own: a push carries only ids, and every refresh re-checks the snapshot's age and `authorize()`.
- Pushes carry ids only, and the refresh is an ordinary component request: `authorize()`, the session binding and the origin checks all apply. A refresh that's redirected (to a login page, say) is not swapped in.
- `Component::invalidate()` is server-side only; clients can't trigger one.
- **Capacity.** `maxConnections` is per worker, and a page's token can be reused for many connections. On a public site, cap connections per client in front of ZealPHP (your proxy's per-IP connection limit on `/_zeal/c/ws` and `/_zeal/c/stream`).

## Running in coroutine-legacy

`App::components()` loads and reflects your component classes — and the framework's own component classes — in the master, before workers fork, so none of them is compiled cold under concurrency. Instances live for one request and are never stored in statics, so they need no isolation support.

## Limits in this version

- **Push needs the root on the page.** Pages that don't include `App::componentPushRoot()` render components normally but never receive updates.
- **No reactive nesting.** A reactive component inside another reactive component's render throws: the parent's requests would carry the child's snapshot too. Stateless components nest freely.
- **Dev hot-reload** (`--dev`) re-registers components from `route/*.php`, but the endpoint route and the key check are set up at boot: if a reload adds the *first* component, restart. Removed components stay registered until restart.
- **Behind a proxy that rewrites `Host`**, the `Origin` fallback and the no-JS back-link compare against the rewritten host. Modern browsers send `Sec-Fetch-Site`, which isn't affected; the back-link falls back to `/`.

## What's next

Planned in [the design doc](architecture/2026-11-06-server-driven-components.md): a bundled vanilla-JS client for actions and input binding, nested components, and benchmarks against `render(…, htmx: true)` fragments.

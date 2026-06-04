# HTMX

ZealPHP treats [htmx](https://htmx.org) as a first-class citizen. The model is the opposite of a SPA: **the server returns HTML**, and htmx swaps it into the page over AJAX without a full reload. You write routes that return markup; htmx wires up the interactivity from HTML attributes. There is no client-side framework, no JSON-to-DOM glue, no build step.

This guide covers the full ZealPHP htmx surface: reading the htmx request headers, driving the htmx client from the response, the `App::renderHtmx()` fragment selector, out-of-band swaps, the boosting model, where htmx ends and SSE/WebSocket begin, and the CSRF pattern.

> The demo site (this repository) uses htmx globally — every page is hx-boosted. The `/learn/htmx` lesson is a gentle, narrative introduction; this guide is the reference.

## Overview — server returns HTML

A normal AJAX setup returns JSON and rebuilds the DOM in JavaScript. htmx inverts that: an element declares *where* a request goes and *what* to do with the HTML that comes back.

```html
<form hx-post="/items" hx-target="#list" hx-swap="afterbegin">
  <input name="item" placeholder="New item">
  <button type="submit">Add</button>
</form>
<ul id="list"><!-- new <li> rows get inserted here --></ul>
```

The route just returns the new row's HTML:

```php
$app->route('/items', methods: ['POST'], handler: function ($request) {
    $item = Item::create($request->post['item']);
    return "<li>" . htmlspecialchars($item->name) . "</li>";
});
```

**Progressive enhancement.** Because the markup is real HTML — a real `<form action>` / `<a href>` underneath the htmx attributes — the same page still works with JavaScript disabled. htmx is an enhancement layer, not a hard dependency.

## Setup out of the box

The demo app's `template/_master.php` wires htmx for every page:

```php
<body hx-boost="true" hx-ext="head-support">
```

and `template/_head.php` loads the libraries:

| Library | Version | Role |
|---------|---------|------|
| `htmx.org` | **2.0.10** | The core library. |
| `htmx-ext-head-support` | **2.0.4** | Reconciles `<head>` on boosted navigation (hx-boost swaps `<body>` + `<title>` but not `<head>`, so per-page CSS/JS modules wouldn't otherwise load when you navigate *into* a page). |

With `hx-boost="true"` on `<body>`, **every** `<a>` and `<form>` on the page is automatically AJAX-ified: htmx intercepts the navigation, fetches the target URL, swaps the `<body>`, updates the `<title>`, and manages browser history — all without a full reload.

**Boosted vs plain requests.** A boosted navigation sends `HX-Boosted: true` (in addition to `HX-Request: true`); a request from an explicit `hx-get`/`hx-post` attribute sends `HX-Request: true` but not `HX-Boosted`. A normal full-page load (typing a URL, hard refresh) sends neither. That distinction is what lets a handler decide between a full page and a partial — see [Reading the request](#reading-the-request).

## Reading the request

`ZealPHP\HTTP\Request` (the `$request` injected into every handler) exposes eight accessors, one per htmx request header. Each reads the lower-cased header from the OpenSwoole request and returns `null` (or `false`, for the booleans) when absent.

| Accessor | Reads header | Returns | Meaning |
|----------|--------------|---------|---------|
| `isHtmx()` | `HX-Request` | `bool` | The request came from htmx (`HX-Request: true`). |
| `isBoosted()` | `HX-Boosted` | `bool` | The request was issued by `hx-boost` (a boosted link/form). |
| `isHistoryRestoreRequest()` | `HX-History-Restore-Request` | `bool` | htmx is restoring a history-cache miss (re-fetching a page it couldn't restore from cache). |
| `htmxTarget()` | `HX-Target` | `?string` | The `id` of the target element (the `hx-target`). |
| `htmxTrigger()` | `HX-Trigger` | `?string` | The `id` of the triggering element. |
| `htmxTriggerName()` | `HX-Trigger-Name` | `?string` | The `name` attribute of the triggering element. |
| `htmxCurrentUrl()` | `HX-Current-URL` | `?string` | The browser's current URL at request time. |
| `htmxPrompt()` | `HX-Prompt` | `?string` | The user's response to an `hx-prompt` dialog. |

Branch on `isHtmx()` to serve a partial to htmx and a full page to a direct hit:

```php
$app->route('/search', methods: ['GET'], handler: function ($request) {
    $hits = Search::run($request->get['q'] ?? '');

    if ($request->isHtmx()) {
        // htmx asked for just the results — return the partial.
        return App::renderToString('search/results', ['hits' => $hits]);
    }
    // Direct navigation — return the whole page (which includes the results).
    return App::render('search/page', ['hits' => $hits]);
});
```

That branch is common enough that ZealPHP ships [`App::renderHtmx()`](#fragments--apprenderhtmx) to collapse it to one line.

## Driving the client

`$response->htmx()` returns a fluent `ZealPHP\HTTP\HtmxResponse` builder that queues `HX-*` **response** headers. These tell the htmx client what to do *after* it receives the body — redirect, retarget the swap, trigger a client event, refresh, and so on. Each setter returns the builder so calls chain; every value is CRLF/NUL-injection-guarded by `Response::header()` before it is queued.

```php
$response->htmx()
    ->retarget('#alerts')
    ->reswap('afterbegin')
    ->trigger('itemSaved');
```

### History & navigation

| Method | Header | Effect |
|--------|--------|--------|
| `pushUrl(string $url)` | `HX-Push-Url` | Push a new URL onto the history stack. Pass `"false"` to suppress. |
| `replaceUrl(string $url)` | `HX-Replace-Url` | Replace the current URL without a new history entry. |
| `redirect(string $url)` | `HX-Redirect` | Client-side redirect (no full reload). |
| `location(string $urlOrJson)` | `HX-Location` | Client-side redirect without a full reload; accepts a URL or a JSON location object (`{"path":"/p","target":"#c"}`). |

### Swap control

| Method | Header | Effect |
|--------|--------|--------|
| `reswap(string $strategy)` | `HX-Reswap` | Override the swap strategy (`innerHTML`, `outerHTML`, `beforebegin`, `afterbegin`, `beforeend`, `afterend`, `delete`, `none`, plus modifiers like `innerHTML swap:1s`). |
| `retarget(string $selector)` | `HX-Retarget` | Redirect the swap to a different element (CSS selector). |
| `reselect(string $selector)` | `HX-Reselect` | Choose which part of the response body is swapped in. |

### Page control

| Method | Header | Effect |
|--------|--------|--------|
| `refresh(bool $refresh = true)` | `HX-Refresh` | `true` → trigger a full client-side page refresh. |

### Events

| Method | Header | Effect |
|--------|--------|--------|
| `trigger(string $events)` | `HX-Trigger` | Trigger client events after the swap. Single name, comma-list, or a JSON object for events with detail. |
| `triggerAfterSwap(string $events)` | `HX-Trigger-After-Swap` | Same, fired after the swap step. |
| `triggerAfterSettle(string $events)` | `HX-Trigger-After-Settle` | Same, fired after the settle step. |
| `triggerJSON(string $event, array $detail)` | `HX-Trigger` | Trigger a single named event with a structured detail payload — without hand-encoding the JSON. |

`triggerJSON('showMessage', ['level' => 'info', 'message' => 'Saved!'])` is shorthand for `trigger('{"showMessage":{"level":"info","message":"Saved!"}}')`. The browser receives `event.detail` = the decoded array.

### Flowing back to the Response

Every builder setter returns the `HtmxResponse`, so the chain can't directly call a `Response` method like `status()`. `response()` hands the parent `Response` back so the chain can continue:

```php
// Validation failed — retarget the error box, swap it, and 422 the response.
$res->htmx()
    ->retarget('#form-errors')
    ->reswap('outerHTML')
    ->response()          // ← back to the Response
    ->status(422);
```

## Fragments & `App::renderHtmx()`

The htmx "one URL, two responses" pattern: a direct hit returns the full page; an htmx request returns just the piece that swaps in. ZealPHP supports this two ways.

### `App::fragment()` — two responses, one template file

`App::fragment($name, $fn)` marks a named region *inside* a template. The same template renders the full page normally, and just the named region when called with a `fragment` selector. One file, two responses — no separate partial file.

```php
// template/contacts/list.php
<ul>
<?php foreach ($contacts as $c): ?>
  <?php App::fragment("contact-{$c->id}", function () use ($c) { ?>
    <li id="contact-<?= $c->id ?>"><?= htmlspecialchars($c->name) ?></li>
  <?php }); ?>
<?php endforeach; ?>
</ul>
```

```php
// Full page:        App::render('contacts/list', ['contacts' => $all])
// One row (htmx):    App::render('contacts/list', ['contacts' => $all, 'fragment' => "contact-{$id}"])
```

When the fragment is extracted, its closure's return value rides the [universal return contract](./templates-and-rendering.md#universal-return-contract) — it can `return 404;`, `return ['k' => 'v'];`, or yield a Generator, exactly like a route handler. A `fragment` selector that matches no region is a `404` (no silent fallback). See [Templates & Rendering](./templates-and-rendering.md#appfragment--template-fragments-the-htmx-pattern) for the full fragment semantics.

### `App::renderHtmx()` — the selector

`App::renderHtmx()` is a thin, htmx-aware selector over `App::render()`. It reads the current request, and:

- **htmx request** → renders just a fragment (partial).
- **normal request** → renders the full page.

```php
public static function renderHtmx(
    string  $template,
    array   $args = [],
    ?string $fragmentName = null,
    ?string $fullPageTemplate = null
): mixed
```

Fragment selection for an htmx request:

1. If `$fragmentName` is passed, that region is rendered.
2. Otherwise the framework derives the region from the request — the `HX-Target` element id (a leading `#` is stripped), falling back to `HX-Trigger-Name`. If neither is present, the template is rendered with no `fragment` key (its bare partial output).

Called outside a request (a CLI render, a warmup), it falls back to the full-page path. It does **not** touch `executeFile()`; it only chooses *what* to render, so the universal return contract and streaming are preserved.

**Before** — the manual branch:

```php
$app->route('/search', methods: ['GET'], handler: function ($request) {
    $hits = Search::run($request->get['q'] ?? '');
    if ($request->isHtmx()) {
        $target = ltrim($request->htmxTarget() ?? '', '#');
        if ($target !== '') {
            return App::render('search', ['hits' => $hits, 'fragment' => $target]);
        }
        return App::render('search', ['hits' => $hits]);
    }
    return App::render('search', ['hits' => $hits]);
});
```

**After** — one line:

```php
$app->route('/search', methods: ['GET'], handler: fn($request) =>
    App::renderHtmx('search', ['hits' => Search::run($request->get['q'] ?? '')]));
```

Same shell, the `App::fragment('results', …)` region inside `search.php` is what an htmx `hx-target="#results"` request gets back; a direct hit gets the whole page.

Separate-template form — a bare partial for htmx plus a distinct full-page shell:

```php
$app->route('/widget', methods: ['GET'], handler: fn() =>
    App::renderHtmx('widget/partial', ['w' => $w], fullPageTemplate: 'widget/page'));
```

## Out-of-band swaps

Sometimes a response needs to update an element *other* than the swap target — a cart badge, a toast, a notification count. htmx's out-of-band (OOB) swap does that: any element in the response body carrying `hx-swap-oob` is swapped into the matching `id` regardless of the primary target.

`HtmxResponse::oob()` builds an OOB wrapper:

```php
public static function oob(
    string $id,
    string $html,
    string $swap = 'true',   // hx-swap-oob value; "true" = innerHTML
    string $tag  = 'div'
): string
```

Append it to any response body to perform an OOB swap with no extra round-trip:

```php
$app->route('/cart/add', methods: ['POST'], handler: function ($request) {
    $cart = Cart::add($request->post['sku']);
    // Primary swap: the product row. OOB: the cart badge.
    return "<div>Added.</div>"
         . HtmxResponse::oob('cart-count', (string) $cart->count);
});
```

The `id` and swap value are HTML-escaped; the tag is sanitised to alphanumerics (falling back to `div`).

## The boosting model

`hx-boost="true"` (set on `<body>` in the demo) turns ordinary `<a>` and `<form>` elements into AJAX navigations:

- A click/submit becomes an AJAX request; htmx swaps the `<body>`, updates the `<title>`, and pushes history.
- The request carries `HX-Boosted: true` **and** `HX-Request: true`. Read it with `$request->isBoosted()`.
- It degrades gracefully: with JS off, the underlying `href`/`action` performs a normal navigation.

**History restoration.** When the user navigates back/forward, htmx restores the page from its history cache. On a cache miss it re-fetches the URL and sends `HX-History-Restore-Request: true` — detect it with `$request->isHistoryRestoreRequest()` (e.g. to skip an expensive personalisation pass on a restore).

Because hx-boost swaps `<body>` but not `<head>`, the demo loads the `head-support` extension so each page's scoped CSS/JS still loads when you navigate into it. After each boosted swap htmx fires `htmx:afterSettle`, which the demo uses to re-run highlight.js and re-init demo panels.

## SSE / WebSocket — where htmx ends

htmx is request/response: a user action triggers a request, the server returns HTML, htmx swaps it. For **server-pushed** updates — the server sending data without a client request — reach past htmx to ZealPHP's streaming primitives:

- **Server-Sent Events** — `$response->sse($fn)` formats the SSE wire protocol for a JS `EventSource` (or htmx's `sse` extension). See [streaming.md](./streaming.md).
- **WebSocket** — `App::ws($path, $onMessage, $onOpen, $onClose)` for bidirectional realtime. See [websocket.md](./websocket.md).

htmx's `hx-ext="sse"` extension can consume an SSE endpoint declaratively (`sse-connect` / `sse-swap`), so a `$response->sse()` route on the server pairs naturally with htmx on the client when you want push-driven swaps without writing `EventSource` JavaScript. For two-way realtime (chat, presence, collaborative editing) use `App::ws()` — that's outside htmx's request/response model.

## CSRF with htmx

htmx submits forms over AJAX, so the usual hidden-input CSRF token works, but the cleaner pattern is `hx-headers` — attach the token as a request header for every htmx request under an element:

```html
<body hx-boost="true" hx-headers='{"X-CSRF-Token": "<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>"}'>
```

Validate it in middleware or the handler by reading the header off the request (`$request->header['x-csrf-token']`). Because `hx-headers` is inherited, one declaration on `<body>` covers every boosted navigation and every nested `hx-get`/`hx-post`.

**Skipping a login double-render.** When an unauthenticated htmx request hits a protected route, returning a full login *page* would swap the login HTML into a small target. Read `HX-Request` to handle it differently — e.g. send an `HX-Redirect` to the login URL (a clean client-side redirect) instead of rendering the login page into the swap target:

```php
if (!Auth::check()) {
    if ($request->isHtmx()) {
        $response->htmx()->redirect('/login');   // HX-Redirect → clean client redirect
        return '';
    }
    return $response->redirect('/login');         // normal 302 for a direct hit
}
```

## Reference table

| ZealPHP API | HX-* header / behaviour |
|-------------|-------------------------|
| **Request — `$request->`** | |
| `isHtmx()` | reads `HX-Request` |
| `isBoosted()` | reads `HX-Boosted` |
| `isHistoryRestoreRequest()` | reads `HX-History-Restore-Request` |
| `htmxTarget()` | reads `HX-Target` |
| `htmxTrigger()` | reads `HX-Trigger` |
| `htmxTriggerName()` | reads `HX-Trigger-Name` |
| `htmxCurrentUrl()` | reads `HX-Current-URL` |
| `htmxPrompt()` | reads `HX-Prompt` |
| **Response — `$response->htmx()->`** | |
| `pushUrl($url)` | sets `HX-Push-Url` |
| `replaceUrl($url)` | sets `HX-Replace-Url` |
| `redirect($url)` | sets `HX-Redirect` |
| `location($urlOrJson)` | sets `HX-Location` |
| `reswap($strategy)` | sets `HX-Reswap` |
| `retarget($selector)` | sets `HX-Retarget` |
| `reselect($selector)` | sets `HX-Reselect` |
| `refresh($bool)` | sets `HX-Refresh` |
| `trigger($events)` | sets `HX-Trigger` |
| `triggerAfterSwap($events)` | sets `HX-Trigger-After-Swap` |
| `triggerAfterSettle($events)` | sets `HX-Trigger-After-Settle` |
| `triggerJSON($event, $detail)` | sets `HX-Trigger` (JSON-encoded detail) |
| `oob($id, $html, $swap, $tag)` | builds an `hx-swap-oob` element (static) |
| `response()` | returns the parent `Response` (chain back) |
| **Rendering — `App::`** | |
| `renderHtmx($tpl, $args, $fragmentName, $fullPageTemplate)` | htmx → fragment, else full page; derives the fragment from `HX-Target` / `HX-Trigger-Name` |
| `fragment($name, $fn)` | marks a named region for one-file-two-responses extraction |

## See also

- [Templates & Rendering](./templates-and-rendering.md) — the file-execution family, `App::fragment()`, and the universal return contract.
- [Streaming](./streaming.md) — Generator SSR, `$response->stream()`, and SSE.
- [WebSocket](./websocket.md) — `App::ws()` and realtime.
- The `/learn/htmx` lesson — a narrative introduction to htmx on ZealPHP.

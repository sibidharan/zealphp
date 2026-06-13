<?php use ZealPHP\App; ?>
<section class="section">
<div class="container htmx-page">
<h1 class="section-title">HTMX</h1>
<p class="section-desc">ZealPHP treats <a href="https://htmx.org" target="_blank" rel="noopener">htmx</a> as a first-class citizen. The model is the opposite of a SPA: <strong>the server returns HTML</strong>, and htmx swaps it into the page over AJAX with no full reload. You write routes that return markup; htmx wires up interactivity from HTML attributes — no client framework, no JSON-to-DOM glue, no build step.</p>

<div class="callout info htmx-mt-1">
  <p class="htmx-m-0">This is the reference. For a gentle, narrative introduction, read the <a href="/learn/htmx">/learn/htmx lesson</a>. The full prose guide also lives at <a href="/docs/guide/htmx"><code>docs/htmx.md</code></a>.</p>
</div>

<h2 id="overview" class="htmx-mt-2">Overview — the server returns HTML</h2>
<p>A normal AJAX setup returns JSON and rebuilds the DOM in JavaScript. htmx inverts that: an element declares <em>where</em> a request goes and <em>what</em> to do with the HTML that comes back.</p>

<?php App::render('/components/_code', [
    'label' => 'Client: one form, two htmx attributes',
    'lang'  => 'html',
    'code'  => <<<'HTML'
<form hx-post="/items" hx-target="#list" hx-swap="afterbegin">
  <input name="item" placeholder="New item">
  <button type="submit">Add</button>
</form>
<ul id="list"><!-- new <li> rows get inserted here --></ul>
HTML]); ?>

<?php App::render('/components/_code', [
    'label' => 'Server: the route returns the new row',
    'code'  => <<<'PHP'
$app->route('/items', function ($request) {
    $item = Item::create($request->post['item']);
    return "<li>" . htmlspecialchars($item->name) . "</li>";
}, methods: ['POST']);
PHP]); ?>

<p><strong>Progressive enhancement.</strong> Because the markup is real HTML — a real <code>&lt;form action&gt;</code> / <code>&lt;a href&gt;</code> underneath the htmx attributes — the same page still works with JavaScript disabled. htmx is an enhancement layer, not a hard dependency.</p>

<h2 id="setup" class="htmx-mt-2">Setup out of the box</h2>
<p>The demo app's <code>template/_master.php</code> wires htmx for every page:</p>

<?php App::render('/components/_code', [
    'lang' => 'html',
    'code' => <<<'HTML'
<body hx-boost="true" hx-ext="head-support">
HTML]); ?>

<p>and <code>template/_head.php</code> loads the libraries:</p>

<table class="ztable htmx-mb-1">
  <tr><th>Library</th><th>Version</th><th>Role</th></tr>
  <tr><td><code>htmx.org</code></td><td><strong>2.0.10</strong></td><td>The core library.</td></tr>
  <tr><td><code>htmx-ext-head-support</code></td><td><strong>2.0.4</strong></td><td>Reconciles <code>&lt;head&gt;</code> on boosted navigation (hx-boost swaps <code>&lt;body&gt;</code> + <code>&lt;title&gt;</code> but not <code>&lt;head&gt;</code>, so per-page CSS/JS modules load when you navigate <em>into</em> a page).</td></tr>
</table>

<p>With <code>hx-boost="true"</code> on <code>&lt;body&gt;</code>, <strong>every</strong> <code>&lt;a&gt;</code> and <code>&lt;form&gt;</code> is automatically AJAX-ified: htmx swaps the <code>&lt;body&gt;</code>, updates the <code>&lt;title&gt;</code>, and manages history — no full reload.</p>

<div class="callout info">
  <strong>Boosted vs plain requests.</strong> A boosted navigation sends <code>HX-Boosted: true</code> <em>and</em> <code>HX-Request: true</code>; an explicit <code>hx-get</code>/<code>hx-post</code> sends <code>HX-Request: true</code> only; a full-page load (typed URL, hard refresh) sends neither. That distinction lets a handler decide between a full page and a partial.
</div>

<h2 id="reading-the-request" class="htmx-mt-2">Reading the request</h2>
<p><code>ZealPHP\HTTP\Request</code> (the <code>$request</code> injected into every handler) exposes eight accessors, one per htmx request header. Each returns <code>null</code> (or <code>false</code> for the booleans) when the header is absent.</p>

<table class="ztable htmx-mb-1">
  <tr><th>Accessor</th><th>Reads header</th><th>Returns</th><th>Meaning</th></tr>
  <tr><td><code>isHtmx()</code></td><td><code>HX-Request</code></td><td><code>bool</code></td><td>Request came from htmx.</td></tr>
  <tr><td><code>isBoosted()</code></td><td><code>HX-Boosted</code></td><td><code>bool</code></td><td>Issued by <code>hx-boost</code>.</td></tr>
  <tr><td><code>isHistoryRestoreRequest()</code></td><td><code>HX-History-Restore-Request</code></td><td><code>bool</code></td><td>htmx is restoring a history-cache miss.</td></tr>
  <tr><td><code>htmxTarget()</code></td><td><code>HX-Target</code></td><td><code>?string</code></td><td>The <code>id</code> of the target element.</td></tr>
  <tr><td><code>htmxTrigger()</code></td><td><code>HX-Trigger</code></td><td><code>?string</code></td><td>The <code>id</code> of the triggering element.</td></tr>
  <tr><td><code>htmxTriggerName()</code></td><td><code>HX-Trigger-Name</code></td><td><code>?string</code></td><td>The <code>name</code> of the triggering element.</td></tr>
  <tr><td><code>htmxCurrentUrl()</code></td><td><code>HX-Current-URL</code></td><td><code>?string</code></td><td>The browser's current URL.</td></tr>
  <tr><td><code>htmxPrompt()</code></td><td><code>HX-Prompt</code></td><td><code>?string</code></td><td>The user's <code>hx-prompt</code> response.</td></tr>
</table>

<?php App::render('/components/_code', [
    'label' => 'Branch on isHtmx() — partial for htmx, full page for a direct hit',
    'code'  => <<<'PHP'
$app->route('/search', function ($request) {
    $hits = Search::run($request->get['q'] ?? '');

    if ($request->isHtmx()) {
        // htmx asked for just the results — return the partial.
        return App::renderToString('search/results', ['hits' => $hits]);
    }
    // Direct navigation — return the whole page.
    return App::render('search/page', ['hits' => $hits]);
}, methods: ['GET']);
PHP]); ?>

<p>That branch is common enough that ZealPHP ships <a href="#render-htmx"><code>App::renderHtmx()</code></a> to collapse it to one line.</p>

<h2 id="driving-the-client" class="htmx-mt-2">Driving the client</h2>
<p><code>$response-&gt;htmx()</code> returns a fluent <code>ZealPHP\HTTP\HtmxResponse</code> builder that queues <code>HX-*</code> <strong>response</strong> headers — instructions the htmx client follows <em>after</em> it receives the body. Each setter returns the builder so calls chain; every value is CRLF/NUL-injection-guarded before it's queued.</p>

<?php App::render('/components/_code', [
    'label' => 'Chained response-header builder',
    'code'  => <<<'PHP'
$response->htmx()
    ->retarget('#alerts')
    ->reswap('afterbegin')
    ->trigger('itemSaved');
PHP]); ?>

<h3 class="htmx-mt-1">History &amp; navigation</h3>
<table class="ztable htmx-mb-1">
  <tr><th>Method</th><th>Header</th><th>Effect</th></tr>
  <tr><td><code>pushUrl($url)</code></td><td><code>HX-Push-Url</code></td><td>Push a URL onto history (<code>"false"</code> suppresses).</td></tr>
  <tr><td><code>replaceUrl($url)</code></td><td><code>HX-Replace-Url</code></td><td>Replace the URL without a new history entry.</td></tr>
  <tr><td><code>redirect($url)</code></td><td><code>HX-Redirect</code></td><td>Client-side redirect (no full reload).</td></tr>
  <tr><td><code>location($urlOrJson)</code></td><td><code>HX-Location</code></td><td>Client-side redirect; URL or JSON location object.</td></tr>
</table>

<h3 class="htmx-mt-1">Swap control</h3>
<table class="ztable htmx-mb-1">
  <tr><th>Method</th><th>Header</th><th>Effect</th></tr>
  <tr><td><code>reswap($strategy)</code></td><td><code>HX-Reswap</code></td><td>Override the swap (<code>innerHTML</code>, <code>outerHTML</code>, <code>afterbegin</code>, … + modifiers).</td></tr>
  <tr><td><code>retarget($selector)</code></td><td><code>HX-Retarget</code></td><td>Redirect the swap to a different element.</td></tr>
  <tr><td><code>reselect($selector)</code></td><td><code>HX-Reselect</code></td><td>Choose which part of the body is swapped in.</td></tr>
</table>

<h3 class="htmx-mt-1">Page control</h3>
<table class="ztable htmx-mb-1">
  <tr><th>Method</th><th>Header</th><th>Effect</th></tr>
  <tr><td><code>refresh($bool = true)</code></td><td><code>HX-Refresh</code></td><td><code>true</code> → full client-side page refresh.</td></tr>
</table>

<h3 class="htmx-mt-1">Events</h3>
<table class="ztable htmx-mb-1">
  <tr><th>Method</th><th>Header</th><th>Effect</th></tr>
  <tr><td><code>trigger($events)</code></td><td><code>HX-Trigger</code></td><td>Trigger events after the swap (name, comma-list, or JSON).</td></tr>
  <tr><td><code>triggerAfterSwap($events)</code></td><td><code>HX-Trigger-After-Swap</code></td><td>Same, after the swap step.</td></tr>
  <tr><td><code>triggerAfterSettle($events)</code></td><td><code>HX-Trigger-After-Settle</code></td><td>Same, after the settle step.</td></tr>
  <tr><td><code>triggerJSON($event, $detail)</code></td><td><code>HX-Trigger</code></td><td>Trigger one named event with a structured detail payload — no hand-encoding.</td></tr>
</table>

<p><code>triggerJSON('showMessage', ['level' =&gt; 'info', 'message' =&gt; 'Saved!'])</code> is shorthand for <code>trigger('{"showMessage":{"level":"info","message":"Saved!"}}')</code>. The browser receives <code>event.detail</code> = the decoded array.</p>

<h3 id="response-chain-back" class="htmx-mt-1">Flowing back to the Response</h3>
<p>Every setter returns the <code>HtmxResponse</code>, so the chain can't directly call a <code>Response</code> method like <code>status()</code>. <code>response()</code> hands the parent <code>Response</code> back so the chain can continue:</p>

<?php App::render('/components/_code', [
    'label' => 'Validation failed → retarget the error box, swap it, 422 the response',
    'code'  => <<<'PHP'
$res->htmx()
    ->retarget('#form-errors')
    ->reswap('outerHTML')
    ->response()          // ← back to the Response
    ->status(422);
PHP]); ?>

<h2 id="render-htmx" class="htmx-mt-2">Fragments &amp; <code>App::renderHtmx()</code></h2>
<p>The htmx "one URL, two responses" pattern: a direct hit returns the full page; an htmx request returns just the piece that swaps in. ZealPHP supports this two ways.</p>

<h3 class="htmx-mt-1"><code>App::fragment()</code> — two responses, one template file</h3>
<p><code>App::fragment($name, $fn)</code> marks a named region <em>inside</em> a template. The same template renders the full page normally, and just the named region when called with a <code>fragment</code> selector — no separate partial file.</p>

<?php App::render('/components/_code', [
    'label' => 'template/contacts/list.php — a named region per row',
    'lang'  => 'php',
    'code'  => <<<'PHP'
<ul>
<?php foreach ($contacts as $c): ?>
  <?php App::fragment("contact-{$c->id}", function () use ($c) { ?>
    <li id="contact-<?= $c->id ?>"><?= htmlspecialchars($c->name) ?></li>
  <?php }); ?>
<?php endforeach; ?>
</ul>
PHP]); ?>

<?php App::render('/components/_code', [
    'code'  => <<<'PHP'
// Full page:     App::render('contacts/list', ['contacts' => $all])
// One row (htmx): App::render('contacts/list', ['contacts' => $all, 'fragment' => "contact-{$id}"])
PHP]); ?>

<p>When extracted, the fragment closure's return value rides the <a href="/responses#return-contract">universal return contract</a> — it can <code>return 404;</code>, <code>return ['k' =&gt; 'v'];</code>, or yield a Generator. A selector that matches no region is a <code>404</code>. See <a href="/templates#fragments">Components &amp; templates</a> for full fragment semantics.</p>

<h3 id="render-htmx-helper" class="htmx-mt-1"><code>App::renderHtmx()</code> — the selector</h3>
<p><code>App::renderHtmx()</code> is a thin, htmx-aware selector over <code>App::render()</code>: an htmx request gets a fragment (partial), a normal request gets the full page.</p>

<?php App::render('/components/_code', [
    'code'  => <<<'PHP'
public static function renderHtmx(
    string  $template,
    array   $args = [],
    ?string $fragmentName = null,
    ?string $fullPageTemplate = null
): mixed
PHP]); ?>

<p>Fragment selection for an htmx request:</p>
<ol class="htmx-list">
  <li>If <code>$fragmentName</code> is passed, that region is rendered.</li>
  <li>Otherwise the framework derives it from the request — the <code>HX-Target</code> id (a leading <code>#</code> is stripped), falling back to <code>HX-Trigger-Name</code>. If neither is present, the template renders with no <code>fragment</code> key (its bare partial output).</li>
</ol>
<p>Called outside a request (a CLI render, a warmup) it falls back to the full-page path. It does <strong>not</strong> touch <code>executeFile()</code> — it only chooses <em>what</em> to render, so the return contract and streaming are preserved.</p>

<div class="htmx-beforeafter">
  <div class="htmx-ba-col">
    <div class="code-label">Before — the manual branch</div>
<?php App::render('/components/_code', [
    'code'  => <<<'PHP'
$app->route('/search', function ($request) {
    $hits = Search::run($request->get['q'] ?? '');
    if ($request->isHtmx()) {
        $target = ltrim($request->htmxTarget() ?? '', '#');
        if ($target !== '') {
            return App::render('search', ['hits' => $hits, 'fragment' => $target]);
        }
        return App::render('search', ['hits' => $hits]);
    }
    return App::render('search', ['hits' => $hits]);
}, methods: ['GET']);
PHP]); ?>
  </div>
  <div class="htmx-ba-col">
    <div class="code-label">After — one line</div>
<?php App::render('/components/_code', [
    'code'  => <<<'PHP'
$app->route('/search', fn($request) =>
    App::renderHtmx('search', [
        'hits' => Search::run($request->get['q'] ?? ''),
    ]), methods: ['GET']);
PHP]); ?>
  </div>
</div>

<p>Same shell — the <code>App::fragment('results', …)</code> region inside <code>search.php</code> is what an htmx <code>hx-target="#results"</code> request gets back; a direct hit gets the whole page. For a bare partial plus a distinct full-page shell, pass <code>fullPageTemplate</code>:</p>

<?php App::render('/components/_code', [
    'code'  => <<<'PHP'
$app->route('/widget', fn() =>
    App::renderHtmx('widget/partial', ['w' => $w], fullPageTemplate: 'widget/page'), methods: ['GET']);
PHP]); ?>

<h2 id="oob" class="htmx-mt-2">Out-of-band swaps</h2>
<p>Sometimes a response updates an element <em>other</em> than the swap target — a cart badge, a toast, a count. htmx's out-of-band (OOB) swap does that: any element carrying <code>hx-swap-oob</code> is swapped into the matching <code>id</code> regardless of the primary target. <code>HtmxResponse::oob()</code> builds the wrapper:</p>

<?php App::render('/components/_code', [
    'code'  => <<<'PHP'
$app->route('/cart/add', function ($request) {
    $cart = Cart::add($request->post['sku']);
    // Primary swap: a confirmation. OOB: the cart badge updates too.
    return "<div>Added.</div>"
         . HtmxResponse::oob('cart-count', (string) $cart->count);
}, methods: ['POST']);
PHP]); ?>

<p>The <code>id</code> and swap value are HTML-escaped; the tag is sanitised to alphanumerics (falling back to <code>div</code>).</p>

<h2 id="boosting" class="htmx-mt-2">The boosting model</h2>
<p><code>hx-boost="true"</code> turns ordinary <code>&lt;a&gt;</code> and <code>&lt;form&gt;</code> elements into AJAX navigations:</p>
<ul class="htmx-list">
  <li>A click/submit becomes an AJAX request; htmx swaps the <code>&lt;body&gt;</code>, updates the <code>&lt;title&gt;</code>, and pushes history.</li>
  <li>The request carries <code>HX-Boosted: true</code> <strong>and</strong> <code>HX-Request: true</code> — read it with <code>$request-&gt;isBoosted()</code>.</li>
  <li>It degrades gracefully: with JS off, the underlying <code>href</code>/<code>action</code> performs a normal navigation.</li>
</ul>
<p><strong>History restoration.</strong> On back/forward, htmx restores from its history cache. On a cache miss it re-fetches and sends <code>HX-History-Restore-Request: true</code> — detect it with <code>$request-&gt;isHistoryRestoreRequest()</code> (e.g. to skip an expensive personalisation pass on a restore). Because hx-boost swaps <code>&lt;body&gt;</code> but not <code>&lt;head&gt;</code>, the demo loads the <code>head-support</code> extension so per-page scoped CSS/JS still loads on navigation; after each swap htmx fires <code>htmx:afterSettle</code>, which the demo uses to re-run highlight.js and re-init demo panels.</p>

<h2 id="sse-ws" class="htmx-mt-2">SSE / WebSocket — where htmx ends</h2>
<p>htmx is request/response: an action triggers a request, the server returns HTML, htmx swaps it. For <strong>server-pushed</strong> updates — the server sending data without a client request — reach past htmx to ZealPHP's streaming primitives:</p>
<ul class="htmx-list">
  <li><strong>Server-Sent Events</strong> — <code>$response-&gt;sse($fn)</code> formats the SSE wire protocol for a JS <code>EventSource</code> (or htmx's <code>sse</code> extension). See <a href="/streaming">Streaming</a>.</li>
  <li><strong>WebSocket</strong> — <code>App::ws($path, $onMessage, $onOpen, $onClose)</code> for bidirectional realtime. See <a href="/ws">WebSocket</a>.</li>
</ul>
<p>htmx's <code>hx-ext="sse"</code> extension can consume an SSE endpoint declaratively (<code>sse-connect</code> / <code>sse-swap</code>), so a <code>$response-&gt;sse()</code> route pairs naturally with htmx when you want push-driven swaps without writing <code>EventSource</code> JavaScript. For two-way realtime (chat, presence, collaboration) use <code>App::ws()</code> — outside htmx's request/response model.</p>

<h2 id="csrf" class="htmx-mt-2">CSRF with htmx</h2>
<p>htmx submits forms over AJAX, so a hidden-input token works — but the cleaner pattern is <code>hx-headers</code>: attach the token as a request header for every htmx request under an element. Because it's inherited, one declaration on <code>&lt;body&gt;</code> covers every boosted navigation and nested <code>hx-get</code>/<code>hx-post</code>.</p>

<?php App::render('/components/_code', [
    'lang' => 'html',
    'code' => <<<'HTML'
<body hx-boost="true" hx-headers='{"X-CSRF-Token": "<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>"}'>
HTML]); ?>

<p>Validate it in middleware or the handler by reading the header off the request (<code>$request-&gt;header['x-csrf-token']</code>).</p>

<p><strong>Skipping a login double-render.</strong> When an unauthenticated htmx request hits a protected route, returning a full login <em>page</em> would swap login HTML into a small target. Read <code>HX-Request</code> to send an <code>HX-Redirect</code> instead:</p>

<?php App::render('/components/_code', [
    'code'  => <<<'PHP'
if (!Auth::check()) {
    if ($request->isHtmx()) {
        $response->htmx()->redirect('/login');   // HX-Redirect → clean client redirect
        return '';
    }
    return $response->redirect('/login');         // normal 302 for a direct hit
}
PHP]); ?>

<h2 id="reference" class="htmx-mt-2">Reference table</h2>
<table class="ztable htmx-mb-2">
  <tr><th>ZealPHP API</th><th>HX-* header / behaviour</th></tr>
  <tr><td colspan="2"><strong>Request — <code>$request-&gt;</code></strong></td></tr>
  <tr><td><code>isHtmx()</code></td><td>reads <code>HX-Request</code></td></tr>
  <tr><td><code>isBoosted()</code></td><td>reads <code>HX-Boosted</code></td></tr>
  <tr><td><code>isHistoryRestoreRequest()</code></td><td>reads <code>HX-History-Restore-Request</code></td></tr>
  <tr><td><code>htmxTarget()</code></td><td>reads <code>HX-Target</code></td></tr>
  <tr><td><code>htmxTrigger()</code></td><td>reads <code>HX-Trigger</code></td></tr>
  <tr><td><code>htmxTriggerName()</code></td><td>reads <code>HX-Trigger-Name</code></td></tr>
  <tr><td><code>htmxCurrentUrl()</code></td><td>reads <code>HX-Current-URL</code></td></tr>
  <tr><td><code>htmxPrompt()</code></td><td>reads <code>HX-Prompt</code></td></tr>
  <tr><td colspan="2"><strong>Response — <code>$response-&gt;htmx()-&gt;</code></strong></td></tr>
  <tr><td><code>pushUrl($url)</code></td><td>sets <code>HX-Push-Url</code></td></tr>
  <tr><td><code>replaceUrl($url)</code></td><td>sets <code>HX-Replace-Url</code></td></tr>
  <tr><td><code>redirect($url)</code></td><td>sets <code>HX-Redirect</code></td></tr>
  <tr><td><code>location($urlOrJson)</code></td><td>sets <code>HX-Location</code></td></tr>
  <tr><td><code>reswap($strategy)</code></td><td>sets <code>HX-Reswap</code></td></tr>
  <tr><td><code>retarget($selector)</code></td><td>sets <code>HX-Retarget</code></td></tr>
  <tr><td><code>reselect($selector)</code></td><td>sets <code>HX-Reselect</code></td></tr>
  <tr><td><code>refresh($bool)</code></td><td>sets <code>HX-Refresh</code></td></tr>
  <tr><td><code>trigger($events)</code></td><td>sets <code>HX-Trigger</code></td></tr>
  <tr><td><code>triggerAfterSwap($events)</code></td><td>sets <code>HX-Trigger-After-Swap</code></td></tr>
  <tr><td><code>triggerAfterSettle($events)</code></td><td>sets <code>HX-Trigger-After-Settle</code></td></tr>
  <tr><td><code>triggerJSON($event, $detail)</code></td><td>sets <code>HX-Trigger</code> (JSON-encoded detail)</td></tr>
  <tr><td><code>oob($id, $html, $swap, $tag)</code></td><td>builds an <code>hx-swap-oob</code> element (static)</td></tr>
  <tr><td><code>response()</code></td><td>returns the parent <code>Response</code> (chain back)</td></tr>
  <tr><td colspan="2"><strong>Rendering — <code>App::</code></strong></td></tr>
  <tr><td><code>renderHtmx($tpl, $args, $fragmentName, $fullPageTemplate)</code></td><td>htmx → fragment, else full page; derives from <code>HX-Target</code> / <code>HX-Trigger-Name</code></td></tr>
  <tr><td><code>fragment($name, $fn)</code></td><td>marks a named region for one-file-two-responses extraction</td></tr>
</table>

<div class="callout info">
  <strong>See also:</strong>
  <a href="/templates">Components &amp; templates</a> (the file-execution family, <code>App::fragment()</code>),
  <a href="/streaming">Streaming</a> (Generator SSR, SSE),
  <a href="/ws">WebSocket</a>, and the <a href="/learn/htmx">/learn/htmx lesson</a>.
</div>

</div>
</section>

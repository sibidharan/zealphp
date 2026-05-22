<?php use ZealPHP\App;
$active = $active ?? 'learn/htmx';
$g = \ZealPHP\G::instance();
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 15,
      'title'    => 'Forms & htmx',
      'subtitle' => 'Interactivity without a JavaScript framework. One attribute changes everything.',
      'prev'     => ['slug' => 'learn/react-vs-php', 'title' => 'React vs PHP'],
      'next'     => ['slug' => 'learn/sessions', 'title' => 'Sessions'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Handle HTML form submissions with POST',
      'The full-page-reload problem and why it feels broken',
      'Add htmx to submit forms without reloading',
      'The four htmx attributes you\'ll use 95% of the time',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      You build a form. The user types something. They click submit. The <strong>entire page reloads</strong>.
      The scroll position resets. The nav re-renders. For a simple "add item" action, reloading
      the whole page feels like demolishing a wall to replace a light switch.
    </p>
    <p>This is how the web worked in 1999. You can do better — without writing JavaScript.</p>

    <h2>Step 1: A traditional form</h2>
    <p>Here's a form that submits via regular POST:</p>
    <pre><code class="language-html">&lt;form method="post" action="/api/items"&gt;
  &lt;input type="text" name="item" placeholder="New item"&gt;
  &lt;button type="submit"&gt;Add&lt;/button&gt;
&lt;/form&gt;</code></pre>
    <p>
      This works. The server receives the data, processes it, and returns a full HTML page. But the
      browser navigates to a new URL, the old page is gone, and the user sees a flash of white.
    </p>

    <h2>Step 2: Add one attribute</h2>
    <p>Now add <code>hx-post</code>:</p>
    <pre><code class="language-html">&lt;form hx-post="/api/items" hx-target="#list" hx-swap="afterbegin"&gt;
  &lt;input type="text" name="item" placeholder="New item"&gt;
  &lt;button type="submit"&gt;Add&lt;/button&gt;
&lt;/form&gt;

&lt;div id="list"&gt;
  &lt;!-- items appear here --&gt;
&lt;/div&gt;</code></pre>
    <p>
      The form no longer reloads the page. htmx sends the POST in the background, receives the
      server's HTML response, and <strong>inserts it as the first child</strong> of <code>#list</code>.
      No JavaScript. No <code>fetch()</code>. No React.
    </p>

    <?php App::render('/components/_before_after', [
      'id' => 'htmx-form',
      'before_label' => 'Without htmx',
      'after_label'  => 'With htmx',
      'before' => '<pre><code class="language-html">&lt;form method="post" action="/api/items"&gt;
  &lt;input name="item" placeholder="New item"&gt;
  &lt;button&gt;Add&lt;/button&gt;
&lt;/form&gt;

&lt;!-- Full page reload. Scroll resets.
     User sees flash of white. --&gt;</code></pre>',
      'after' => '<pre><code class="language-html">&lt;form hx-post="/api/items"
      hx-target="#list"
      hx-swap="afterbegin"&gt;
  &lt;input name="item" placeholder="New item"&gt;
  &lt;button&gt;Add&lt;/button&gt;
&lt;/form&gt;

&lt;!-- No reload. New item appears instantly.
     Page stays exactly where it was. --&gt;</code></pre>',
    ]); ?>

    <h2>The mental model</h2>
    <p>
      Traditional forms are like <strong>demolishing a wall to replace a light switch</strong>.
      htmx is like <strong>unscrewing just the switch plate</strong>. The server sends back a new
      switch plate (an HTML fragment), and htmx swaps it into the wall. Everything else stays untouched.
    </p>
    <p>
      htmx doesn't replace your server rendering. It <em>enhances</em> it. The server still generates
      HTML — htmx just puts it in the right place without a full page navigation.
    </p>

    <h2>The four attributes</h2>
    <p>This is 95% of htmx:</p>
    <ul>
      <li><code>hx-get</code> / <code>hx-post</code> / <code>hx-put</code> / <code>hx-delete</code> — <strong>fire the request</strong></li>
      <li><code>hx-target</code> — <strong>which element to update</strong> (CSS selector)</li>
      <li><code>hx-swap</code> — <strong>how to insert the response</strong>
        <ul>
          <li><code>innerHTML</code> — replace the target's children</li>
          <li><code>outerHTML</code> — replace the target itself</li>
          <li><code>afterbegin</code> — insert as first child</li>
          <li><code>beforeend</code> — insert as last child</li>
          <li><code>delete</code> — remove the target</li>
        </ul>
      </li>
      <li><code>hx-trigger</code> — <strong>when to fire</strong> (<code>click</code> default, also <code>load</code>, <code>change</code>, <code>keyup delay:300ms</code>)</li>
    </ul>
    <p>That's it. Four attributes replace hundreds of lines of JavaScript.</p>

    <h2>Live demo: a counter button</h2>
    <p>
      The button below has <strong>zero custom JavaScript</strong>. It uses <code>hx-post</code> to send
      a request to <code>/api/learn/demo/incr</code>. The server increments a counter stored in your session,
      renders a new <code>&lt;button&gt;</code> element, and returns it. <code>hx-swap="outerHTML"</code>
      replaces the old button with the new one.
    </p>

    <?php App::render('/components/_tryit', ['title' => 'Click the counter', 'body' =>
      '<div class="lhx-center">' .
      App::renderToString('/components/_counter_button', ['n' => (int)($g->session['demo_counter'] ?? 0)]) .
      '</div>' .
      '<p>Open DevTools → Network tab and watch each click: a POST goes out, an HTML fragment comes back, the button is replaced. No page reload. No JSON parsing. No client-side state management.</p>'
    ]); ?>

    <h2>Six recipes you'll actually use</h2>
    <p>
      The four attributes cover the mechanics. These five patterns cover the everyday use cases —
      every one of them is wired up in the demo app you're reading right now. Copy, paste, adapt.
    </p>

    <h3>1. Inline edit — click to edit, save in place</h3>
    <p>
      The user clicks an item. The server returns the same row, but with an <code>&lt;input&gt;</code>
      replacing the static text. They edit, blur, the server saves and returns the static row again.
      Used in the notes app for renaming notes.
    </p>
    <pre><code class="language-html">&lt;!-- The static row --&gt;
&lt;div id="note-42"&gt;
  &lt;span hx-get="/api/learn/notes/42/edit"
        hx-target="#note-42"
        hx-swap="outerHTML"
        style="cursor:pointer"&gt;Buy milk&lt;/span&gt;
&lt;/div&gt;

&lt;!-- What the server returns when /edit is clicked --&gt;
&lt;div id="note-42"&gt;
  &lt;input name="title" value="Buy milk"
         hx-post="/api/learn/notes/42"
         hx-trigger="blur, keyup[key=='Enter']"
         hx-target="#note-42"
         hx-swap="outerHTML" autofocus&gt;
&lt;/div&gt;</code></pre>

    <h3>2. Delete with confirmation</h3>
    <p>
      One attribute, native browser confirm dialog, the row disappears on success.
      No <code>confirm()</code> wrapper, no event handlers, no state.
    </p>
    <pre><code class="language-html">&lt;button hx-delete="/api/learn/notes/42"
        hx-confirm="Delete this note?"
        hx-target="#note-42"
        hx-swap="delete"&gt;Delete&lt;/button&gt;</code></pre>

    <h3>3. Search as you type</h3>
    <p>
      Type in the search box, results filter live. The <code>delay:300ms</code> debounces requests;
      <code>changed</code> only fires when the input actually changed (not every keystroke).
    </p>
    <pre><code class="language-html">&lt;input type="search" name="q" placeholder="Search notes…"
       hx-get="/api/learn/notes/search"
       hx-trigger="keyup changed delay:300ms"
       hx-target="#results"
       hx-swap="innerHTML"&gt;
&lt;div id="results"&gt;&lt;/div&gt;</code></pre>

    <h3>4. Load more on scroll</h3>
    <p>
      The "Load more" link replaces itself with the next page of items plus a new "Load more" at
      the bottom. <code>hx-trigger="revealed"</code> auto-fires when the link enters the viewport —
      true infinite scroll, three lines.
    </p>
    <pre><code class="language-html">&lt;div id="feed"&gt;
  &lt;!-- ...first page of items... --&gt;
  &lt;a hx-get="/api/feed?page=2"
     hx-target="this"
     hx-swap="outerHTML"
     hx-trigger="revealed"&gt;Loading more…&lt;/a&gt;
&lt;/div&gt;</code></pre>

    <h3>5. Modal — open, fill, close</h3>
    <p>
      Click a button, server returns a <code>&lt;dialog&gt;</code> element with the form inside.
      Append it to <code>&lt;body&gt;</code>. The form posts and removes the dialog on success.
    </p>
    <pre><code class="language-html">&lt;button hx-get="/notes/new-modal"
        hx-target="body"
        hx-swap="beforeend"&gt;+ New note&lt;/button&gt;

&lt;!-- Server returns: --&gt;
&lt;dialog id="modal" open&gt;
  &lt;form hx-post="/api/learn/notes"
        hx-target="#modal"
        hx-swap="outerHTML"&gt;
    &lt;input name="title" placeholder="Title" required autofocus&gt;
    &lt;button type="submit"&gt;Create&lt;/button&gt;
    &lt;button type="button" onclick="this.closest('dialog').remove()"&gt;Cancel&lt;/button&gt;
  &lt;/form&gt;
&lt;/dialog&gt;</code></pre>

    <h3>6. Server-driven pagination</h3>
    <p>
      Recipe 4 is client-driven — the browser fires a request when a sentinel scrolls into view, and
      the server replies with whatever page <em>the client asked for</em>. That works when the data
      is static. When the underlying list is changing live (new posts arriving, items getting
      deleted), you want the <strong>server</strong> to decide what "next page" means using its own
      state — typically a cursor (last id seen) instead of a page number.
    </p>
    <pre><code class="language-html">&lt;!-- Initial render — server emits N items + a "Load more" link
     whose hx-get carries the cursor of the last row shown --&gt;
&lt;ul id="feed"&gt;
  &lt;li&gt;Item 200&lt;/li&gt;
  &lt;li&gt;Item 199&lt;/li&gt;
  ...
  &lt;!-- The link replaces itself with the next page + a new "Load more" --&gt;
  &lt;a hx-get="/api/feed?after=180"
     hx-target="this"
     hx-swap="outerHTML"&gt;Load more&lt;/a&gt;
&lt;/ul&gt;

&lt;!-- Server returns, for /api/feed?after=180: --&gt;
&lt;li&gt;Item 180&lt;/li&gt;
&lt;li&gt;Item 179&lt;/li&gt;
...
&lt;a hx-get="/api/feed?after=161"
   hx-target="this"
   hx-swap="outerHTML"&gt;Load more&lt;/a&gt;</code></pre>
    <p>
      The handler:
    </p>
    <pre><code class="language-php">$app-&gt;route('/api/feed', function ($request) {
    $after = (int)($request-&gt;get['after'] ?? PHP_INT_MAX);
    $rows  = Feed::recent(beforeId: $after, limit: 20);
    if (empty($rows)) return '&lt;!-- end --&gt;';  // empty fragment, link is gone
    $last  = end($rows)-&gt;id;
    $html  = '';
    foreach ($rows as $row) $html .= "&lt;li&gt;{$row-&gt;title}&lt;/li&gt;";
    $html .= "&lt;a hx-get=\"/api/feed?after={$last}\" hx-target=\"this\" hx-swap=\"outerHTML\"&gt;Load more&lt;/a&gt;";
    return $html;
});</code></pre>
    <p>
      The cursor (<code>last_id</code>) lives in the URL of the next request, not in client state.
      The server can change page size, skip soft-deleted rows, or insert promotional content
      between pages — all without the client knowing. This is the pattern most "infinite feed"
      products actually use; recipe 4 is the demo, recipe 6 is the product.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'The pattern under all six',
      'body'    => '<p>Notice what every recipe has in common: <strong>the server returns HTML, not JSON</strong>. No client-side rendering. No "wait for the data, then build the DOM." The server already knows how to render this row, this dialog, this search result — htmx just puts the rendered HTML where it belongs. Your route handlers become HTML fragment factories. That is the entire architectural shift.</p>',
    ]); ?>

    <h2>Progressive enhancement</h2>
    <p>
      htmx works <em>on top of</em> regular HTML. If JavaScript is disabled, a
      <code>&lt;form hx-post="/foo"&gt;</code> falls back to a regular form POST. The server returns
      the same HTML; htmx just makes it smoother. Your app degrades gracefully — that's a feature.
    </p>

    <?php App::render('/components/_concept_check', [
      'id'       => 'htmx1',
      'question' => 'What does hx-swap="afterbegin" do?',
      'correct'  => 'b',
      'explain'  => 'afterbegin inserts the response HTML as the first child of the target element, pushing existing children down.',
      'options'  => [
        'a' => 'Replaces the entire target element',
        'b' => 'Inserts the response as the first child of the target',
        'c' => 'Appends the response after the target element',
      ],
    ]); ?>

    <?php App::render('/components/_deepdive', [
      'title' => 'When htmx isn\'t enough',
      'body'  => '<p>htmx is request/response. The client asks, the server answers. For scenarios where the <em>server</em> needs to push updates without being asked — live notifications, multi-tab sync, AI token streaming — you need a persistent connection. ZealPHP has WebSocket (<code>App::ws()</code>) and Server-Sent Events (<code>$response->sse()</code>) for those cases. You\'ll use both in Lessons 19 (Real-Time Sync) and 20 (AI Chat).</p>',
    ]); ?>

    <h2 id="fragments" class="lhx-h2">Template fragments — one file, two responses</h2>
    <p>
      The htmx pattern above has a tension: when a user clicks the
      <strong>Add</strong> button, the server returns a single <code>&lt;li&gt;</code>
      to insert into the list. But the <em>same</em> page on first load needs the
      <strong>full</strong> list rendered as part of the HTML — same markup, same
      template variables. Where does the <code>&lt;li&gt;</code> markup live?
    </p>

    <p>The naive answer is "two files":</p>

    <?php App::render('/components/_code', [
      'label' => 'The two-file approach — partials (works, but duplicated knowledge)',
      'code'  => <<<'PHP'
// template/contacts/list.php — full page
<ul>
<?php foreach ($contacts as $c): ?>
  <?= App::renderToString('/partials/contact-row', ['c' => $c]) ?>
<?php endforeach; ?>
</ul>

// template/partials/contact-row.php — the row in a separate file
<li id="contact-<?= $c['id'] ?>">
  <?= htmlspecialchars($c['name']) ?>
</li>

// Route handler — call render() OR include the partial directly:
$app->route('/contacts',         fn() => App::render('contacts/list', ['contacts' => Contact::all()]));
$app->route('/contacts/{id}/row', fn($id) => App::render('/partials/contact-row', ['c' => Contact::find($id)]));
PHP,
    ]); ?>

    <p>
      That works (and ZealPHP does it natively — <code>App::render()</code>,
      <code>App::renderToString()</code>, <code>App::renderStream()</code>, and
      <code>App::include()</code> all let one template call another;
      see <a href="/learn/components">Layouts &amp; Components</a> for the full
      family). But it forces the row markup to live in a separate file from the
      list it's used in, and the route handler now has two near-identical entries
      that drift apart over time.
    </p>

    <p>
      <strong>Template fragments</strong> (since v0.2.24) collapse both into one
      template + one route. Mark named regions inline with
      <code>App::fragment($name, fn)</code>; the framework either runs the
      region inline as part of the full page (no fragment selector) or extracts
      <em>just that region</em> when the caller asks for it by name.
    </p>

    <?php App::render('/components/_code', [
      'label' => 'template/contacts/list.php — single file, both responses',
      'code'  => <<<'PHP'
<ul id="contacts">
<?php foreach ($contacts as $c): ?>
  <?php App::fragment("contact-{$c['id']}", function() use ($c) { ?>
    <li id="contact-<?= $c['id'] ?>" hx-target="this" hx-swap="outerHTML">
      <span><?= htmlspecialchars($c['name']) ?></span>
      <button hx-get="/contacts?fragment=contact-<?= $c['id'] ?>-edit">Edit</button>
    </li>
  <?php }); ?>
  <?php App::fragment("contact-{$c['id']}-edit", function() use ($c) { ?>
    <li id="contact-<?= $c['id'] ?>" hx-target="this" hx-swap="outerHTML">
      <form hx-post="/contacts/<?= $c['id'] ?>" hx-swap="outerHTML">
        <input name="name" value="<?= htmlspecialchars($c['name']) ?>">
        <button>Save</button>
      </form>
    </li>
  <?php }); ?>
<?php endforeach; ?>
</ul>
PHP,
    ]); ?>

    <?php App::render('/components/_code', [
      'label' => 'route handler — ONE entry, both modes',
      'code'  => <<<'PHP'
$app->route('/contacts', function($g) {
    return App::render('contacts/list', [
        'contacts' => Contact::all(),
        // No fragment → full page renders normally.
        // ?fragment=contact-2 → only that row's <li> comes back, no <ul>, no siblings.
        'fragment' => is_string($g->get['fragment'] ?? null) ? $g->get['fragment'] : null,
    ]);
});
PHP,
    ]); ?>

    <h3 class="lhx-h3">Fragments ride the universal return contract</h3>
    <p>
      Inside the closure passed to <code>App::fragment()</code> you can return
      anything a route handler can — the framework propagates it through the
      same <a href="/responses#return-contract">universal return contract</a>
      that every other entry point uses (route handler, public file, API
      closure, <code>App::include()</code>).
    </p>

    <?php App::render('/components/_code', [
      'label' => 'Fragment closures return shapes — same as route handlers',
      'code'  => <<<'PHP'
<?php App::fragment('contact-row', function() use ($contact, $request, $g) {
    // Auth check — return HTTP status int and the framework emits 403:
    if (!$g->session['user'] || !canSee($g->session['user'], $contact)) {
        return 403;
    }

    // Accept: application/json — return array, framework emits JSON:
    if (str_contains($request->header['accept'] ?? '', 'application/json')) {
        return ['id' => $contact->id, 'name' => $contact->name];
    }

    // Otherwise stream HTML chunks via a Generator:
    return (function() use ($contact) {
        yield "<li id='contact-{$contact->id}'>";
        yield htmlspecialchars($contact->name);
        yield '</li>';
    })();
}); ?>
PHP,
    ]); ?>

    <p>
      Three other behaviours worth knowing:
    </p>
    <ul class="lhx-list">
      <li>
        <strong>Missing fragment → 404.</strong> Asking for
        <code>?fragment=does-not-exist</code> when no
        <code>App::fragment('does-not-exist', …)</code> block matched returns
        HTTP 404. Doesn't accidentally fall through to the full page.
      </li>
      <li>
        <strong>First match wins.</strong> If the same fragment name appears
        twice in a template, the first block is extracted; the rest of the
        template short-circuits via <code>HaltException</code>.
      </li>
      <li>
        <strong>Nested renders compose cleanly.</strong> An <code>App::render()</code>
        called from inside a fragment closure does <em>not</em> inherit the parent's
        fragment selector — each render's scope is saved and restored.
      </li>
    </ul>

    <h3 class="lhx-h3">Try it live</h3>
    <p>
      A four-contact list rendered through one template. Each row's "Show
      details" button does <code>hx-get="/demo/fragments/contacts?fragment=contact-{id}"</code> —
      same URL as the page, just one named fragment swapped in place. View source
      after a swap and you'll see only the <code>&lt;li&gt;</code> came back, not the surrounding
      <code>&lt;html&gt;</code> shell.
    </p>

    <?php App::render('/components/_tryit', [
      'title' => 'Open the contacts demo',
      'body'  => '<p class="lhx-tryit-line">'
              .  '<a href="/demo/fragments/contacts" target="_blank" rel="noopener" class="tryit-link lhx-link">/demo/fragments/contacts</a>'
              .  ' — full page, then click any row.</p>'
              .  '<p class="lhx-tryit-note">'
              .  'Open DevTools → Network → XHR to see each swap is a single 200 response with just one <code>&lt;li&gt;</code> as the body. '
              .  'And <a href="/demo/fragments/contacts?fragment=does-not-exist" target="_blank" rel="noopener" class="lhx-link">/demo/fragments/contacts?fragment=does-not-exist</a> returns HTTP 404 — the framework refuses to fall back to the full page when the named fragment doesn\'t exist.</p>',
    ]); ?>

    <h2 id="server-side">Server-side: detect htmx, drive client behaviour, compose with <code>App::fragment()</code></h2>
    <p>
      htmx flows have <strong>two sides</strong>: the client decides what to swap (<code>hx-*</code> attributes,
      shown above), and the server decides <em>what HTML to return</em> AND <em>what htmx should do after the swap</em>.
      ZealPHP gives you a thin layer over both — <code>App::fragment()</code> for the body
      (<a href="/templates#fragments">one file, two responses</a>) and a fluent
      <code>$response->htmx()</code> builder for the response headers htmx reads. The two compose cleanly because
      they're orthogonal: <strong>fragment writes the body, <code>htmx()</code> writes the metadata.</strong>
    </p>

    <h3 id="hx-request">Read htmx context from the request</h3>
    <p>Available on every <code>ZealPHP\HTTP\Request</code> — no need to dig through <code>$g->server['HTTP_HX_*']</code>:</p>
    <pre><code class="language-php">use ZealPHP\App;

$app->route('/notes/{id}', function ($request, $response, $id) {
    $note = Notes::find((int) $id);

    if ($request->isHtmx()) {
        // htmx swap — return just the card fragment
        return App::renderToString('pages/notes', [
            'note'     => $note,
            'fragment' => 'note-card',   // App::fragment('note-card', ...) inside the template
        ]);
    }

    // Plain navigation / bookmark / search-engine — full page
    return App::render('_master', ['page' => 'note', 'note' => $note]);
});</code></pre>

    <p class="lhx-note">
      Also: <code>isBoosted()</code>, <code>isHistoryRestoreRequest()</code>,
      <code>htmxTarget()</code>, <code>htmxTrigger()</code>, <code>htmxTriggerName()</code>,
      <code>htmxCurrentUrl()</code>, <code>htmxPrompt()</code>.
    </p>

    <h3 id="hx-response">Drive client behaviour with <code>$response->htmx()</code></h3>
    <p>
      11 HX-* response headers htmx reads after a swap — events, browser history,
      target/swap override, refresh, redirect. Fluent, chained:
    </p>
    <pre><code class="language-php">$app->route('/api/notes', function ($request, $response) {
    $note = Notes::create($request->getParsedBody());

    return $response->htmx()
        ->trigger('note-saved')                   // HX-Trigger: fire JS event
        ->triggerAfterSwap('focus-next-input')    // HX-Trigger-After-Swap
        ->pushUrl("/notes/{$note->id}")           // HX-Push-Url: browser history
        ->reswap('beforeend')                     // HX-Reswap: override target swap
        ->response()                              // → underlying Response
        ->withHeader('Content-Type', 'text/html')
        ->withBody(\GuzzleHttp\Psr7\Utils::streamFor(
            App::renderToString('partials/note_card', ['note' => $note])
        ));
});</code></pre>

    <p class="lhx-note">
      Full surface: <code>trigger()</code>, <code>triggerAfterSwap()</code>, <code>triggerAfterSettle()</code>,
      <code>reswap()</code>, <code>retarget()</code>, <code>reselect()</code>,
      <code>refresh()</code>, <code>location()</code>, <code>pushUrl()</code>, <code>replaceUrl()</code>,
      <code>redirect()</code>. Each returns the builder; <code>response()</code> hands you back the underlying <code>Response</code>.
    </p>

    <h3 id="hx-oob">Out-of-band swaps — update multiple regions in one response</h3>
    <p>
      Sometimes one action should refresh more than the targeted element — e.g. saving a note also bumps an
      unread-counter badge in the nav. <code>HtmxResponse::oob()</code> emits a marked fragment you concat into
      the response body; htmx swaps it into its own <code>id</code>-matched region client-side:
    </p>
    <pre><code class="language-php">use ZealPHP\HTTP\HtmxResponse;

$body = App::renderToString('partials/note_card', ['note' => $note])         // primary swap (hx-target)
      . HtmxResponse::oob('unread-badge', '&lt;span&gt;' . $unread . '&lt;/span&gt;')         // OOB: replace #unread-badge
      . HtmxResponse::oob('toast', '&lt;div&gt;Saved!&lt;/div&gt;', swap: 'beforeend'); // OOB: append to #toast

return $response->htmx()->trigger('note-saved')->response()
       ->withHeader('Content-Type', 'text/html')
       ->withBody(\GuzzleHttp\Psr7\Utils::streamFor($body));</code></pre>

    <h3 id="hx-compose">Putting it together: one template, two responses, one HX-Trigger</h3>
    <p>
      This is the punchline. The <em>same</em> route handler serves the full page on plain navigation and the
      named fragment on htmx swap — and on the htmx response, fires a client event so a toast component picks up:
    </p>
    <pre><code class="language-php">// template/pages/notes.php — fragments and full page from one file
&lt;?php use ZealPHP\App; ?&gt;
&lt;section&gt;
  &lt;h1&gt;Notes&lt;/h1&gt;
  &lt;?php App::fragment('note-list', function () use ($notes) { ?&gt;
    &lt;ul id="note-list" hx-target="this" hx-swap="outerHTML"&gt;
      &lt;?php foreach ($notes as $note): ?&gt;
        &lt;li&gt;&lt;?= htmlspecialchars($note-&gt;title) ?&gt;&lt;/li&gt;
      &lt;?php endforeach; ?&gt;
    &lt;/ul&gt;
  &lt;?php }); ?&gt;
&lt;/section&gt;</code></pre>
    <pre><code class="language-php">// route handler — branch by request type, fire event on save
use ZealPHP\App;

$app->route('/notes', function ($request, $response) {
    if ($request->getMethod() === 'POST') {
        Notes::create($request->getParsedBody());
        // After save, return just the updated list AND fire the toast event.
        $body = App::renderToString('pages/notes', [
            'notes'    => Notes::all(),
            'fragment' => 'note-list',
        ]);
        return $response->htmx()->trigger('note-saved')->response()
               ->withHeader('Content-Type', 'text/html')
               ->withBody(\GuzzleHttp\Psr7\Utils::streamFor($body));
    }
    // GET — full page on plain nav, just #note-list on htmx swap.
    return App::render('_master', [
        'page'     => 'notes',
        'notes'   => Notes::all(),
        'fragment' => $request->isHtmx() ? 'note-list' : null,
    ]);
});</code></pre>

    <p class="lhx-note">
      <strong>Why this design:</strong> body and metadata are orthogonal concerns. <code>App::render*()</code> /
      <code>App::include()</code> / <code>App::fragment()</code> own the body (the
      <a href="/responses#return-contract">universal return contract</a> covers every shape). Response
      headers are not body content; pushing them into the rendering API would conflate two unrelated axes and break
      the contract that "what the file/template returns is the body." The builder lives on <code>Response</code>
      because that's where headers actually go.
    </p>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'htmx turns any HTML element into an AJAX trigger with just HTML attributes',
      'The server returns HTML fragments, not JSON — no client-side rendering needed',
      'Four attributes (<code>hx-post</code>, <code>hx-target</code>, <code>hx-swap</code>, <code>hx-trigger</code>) cover 95% of use cases',
      'Server side: <code>$request->isHtmx()</code> + <code>$response->htmx()->trigger()->pushUrl()</code> + <code>App::fragment()</code> compose into one handler that serves full page <em>and</em> partial swap',
      'Progressive enhancement: forms still work without JavaScript',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/components"
         hx-get="/api/learn/page?slug=learn/components" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/components">← Layouts &amp; Components</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/sessions"
         hx-get="/api/learn/page?slug=learn/sessions" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/sessions">Sessions →</a>
    </div>
  </article>
</div>

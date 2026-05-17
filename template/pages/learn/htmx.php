<?php use ZealPHP\App;
$active = $active ?? 'learn/htmx';
$g = \ZealPHP\G::instance();
?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 6,
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
      '<div style="text-align:center;padding:1rem 0">' .
      App::renderToString('/components/_counter_button', ['n' => (int)($g->session['demo_counter'] ?? 0)]) .
      '</div>' .
      '<p>Open DevTools → Network tab and watch each click: a POST goes out, an HTML fragment comes back, the button is replaced. No page reload. No JSON parsing. No client-side state management.</p>'
    ]); ?>

    <h2>Five recipes you'll actually use</h2>
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

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'The pattern under all five',
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
      'body'  => '<p>htmx is request/response. The client asks, the server answers. For scenarios where the <em>server</em> needs to push updates without being asked — live notifications, multi-tab sync, AI token streaming — you need a persistent connection. ZealPHP has WebSocket (<code>App::ws()</code>) and Server-Sent Events (<code>$response->sse()</code>) for those cases. You\'ll use both in Lessons 9 and 10.</p>',
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'htmx turns any HTML element into an AJAX trigger with just HTML attributes',
      'The server returns HTML fragments, not JSON — no client-side rendering needed',
      'Four attributes (<code>hx-post</code>, <code>hx-target</code>, <code>hx-swap</code>, <code>hx-trigger</code>) cover 95% of use cases',
      'Progressive enhancement: forms still work without JavaScript',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/components"
         hx-get="/api/learn/page?slug=learn/components" hx-target=".lesson-content"
         hx-swap="outerHTML show:.lesson-content:top" hx-push-url="/learn/components">← Layouts &amp; Components</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/sessions"
         hx-get="/api/learn/page?slug=learn/sessions" hx-target=".lesson-content"
         hx-swap="outerHTML show:.lesson-content:top" hx-push-url="/learn/sessions">Sessions →</a>
    </div>
  </article>
</div>

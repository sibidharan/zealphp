<?php use ZealPHP\App; $active = $active ?? 'learn/htmx'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 7,
      'title'    => 'Add htmx',
      'subtitle' => 'Interactivity without a JavaScript framework. Four attributes is the whole API.',
      'prev'     => ['slug' => 'learn/sessions', 'title' => 'Sessions & Auth'],
      'next'     => ['slug' => 'learn/notes', 'title' => 'Build Personal Notes'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'The four htmx attributes you need 95% of the time',
      'Progressive enhancement — your forms still post if JS is off',
      'When htmx is right and when to reach for WebSocket instead',
    ]]); ?>

    <h2>Why htmx?</h2>
    <p>
      Most web apps need just enough interactivity to avoid full-page reloads on common actions
      — submit a form, delete an item, refresh a list. React solves that problem and then keeps going,
      replacing the whole rendering model. htmx solves <em>only</em> that problem, in 14&nbsp;KB of JavaScript,
      using attributes you add to ordinary HTML.
    </p>

    <h2>The four attributes</h2>
    <pre><code>&lt;button hx-post="/api/items" hx-target="#list" hx-swap="afterbegin"&gt;
  Add item
&lt;/button&gt;</code></pre>
    <ul style="line-height:1.8">
      <li><code>hx-get</code> / <code>hx-post</code> / <code>hx-put</code> / <code>hx-delete</code> — fire the request</li>
      <li><code>hx-target</code> — CSS selector for the DOM node to update</li>
      <li><code>hx-swap</code> — how to insert the response (<code>innerHTML</code>, <code>outerHTML</code>, <code>afterbegin</code>, <code>beforeend</code>, <code>delete</code>, <code>none</code>)</li>
      <li><code>hx-trigger</code> — when to fire (<code>click</code> by default, also <code>load</code>, <code>change</code>, <code>keyup delay:300ms</code>...)</li>
    </ul>

    <h2>Live demo — a counter button</h2>
    <p>
      The button below has zero custom JavaScript. <code>hx-post</code> sends a request to
      <code>/api/learn/demo/incr</code>; the server increments a session counter and returns
      a new <code>&lt;button&gt;</code> element; <code>hx-swap="outerHTML"</code> replaces this
      one with the new one. Click it:
    </p>
    <?php $g = \ZealPHP\G::instance(); ?>
    <?php App::render('/components/_tryit', ['title' => 'Counter (server-side state via $g->session)', 'body' => '<div style="text-align:center;padding:1rem 0">' . App::renderToString('/components/_counter_button', ['n' => (int)($g->session['demo_counter'] ?? 0)]) . '</div><p style="margin-top:.75rem">The HTML you see is rendered server-side by <code>App::renderToString(\'/components/_counter_button\', [\'n\' =&gt; \$n])</code> on every click. Open DevTools → Network and watch the POST/response cycle.</p>']); ?>

    <h2>Progressive enhancement</h2>
    <p>
      htmx works <em>on top of</em> regular HTML — not instead of it. If JavaScript is disabled,
      a <code>&lt;form hx-post="/foo"&gt;</code> still does a real form POST. The server returns the same HTML;
      htmx just swaps the response into a target instead of replacing the whole page.
      Your app degrades to a 1990s-era PHP site for users who block scripts. That's a feature, not a limitation.
    </p>

    <?php App::render('/components/_deepdive', [
      'title' => 'When htmx isn\'t enough — WebSocket',
      'body'  => '<p>htmx is request/response. Server-pushed events (live updates, multi-tab sync, AI streaming) need a long-lived connection. ZealPHP has <code>App::ws()</code> for that — same handler shape as a route, but receives <code>$server</code> + <code>$frame</code> instead of <code>$request</code> + <code>$response</code>.</p><p>This very tutorial uses it: open <a href="/learn/notes">Lesson 8</a> in two tabs, add a note in one — the other tab updates without polling. The handler is <code>$app-&gt;ws(\'/ws/learn\', ...)</code> in <code>route/learn.php</code>; the client lives in <code>public/js/learn.js</code>.</p>',
    ]); ?>

    <h2>htmx vs. SSE</h2>
    <p>
      htmx has an SSE extension, but it's designed for opening a long-lived <code>EventSource</code> that pushes events into the page. For our AI chat we POST a message and consume the SSE response token-by-token — that's better handled with raw <code>fetch</code> + ReadableStream. Lesson 9 shows the chat client; htmx handles the rest of the interactivity.
    </p>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/sessions">← Sessions & Auth</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/notes">Build Personal Notes →</a>
    </div>
  </article>
</div>

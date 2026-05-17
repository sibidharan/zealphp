<?php use ZealPHP\App; $active = $active ?? 'learn/responses'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 9,
      'title'    => 'Returning a Response',
      'subtitle' => 'Most frameworks make you construct a response object. ZealPHP infers it from what you return — like a thoughtful waiter who doesn\'t need you to spell out medium-rare, no onions.',
      'prev'     => ['slug' => 'learn/injection',  'title' => 'Parameter Injection'],
      'next'     => ['slug' => 'learn/middleware', 'title' => 'Middleware: The Wrap'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'The six return-value conventions ZealPHP recognizes',
      'When to <code>return $data</code> vs reach for the <code>$response</code> object',
      'How streaming (Generators) fits into the same conventions',
      'What happens to <code>echo</code> output (and when it matters)',
    ]]); ?>

    <h2>The six conventions</h2>
    <p>
      Whatever your handler returns, ZealPHP’s <code>ResponseMiddleware</code> looks at the
      type and translates it into the right HTTP response. Six types, six behaviors:
    </p>
    <table class="cmp-table">
      <thead><tr><th>Return type</th><th>What happens</th><th>Example</th></tr></thead>
      <tbody>
        <tr><td><code>int</code></td><td>Status code, empty body</td><td><code>return 404;</code></td></tr>
        <tr><td><code>string</code></td><td>HTML body, 200 OK</td><td><code>return '&lt;h1&gt;Hello&lt;/h1&gt;';</code></td></tr>
        <tr><td><code>array</code> / <code>object</code></td><td>JSON-encoded, <code>Content-Type: application/json</code></td><td><code>return ['ok' =&gt; true];</code></td></tr>
        <tr><td><code>\Generator</code></td><td>Streaming — each <code>yield</code> sent immediately</td><td><code>yield "&lt;li&gt;{$row-&gt;name}&lt;/li&gt;";</code></td></tr>
        <tr><td><code>void</code> + <code>echo</code></td><td>Captured output buffer becomes the body</td><td><code>echo App::render(...);</code></td></tr>
        <tr><td><code>ResponseInterface</code></td><td>PSR-7 passthrough — sent verbatim</td><td><code>return $response-&gt;redirect('/');</code></td></tr>
      </tbody>
    </table>
    <p>
      The first three cover 90% of handlers. The other three exist for the cases where 90% isn’t
      enough.
    </p>

    <h2>The default path: return data</h2>
    <p>
      JSON APIs are the cleanest case. Return an array. Done.
    </p>
    <pre><code class="language-php">$app-&gt;route('/api/users/{id}', function ($id) {
    $user = User::find($id);
    return $user ? $user-&gt;toArray() : 404;
});</code></pre>
    <p>
      Note the <code>: 404</code> branch — an int return-value short-circuits to a status code.
      No <code>$response-&gt;status(404)-&gt;end()</code>; no <code>http_response_code(404); exit;</code>.
      The framework reads "404" and does the right thing.
    </p>

    <h2>When to reach for $response</h2>
    <p>
      The <code>$response</code> object is for cases that don’t fit a single return value:
      <strong>setting cookies, redirects, custom headers, streaming, sending a file</strong>. You
      mutate the response object, then either return the result or just <code>return</code>:
    </p>
    <pre><code class="language-php">$app-&gt;route('/logout', function ($response) {
    $response-&gt;cookie('session', '', time() - 3600, '/');
    return $response-&gt;redirect('/');
});</code></pre>
    <p>
      Same handler, the <code>redirect()</code> call returns a PSR-7 response which is then returned
      from the closure. ResponseMiddleware sees the <code>ResponseInterface</code> and ships it.
    </p>

    <h2>Streaming via Generator</h2>
    <p>
      Yield from your handler and you’re streaming. The framework flushes each yielded chunk
      immediately — no buffer, no waiting for the function to return.
    </p>
    <pre><code class="language-php">$app-&gt;route('/feed', function () {
    return (function () {
        yield "&lt;html&gt;&lt;body&gt;&lt;ul&gt;";
        foreach (Post::recent() as $p) {
            yield "&lt;li&gt;{$p-&gt;title}&lt;/li&gt;";
        }
        yield "&lt;/ul&gt;&lt;/body&gt;&lt;/html&gt;";
    })();
});</code></pre>
    <p>
      The next lesson on streaming covers the four patterns in detail. For now: <em>any Generator
      return triggers streaming mode</em>. The browser starts rendering before your handler finishes.
    </p>

    <h2>echo + void: the legacy escape hatch</h2>
    <p>
      You can still write PHP the old way — echo to stdout, let the framework capture it:
    </p>
    <pre><code class="language-php">$app-&gt;route('/about', function () {
    App::render('about', ['user' =&gt; auth()]);
    // no return — rendered output is captured via ob_get_clean()
});</code></pre>
    <p>
      <code>App::render()</code> echoes the rendered template. The handler returns void.
      ResponseMiddleware grabs the output buffer and sends it. This is how every WordPress page
      handler works, more or less.
    </p>
    <p>
      <em>Prefer returning a value when you can.</em> Returning is cheaper than buffering, easier to
      test, and works inside coroutines without surprises. But if you’re porting Apache code,
      echo-and-void is the bridge.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'deep',
      'title'   => 'Status codes without ceremony',
      'body'    => '<p>You don’t need a constant for "200 OK." Just <code>return $data</code> and you get a 200. To return any other status code with an empty body, return the int: <code>return 204;</code>, <code>return 304;</code>, <code>return 422;</code>. To return a non-200 with a body, use <code>$response-&gt;status(422)-&gt;json([...])</code>. The framework gets out of your way at the easy cases and helps when you need help.</p>',
    ]); ?>

    <h2>Try it live</h2>
    <p>
      Every response convention has a demo at <code>/demo/response/{method}</code>:
    </p>
    <ul>
      <li><a href="/demo/view/response/json" target="_blank">Return an array → JSON</a></li>
      <li><a href="/demo/view/response/redirect-301" target="_blank">301 permanent redirect</a> — <code>$response-&gt;redirect($url, 301)</code></li>
      <li><a href="/demo/view/response/redirect-302" target="_blank">302 redirect</a> — <code>$response-&gt;redirect($url)</code></li>
      <li><a href="/demo/view/response/headers" target="_blank">Custom headers</a> — <code>$response-&gt;header()</code></li>
      <li><a href="/demo/view/response/cookie" target="_blank">Set a cookie</a> — <code>$response-&gt;cookie()</code></li>
    </ul>

    <?php App::render('/components/_concept_check', [
      'id'       => 'resp1',
      'question' => 'Your handler is <code>function () { return "&lt;p&gt;hi&lt;/p&gt;"; }</code>. What Content-Type does the browser see?',
      'correct'  => 'b',
      'explain'  => 'A <code>string</code> return is treated as HTML by default. <code>Content-Type: text/html</code>. Return an array if you want JSON; return an int if you want just a status code; return a Generator if you want streaming.',
      'options'  => [
        'a' => '<code>application/json</code>',
        'b' => '<code>text/html</code>',
        'c' => '<code>text/plain</code> — the framework can\'t infer HTML from a bare string',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Return an <code>int</code> for status-only responses (<code>return 404</code>).',
      'Return a <code>string</code> for HTML, an <code>array</code> for JSON — the framework picks the Content-Type.',
      'Return a <code>Generator</code> to stream chunks as they\'re yielded.',
      'Reach for the <code>$response</code> object when you need cookies, headers, or redirects alongside the body.',
      '<code>echo</code> + void works for legacy code — the framework captures stdout, but returning is cleaner.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/injection"
         hx-get="/api/learn/page?slug=learn/injection" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/injection">← Parameter Injection</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/middleware"
         hx-get="/api/learn/page?slug=learn/middleware" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/middleware">Middleware: The Wrap →</a>
    </div>
  </article>
</div>

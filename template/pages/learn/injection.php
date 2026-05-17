<?php use ZealPHP\App; $active = $active ?? 'learn/injection'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 6,
      'title'    => 'Parameter Injection',
      'subtitle' => 'PHP is a permissive language. ZealPHP is a permissive language that also reads — specifically, the signature of every handler you write.',
      'prev'     => ['slug' => 'learn/lifecycle',  'title' => 'A Request\'s Journey'],
      'next'     => ['slug' => 'learn/responses',  'title' => 'Returning a Response'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'The four magic parameter names ZealPHP injects for you',
      'How URL params (like {id}) get pulled into your function signature',
      'Why this is cached at registration — not done per request',
      'What happens when you ask for something ZealPHP doesn\'t know about',
    ]]); ?>

    <h2>You declare. The framework fetches.</h2>
    <p>
      Most PHP frameworks pass you a request object and make you fish out what you need. Express
      gives you <code>(req, res, next)</code>. Laravel passes a <code>Request</code> instance you call
      methods on. Symfony hands you the kitchen sink and lets you sort it out.
    </p>
    <p>
      ZealPHP does something quieter: it <em>reads your function signature</em> with reflection,
      figures out what each parameter wants, and passes the right object by name. You write a
      handler that looks like a plain function. The framework treats the parameter list as a
      shopping list.
    </p>
    <pre><code class="language-php">$app-&gt;route('/users/{id}', function ($id, $request, $response) {
    return ['id' =&gt; $id, 'method' =&gt; $request-&gt;server['request_method']];
});</code></pre>
    <p>
      Three parameters, three different sources: <code>$id</code> from the URL pattern,
      <code>$request</code> from the framework, <code>$response</code> from the framework. You
      didn’t write a single line of plumbing.
    </p>

    <h2>The four magic names</h2>
    <p>ZealPHP recognizes four parameter-name patterns:</p>
    <table class="cmp-table">
      <thead><tr><th>If your parameter is named…</th><th>You get…</th></tr></thead>
      <tbody>
        <tr><td><code>$request</code></td><td>The <code>ZealPHP\HTTP\Request</code> wrapper — headers, query, body, cookies, files</td></tr>
        <tr><td><code>$response</code></td><td>The <code>ZealPHP\HTTP\Response</code> wrapper — <code>json()</code>, <code>redirect()</code>, <code>stream()</code>, <code>sse()</code>, <code>cookie()</code></td></tr>
        <tr><td><code>$app</code></td><td>The <code>ResponseMiddleware</code> instance — rarely needed, occasionally useful for sub-rendering</td></tr>
        <tr><td>Anything matching a <code>{name}</code> in the route</td><td>The captured URL segment as a string</td></tr>
      </tbody>
    </table>
    <p>
      Any other parameter with a default value gets its default. A parameter without a default that
      ZealPHP can’t resolve raises a clear error at request time — not a silent <code>null</code>.
    </p>

    <h2>Examples by parameter name</h2>
    <h3>1. Just the URL params</h3>
    <pre><code class="language-php">$app-&gt;route('/posts/{slug}', function ($slug) {
    return Post::findBySlug($slug);
});</code></pre>
    <p>Cleanest possible handler. ZealPHP returns the array as JSON.</p>

    <h3>2. URL params + request body</h3>
    <pre><code class="language-php">$app-&gt;route('/posts/{slug}/comment', ['method' =&gt; 'POST'],
    function ($slug, $request) {
        $body = $request-&gt;post['comment'] ?? '';
        Comment::create($slug, $body);
        return ['ok' =&gt; true];
    }
);</code></pre>

    <h3>3. URL params + response (for cookies / streaming / redirects)</h3>
    <pre><code class="language-php">$app-&gt;route('/login/{token}', function ($token, $response) {
    $response-&gt;cookie('session', $token, time() + 86400, '/', '', true, true);
    return $response-&gt;redirect('/dashboard');
});</code></pre>

    <h3>4. Mix everything</h3>
    <pre><code class="language-php">$app-&gt;route('/users/{id}/avatar', function ($id, $request, $response) {
    $size = (int)($request-&gt;get['size'] ?? 64);
    return $response-&gt;sendFile("storage/avatars/{$id}-{$size}.png");
});</code></pre>

    <h2>Why this is fast</h2>
    <p>
      Reflection is famously slow in PHP. ZealPHP runs it <em>once per route, at registration time</em>,
      builds a parameter map (just an array of "this position gets the URL param named id, that one
      gets the request object"), and caches it on the route. At request time, the framework walks
      the cached map, not the reflection metadata.
    </p>
    <p>
      The result: parameter injection costs you an array walk and a few function calls. The
      reflection cost is amortized across every request the worker handles — thousands of them
      — and in coroutine mode, the worker handles a lot.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Comparison: Express handler vs ZealPHP handler',
      'body'    => '<p>An Express middleware always looks like <code>function(req, res, next) { ... }</code> — same signature, every time, even if you don’t use all three. A ZealPHP handler looks like whatever you need: <code>function($id) {}</code>, <code>function($request, $response) {}</code>, <code>function() {}</code>. The framework reads your signature instead of forcing one on you. <em>Tell ZealPHP what you want by naming your variables.</em></p>',
    ]); ?>

    <h2>Try it live</h2>
    <p>
      Every parameter-injection case is wired up in the demo app at <code>/demo/inject/{case}</code>.
      Visit a few to see what gets injected:
    </p>
    <ul>
      <li><a href="/demo/inject/simple">/demo/inject/simple</a> — URL param only</li>
      <li><a href="/demo/inject/request">/demo/inject/request</a> — <code>$request</code> dumped</li>
      <li><a href="/demo/inject/response">/demo/inject/response</a> — <code>$response</code> used to set a custom header</li>
      <li><a href="/demo/inject/mixed">/demo/inject/mixed</a> — URL param + <code>$request</code> + <code>$response</code></li>
    </ul>

    <?php App::render('/components/_concept_check', [
      'id'       => 'inject1',
      'question' => 'You register <code>$app-&gt;route("/posts/{slug}", function ($request, $slug) {...})</code>. Does the order of parameters matter?',
      'correct'  => 'b',
      'explain'  => 'Parameter injection is by name, not position. <code>$request</code> matches "give me the request object" regardless of where it sits in the signature, and <code>$slug</code> matches the URL placeholder. Reorder them any way you want.',
      'options'  => [
        'a' => 'Yes — URL params must come first.',
        'b' => 'No — injection is by name, not position.',
        'c' => 'Yes — <code>$request</code> must be first because it\'s the framework object.',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Four magic names: <code>$request</code>, <code>$response</code>, <code>$app</code>, and any URL <code>{placeholder}</code>.',
      'Parameter order in your handler signature doesn\'t matter — injection is by name.',
      'Reflection runs once at route registration; the parameter map is cached — per-request injection is just an array walk.',
      'Any parameter with a default value gets its default if not injected.',
      'Parameters ZealPHP can\'t resolve raise an error — never silently get <code>null</code>.',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/lifecycle"
         hx-get="/api/learn/page?slug=learn/lifecycle" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/lifecycle">← A Request’s Journey</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/responses"
         hx-get="/api/learn/page?slug=learn/responses" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/responses">Returning a Response →</a>
    </div>
  </article>
</div>

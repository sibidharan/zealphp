<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Components & Views</h1>
<p class="section-desc">No Blade. No Twig. No Mustache. <strong>PHP IS the component engine.</strong> ZealPHP components are plain <code>.php</code> files — loops, conditionals, expressions, classes, everything you know works. Zero learning curve, full language power.</p>

<h2>Pass data, render a component</h2>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1.5rem 0">
<div>
<?php App::render('/components/_code', [
    'label' => 'Route handler',
    'code'  => <<<'PHP'
$app->route('/users/{id}', function($id) {
    $user = User::find($id);
    if (!$user) return 404;

    App::render('profile', [
        'user'    => $user,
        'posts'   => $user->posts(),
        'isAdmin' => $user->role === 'admin',
    ]);
});
PHP]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'template/profile.php',
    'code'  => <<<'PHP'
<h1><?= htmlspecialchars($user->name) ?></h1>

<?php if ($isAdmin): ?>
  <span class="badge">Admin</span>
<?php endif; ?>

<h2>Posts (<?= count($posts) ?>)</h2>
<ul>
  <?php foreach ($posts as $post): ?>
    <li>
      <a href="/post/<?= $post->id ?>">
        <?= htmlspecialchars($post->title) ?>
      </a>
      <small><?= $post->created_at ?></small>
    </li>
  <?php endforeach; ?>
</ul>
PHP]); ?>
</div>
</div>

<p>Every key in the <code>$args</code> array becomes a local variable in the component via <code>extract()</code>. No magic syntax — just PHP.</p>

<h2>Layouts & composition</h2>
<p>Components can render other components. Build a layout system with a single master layout composing smaller components:</p>

<?php App::render('/components/_code', [
    'label' => 'public/about.php — page entry (3 lines)',
    'code'  => <<<'PHP'
<?php use ZealPHP\App;
App::render('_master', ['title' => 'About Us', 'page' => 'about']);
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => 'template/_master.php — layout wrapper',
    'code'  => <<<'PHP'
<!doctype html>
<html>
<head><title><?= htmlspecialchars($title) ?></title></head>
<body>
  <?php App::render('_nav', ['active' => $page]) ?>

  <main>
    <?php App::render("/pages/$page") ?>
  </main>

  <?php App::render('_footer') ?>
</body>
</html>
PHP]); ?>

<div class="callout info">
This is exactly how the ZealPHP docs site works — every page in <code>public/</code> is 3 lines that call <code>App::render('_master', [...])</code>. The master template renders the nav, the page content, and the footer. <strong>No template inheritance syntax needed — it's just PHP includes.</strong>
</div>

<h2>Components with slots</h2>
<p>Reusable UI components that accept data as arguments:</p>

<?php App::render('/components/_code', [
    'label' => 'template/components/_card.php',
    'code'  => <<<'PHP'
<div class="card">
  <div class="card-icon"><?= $icon ?></div>
  <h3><?= htmlspecialchars($title) ?></h3>
  <p><?= htmlspecialchars($body) ?></p>
  <?php if (!empty($href)): ?>
    <a href="<?= htmlspecialchars($href) ?>">Read more</a>
  <?php endif; ?>
</div>
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => 'Using the component in any template',
    'code'  => <<<'PHP'
<?php foreach ($features as $f): ?>
  <?php App::render('/components/_card', [
      'icon'  => $f['icon'],
      'title' => $f['name'],
      'body'  => $f['desc'],
      'href'  => $f['url'],
  ]) ?>
<?php endforeach; ?>
PHP]); ?>

<h2>Path resolution</h2>
<table class="ztable">
<tr><th>Call</th><th>Resolves to</th><th>When</th></tr>
<tr><td><code>App::render('home')</code></td><td><code>template/home.php</code></td><td>Top-level template</td></tr>
<tr><td><code>App::render('/components/_card')</code></td><td><code>template/components/_card.php</code></td><td>Leading <code>/</code> = absolute from <code>template/</code></td></tr>
<tr><td><code>App::render('header')</code> from <code>public/users.php</code></td><td><code>template/users/header.php</code></td><td>Auto-namespaces by current public file</td></tr>
<tr><td><code>App::render('header')</code> (fallback)</td><td><code>template/header.php</code></td><td>If namespaced path doesn't exist</td></tr>
</table>

<h2 id="file-execution-family">The file-execution family — five ways to run a PHP file through the framework</h2>
<p>The first four share a single private core (<code>App::executeFile()</code>) that runs the file, captures output, and applies the <a href="/responses#return-contract">universal return contract</a>. They differ only on (a) where the path is resolved from and (b) what the wrapper does with the result. The fifth — <code>App::fragment()</code> — runs <em>inside</em> a template and marks a named region the framework can extract by name. See the <a href="#fragments">fragments section</a> below.</p>

<table class="ztable" style="margin-bottom:1.5rem">
<tr><th>Method</th><th>Path resolved from</th><th>Returns</th><th>Use when</th></tr>
<tr>
  <td><code>App::render($tpl, $args)</code></td>
  <td><code>template/</code> (with <code>.php</code> suffix)</td>
  <td><code>mixed</code> — full <a href="/responses#return-contract">return contract</a>. <strong>BC:</strong> if the template only echoes (no explicit <code>return</code>), the captured output is echoed back — keeps every <code>App::render('_master', …)</code> call site working unchanged.</td>
  <td>Direct output in a route handler or inside another template; the bread-and-butter render call</td>
</tr>
<tr>
  <td><code>App::renderToString($tpl, $args)</code></td>
  <td><code>template/</code></td>
  <td><code>string</code> — coerces every shape (Generator consumed, Closure invoked, scalar cast)</td>
  <td>Need the HTML as a value: email body, cache entry, or to pass into another renderer</td>
</tr>
<tr>
  <td><code>App::renderStream($tpl, $args)</code></td>
  <td><code>template/</code></td>
  <td><code>\Generator</code> — yields whatever the template returned, chunk-by-chunk</td>
  <td>SSR streaming. Works with regular echo templates AND streaming-Closure templates uniformly</td>
</tr>
<tr>
  <td><code>App::include($publicPath, $args = [])</code></td>
  <td><code>public/</code> (Apache document-root convention — leading <code>/</code> optional)</td>
  <td><code>mixed</code> — full <a href="/responses#return-contract">return contract</a>, never echoed (always returned so it threads through <code>ResponseMiddleware</code>). Auto-populates <code>$_SERVER['PHP_SELF']</code>, <code>SCRIPT_NAME</code>, <code>SCRIPT_FILENAME</code> for the included file (Apache mod_php parity).</td>
  <td>Apache rewrites — <code>$app-&gt;route('/old-page', fn() =&gt; App::include('/new.php'))</code> serves <code>public/new.php</code> in-process with the URL bar still at <code>/old-page</code>. See <a href="/legacy-apps">Legacy Apps</a> for the 12 rewrite recipes</td>
</tr>
<tr>
  <td><code>App::fragment($name, $fn)</code> <span class="badge" style="font-size:.65rem;background:#fbbf24;color:#1c1917;padding:.05rem .35rem;border-radius:3px;margin-left:.25rem">v0.2.24</span></td>
  <td>N/A — called <em>inside</em> a template, not on a path</td>
  <td><code>void</code>. The closure's return rides the full return contract when the fragment is extracted (the parent <code>App::render()</code> propagates it back through <code>ResponseMiddleware</code>).</td>
  <td>Mark a named region inside a template so the same <code>App::render('page', $args)</code> call can serve either the full page (no selector) or just that one region (<code>$args['fragment'] = 'name'</code>). The htmx-essay <a href="/learn/htmx#fragments">"one file, two responses"</a> pattern.</td>
</tr>
</table>

<p style="color:var(--text-muted);font-size:.92rem"><code>App::includeFile()</code> is the deprecated alias for <code>App::include()</code> — kept for backward compatibility (the WordPress showcase and existing scaffolds still call it). New code should use <code>App::include()</code>.</p>

<h3 id="fragments" style="margin-top:1.5rem">Template fragments — one file, two responses</h3>
<p>
  <code>App::fragment($name, $fn)</code> turns any template into a dual-mode file: the same <code>App::render('page', $args)</code> call serves <strong>either</strong> the complete page (no fragment selector → every <code>App::fragment()</code> block runs inline) <strong>or</strong> just one named region (<code>$args['fragment'] = 'name'</code> → that region's buffer is cleared, only its closure runs, the rest of the template short-circuits via <code>HaltException</code>). Same template, same route handler, two different responses on the same URL — the <a href="https://htmx.org/essays/template-fragments/" target="_blank" rel="noopener">htmx-essay template-fragment</a> pattern without separate partial files.
</p>

<?php App::render('/components/_code', [
  'label' => 'template/contacts/list.php — one template, both responses',
  'code'  => <<<'PHP'
<ul id="contacts">
<?php foreach ($contacts as $c): ?>
  <?php App::fragment("contact-{$c['id']}", function() use ($c) { ?>
    <li id="contact-<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></li>
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
        // No selector → full <ul> with every row inline.
        // ?fragment=contact-2 → just that one <li> on the wire.
        'fragment' => is_string($g->get['fragment'] ?? null) ? $g->get['fragment'] : null,
    ]);
});
PHP,
]); ?>

<p>Inside the closure, the universal contract applies — <code>return 404;</code> for auth, <code>return ['id'=>1];</code> for JSON, <code>return (fn(){ yield ...; })();</code> for streaming. Three behaviours worth knowing:</p>

<ul style="margin:.5rem 0;line-height:1.7">
  <li><strong>Missing fragment → 404</strong> per the <a href="/responses#return-contract">universal return contract</a>. Asking for <code>?fragment=does-not-exist</code> doesn't silently fall back to the full page.</li>
  <li><strong>First match wins</strong> when the same name appears twice — the first block extracts, the rest of the template short-circuits.</li>
  <li><strong>Nested renders compose</strong> — an <code>App::render()</code> called from inside a fragment closure does <em>not</em> inherit the parent's fragment selector. Each render's scope is saved+restored.</li>
</ul>

<?php App::render('/components/_tryit', [
  'title' => 'Live demo — the contacts list',
  'body'  => '<p style="margin:.25rem 0">'
          .  '<a href="/demo/fragments/contacts" target="_blank" rel="noopener" style="color:#fbbf24">/demo/fragments/contacts</a>'
          .  ' — 4 contacts, each row swaps in place via <code>hx-get="?fragment=contact-N"</code>. Open DevTools → Network → XHR to confirm each click is a single 200 with just the <code>&lt;li&gt;</code> in the body.</p>'
          .  '<p style="margin:.25rem 0;font-size:.85rem;color:#9ca3af">Full walk-through: <a href="/learn/htmx#fragments" style="color:#fbbf24">Forms &amp; htmx — Template fragments</a>.</p>',
]); ?>

<h2>SSR Streaming — yield from templates</h2>
<p><code>App::renderStream()</code> returns a Generator. If the template file returns a Generator (via IIFE), it delegates with <code>yield from</code>. If the template echoes normally, the output is captured and yielded as one chunk. <strong>Both patterns compose in the same streaming pipeline.</strong></p>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin:1.5rem 0">
<div>
<?php App::render('/components/_code', [
    'label' => 'Streaming template (template/users/stream.php)',
    'code'  => <<<'PHP'
<?php
// Declare what data this template needs —
// framework injects by name (like route handlers)
return function($users) {
    yield "<section class='users'>";
    foreach ($users as $user) {
        yield "<div class='card'>"
            . htmlspecialchars($user->name)
            . "</div>\n";
    }
    yield "</section>";
};
PHP]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'Route handler — compose streams',
    'code'  => <<<'PHP'
$app->route('/users', function() {
    return (function() {
        // Regular template → single chunk
        yield from App::renderStream(
            'shell-open', ['title' => 'Users']
        );

        // Streaming template → per-user chunks
        yield from App::renderStream(
            'users/stream',
            ['users' => User::all()]
        );

        yield from App::renderStream('shell-close');
    })();
});
PHP]); ?>
</div>
</div>

<p>The template declares <code>function($users)</code> — the framework injects <code>$users</code> from the args array by name, exactly like route parameter injection. Each <code>yield</code> flushes to the browser immediately.</p>

<h3>Three streaming template styles</h3>
<table class="ztable">
<tr><th>Style</th><th>Template code</th><th>Best for</th></tr>
<tr>
  <td>Closure (cleanest)</td>
  <td><code>return function($users) { yield ...; };</code></td>
  <td>New streaming templates — parameter injection, no <code>use()</code> needed</td>
</tr>
<tr>
  <td>IIFE Generator</td>
  <td><code>return (function() use ($users) { yield ...; })();</code></td>
  <td>When you need variables from the include scope via <code>use()</code></td>
</tr>
<tr>
  <td>Regular echo</td>
  <td><code>&lt;h1&gt;&lt;?= $title ?&gt;&lt;/h1&gt;</code></td>
  <td>Non-streaming templates — output captured as one chunk</td>
</tr>
</table>
<p>All three compose in the same <code>yield from App::renderStream(...)</code> pipeline.</p>

<h2>Yield from everywhere</h2>
<p>Generators work in route handlers, public/ files, API handlers, and template files:</p>

<table class="ztable">
<tr><th>Location</th><th>How to stream</th><th>Example</th></tr>
<tr>
  <td>Route handler</td>
  <td>Return a Generator directly</td>
  <td><code>return (function() { yield "chunk"; })();</code></td>
</tr>
<tr>
  <td>Public file</td>
  <td>Return a Generator from the file</td>
  <td><code>public/feed.php</code> → <code>&lt;?php return (function() { yield "..."; })();</code></td>
</tr>
<tr>
  <td>API handler</td>
  <td>Return a Generator from <code>$get</code>/<code>$post</code></td>
  <td><code>$get = function() { return (function() { yield ...; })(); };</code></td>
</tr>
<tr>
  <td>Template</td>
  <td>Return a Closure via <code>renderStream()</code></td>
  <td><code>return function($items) { yield ...; };</code></td>
</tr>
</table>

<?php App::render('/components/_code', [
    'label' => 'public/feed.php — a streaming public page',
    'code'  => <<<'PHP'
<?php
// File: public/feed.php → GET /feed
// Returns a Generator — framework streams each yield to the browser
use ZealPHP\App;

return (function() {
    yield App::renderToString('shell-open', ['title' => 'Live Feed']);
    yield "<h1>Feed</h1>";

    foreach (fetchFeedItems() as $item) {
        yield "<article>{$item->title}</article>\n";
    }

    yield App::renderToString('shell-close');
})();
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => 'api/events/stream.php — a streaming API endpoint',
    'code'  => <<<'PHP'
<?php
// File: api/events/stream.php → GET /api/events/stream
$stream = function() {
    return (function() {
        yield '{"events":[';
        $first = true;
        foreach (Event::cursor() as $event) {
            if (!$first) yield ',';
            yield json_encode($event);
            $first = false;
        }
        yield ']}';
    })();
};
PHP]); ?>

<h2>PHP template patterns cheat sheet</h2>
<table class="ztable">
<tr><th>Pattern</th><th>PHP</th></tr>
<tr><td>Output a variable</td><td><code>&lt;?= $name ?&gt;</code></td></tr>
<tr><td>Escape HTML</td><td><code>&lt;?= htmlspecialchars($input) ?&gt;</code></td></tr>
<tr><td>Conditional</td><td><code>&lt;?php if ($cond): ?&gt; ... &lt;?php endif; ?&gt;</code></td></tr>
<tr><td>Loop</td><td><code>&lt;?php foreach ($items as $i): ?&gt; ... &lt;?php endforeach; ?&gt;</code></td></tr>
<tr><td>Include component</td><td><code>&lt;?php App::render('/components/_card', $args) ?&gt;</code></td></tr>
<tr><td>Ternary default</td><td><code>&lt;?= $subtitle ?? 'Default' ?&gt;</code></td></tr>
<tr><td>Format number</td><td><code>&lt;?= number_format($price, 2) ?&gt;</code></td></tr>
<tr><td>Date format</td><td><code>&lt;?= date('M j, Y', strtotime($created)) ?&gt;</code></td></tr>
<tr><td>Raw HTML (trusted)</td><td><code>&lt;?= $trusted_html ?&gt;</code></td></tr>
<tr><td>JSON encode</td><td><code>&lt;script&gt;const data = &lt;?= json_encode($data) ?&gt;&lt;/script&gt;</code></td></tr>
</table>

<div class="callout warn" style="margin-top:1.5rem">
<strong>Always escape user data</strong> with <code>htmlspecialchars()</code>. PHP templates have no auto-escaping — you get full control, which means full responsibility.
</div>

<h2>Why PHP over Blade/Twig/Mustache?</h2>
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-top:1rem">
  <div class="card" style="padding:1rem"><strong>Zero learning curve</strong><br>No new syntax. If you know PHP, you know the component engine.</div>
  <div class="card" style="padding:1rem"><strong>Full language power</strong><br>Classes, closures, exceptions, generators — not a subset.</div>
  <div class="card" style="padding:1rem"><strong>No compile step</strong><br>No cache directory. Components are interpreted directly.</div>
  <div class="card" style="padding:1rem"><strong>IDE support</strong><br>Autocompletion, type checking, refactoring — all free.</div>
  <div class="card" style="padding:1rem"><strong>SSR streaming</strong><br>Components can <code>yield</code>. Progressive rendering built in.</div>
  <div class="card" style="padding:1rem"><strong>Composable</strong><br>Render inside render. No "extends", no "blocks" — just function calls.</div>
</div>

</div>
</section>

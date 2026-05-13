<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Templates & Views</h1>
<p class="section-desc">No Blade. No Twig. No Mustache. <strong>PHP IS the template engine.</strong> ZealPHP templates are plain <code>.php</code> files — loops, conditionals, expressions, classes, everything you know works. Zero learning curve, full language power.</p>

<h2>Pass data, render a template</h2>

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

<p>Every key in the <code>$args</code> array becomes a local variable in the template via <code>extract()</code>. No magic syntax — just PHP.</p>

<h2>Layouts & composition</h2>
<p>Templates can render other templates. Build a layout system with a single master template and components:</p>

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

<h2>Three render methods</h2>
<table class="ztable">
<tr><th>Method</th><th>Returns</th><th>Use when</th></tr>
<tr><td><code>App::render($tpl, $args)</code></td><td><code>void</code> (echoes)</td><td>Direct output in route handler or another template</td></tr>
<tr><td><code>App::renderToString($tpl, $args)</code></td><td><code>string</code></td><td>Need HTML as value — email, cache, or <code>yield</code></td></tr>
<tr><td><code>App::renderStream($tpl, $args)</code></td><td><code>Generator</code></td><td>SSR streaming — works with both regular and streaming templates</td></tr>
</table>

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
  <div class="card" style="padding:1rem"><strong>Zero learning curve</strong><br>No new syntax. If you know PHP, you know the template engine.</div>
  <div class="card" style="padding:1rem"><strong>Full language power</strong><br>Classes, closures, exceptions, generators — not a subset.</div>
  <div class="card" style="padding:1rem"><strong>No compile step</strong><br>No cache directory. Templates are interpreted directly.</div>
  <div class="card" style="padding:1rem"><strong>IDE support</strong><br>Autocompletion, type checking, refactoring — all free.</div>
  <div class="card" style="padding:1rem"><strong>SSR streaming</strong><br>Templates can <code>yield</code>. Progressive rendering built in.</div>
  <div class="card" style="padding:1rem"><strong>Composable</strong><br>Render inside render. No "extends", no "blocks" — just function calls.</div>
</div>

</div>
</section>

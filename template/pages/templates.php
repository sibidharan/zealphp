<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Templates & Views</h1>
<p class="section-desc"><code>App::render()</code> and <code>App::renderToString()</code> — PHP-native templating with smart path resolution and SSR streaming.</p>

<h2>Basic Render</h2>
<p>Pass a template name and a data array. Variables are extracted into the template scope.</p>

<?php App::render('/components/_code', [
    'label' => 'Route handler + template',
    'code'  => <<<'PHP'
// In your route handler:
$app->route('/welcome/{name}', function($name) {
    App::render('welcome', ['name' => $name, 'tagline' => 'Welcome aboard']);
});

// template/welcome.php — vars from $args become locals:
<h1>Hello, <?= htmlspecialchars($name) ?></h1>
<p><?= htmlspecialchars($tagline) ?></p>
PHP]); ?>

<h2>Path Resolution</h2>
<p>Templates live in <code>template/</code> by default. The resolver tries paths in this order:</p>

<table class="ztable">
<tr><th>Call</th><th>Resolves to</th><th>When</th></tr>
<tr><td><code>App::render('home')</code></td><td><code>template/home.php</code></td><td>Top-level template</td></tr>
<tr><td><code>App::render('/components/_card')</code></td><td><code>template/components/_card.php</code></td><td>Leading slash = absolute from <code>template/</code></td></tr>
<tr><td><code>App::render('header')</code> from <code>public/users.php</code></td><td><code>template/users/header.php</code> (if exists)</td><td>Auto-namespaces by current public file</td></tr>
<tr><td><code>App::render('header')</code> (fallback)</td><td><code>template/header.php</code></td><td>If namespaced path doesn't exist</td></tr>
</table>

<div class="callout info">
The <strong>auto-namespacing</strong> means a page in <code>public/users.php</code> can call <code>App::render('row')</code> and ZealPHP looks for <code>template/users/row.php</code> first — keeping page-specific templates organized by route.
</div>

<h2>Component Composition</h2>
<p>Templates can render other templates. This is how the demo site is built — pages compose components:</p>

<?php App::render('/components/_code', [
    'label' => 'template/pages/users.php composes components',
    'code'  => <<<'PHP'
<?php use ZealPHP\App; ?>
<section class="section">
  <h1>Users</h1>

  <?php foreach ($users as $user): ?>
    <?php App::render('/components/_card', [
        'icon'  => '👤',
        'title' => $user['name'],
        'body'  => $user['email'],
        'href'  => "/user/{$user['id']}",
    ]); ?>
  <?php endforeach; ?>

  <?php App::render('/components/_code', [
      'label' => 'Try it',
      'code'  => 'curl localhost:8080/api/users',
      'lang'  => 'bash',
  ]); ?>
</section>
PHP]); ?>

<h2>renderToString — Capture Output</h2>
<p>Use <code>renderToString()</code> when you need the rendered HTML as a string — for emails, caching, or SSR streaming with <code>yield</code>:</p>

<?php App::render('/components/_code', [
    'label' => 'Three uses for renderToString',
    'code'  => <<<'PHP'
use ZealPHP\App;

// 1. Capture for an email
$html = App::renderToString('emails/welcome', ['name' => 'Alice']);
mail($to, 'Welcome', $html, "Content-Type: text/html\r\n");

// 2. Cache a heavy page
$html = $cache->remember('home', 60, fn() =>
    App::renderToString('home', ['posts' => fetchPosts()])
);

// 3. Combine with Generator streaming (see below)
yield App::renderToString('header');
PHP]); ?>

<h2>SSR Streaming with App::render</h2>
<p>Return a <code>Generator</code> from a route handler. Each <code>yield</code> is sent to the client immediately — perfect for streaming a slow page section-by-section without blocking the shell.</p>

<?php App::render('/components/_code', [
    'label' => 'Stream the shell, then sections as they resolve',
    'code'  => <<<'PHP'
use ZealPHP\App;

$app->route('/dashboard', function() {
    return (function() {
        // 1. Send the HTML shell immediately
        yield App::renderToString('shell-open', ['title' => 'Dashboard']);
        yield "<div class='hero'>Welcome!</div>";

        // 2. Yield each section as its data becomes available
        yield App::renderToString('stats', ['stats' => fetchStats()]);
        yield App::renderToString('chart', ['data' => fetchChartData()]);
        yield App::renderToString('activity', ['events' => fetchRecent()]);

        // 3. Close the shell
        yield App::renderToString('shell-close');
    })();
});
PHP]); ?>

<p>Each <code>yield</code> flushes to the browser — the user sees the page progressively, instead of waiting for all data to load.</p>

<div class="callout info">
<strong>Pro tip:</strong> Combine SSR streaming with <code>go()</code> (coroutines) to fetch multiple data sources in parallel, then yield results as each completes — first available, first rendered.
</div>

<h2>Writing APIs with Templates</h2>
<p>Mix template rendering with JSON responses. The same route can return different formats based on Accept header or query param:</p>

<?php App::render('/components/_code', [
    'label' => 'Content negotiation',
    'code'  => <<<'PHP'
$app->route('/users/{id}', function($id, $request) {
    $user = User::find($id);
    if (!$user) return 404;  // int → status

    // JSON for API clients
    if (str_contains($request->header['accept'] ?? '', 'json')) {
        return $user->toArray();  // array → JSON
    }

    // HTML for browsers
    App::render('user/profile', ['user' => $user]);  // void → output buffer
});
PHP]); ?>

<h2>API + Template Combo Example</h2>
<p>Real-world pattern: a public PHP file uses templates for the page, ZealAPI handles the data:</p>

<?php App::render('/components/_code', [
    'label' => 'public/products.php — page',
    'code'  => <<<'PHP'
<?php
// File: public/products.php → GET /products
use ZealPHP\App;
App::render('_master', [
    'title' => 'Products',
    'page'  => 'products',
]);
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => 'api/products/list.php — data',
    'code'  => <<<'PHP'
<?php
// File: api/products/list.php → GET /api/products/list
$list = function() {
    return [
        'products' => Product::query()->get()->toArray(),
        'count'    => Product::count(),
    ];
};
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => 'template/products/index.php — view',
    'code'  => <<<'PHP'
<h1>Products</h1>
<div id="grid"></div>

<script>
fetch('/api/products/list')
  .then(r => r.json())
  .then(data => {
    const grid = document.getElementById('grid');
    data.products.forEach(p => {
      grid.innerHTML += `<div class="card">${p.name}</div>`;
    });
  });
</script>
PHP]); ?>

<h2>Method Reference</h2>
<table class="ztable">
<tr><th>Method</th><th>Returns</th><th>Use when</th></tr>
<tr>
  <td><code>App::render($tpl, $args = [])</code></td>
  <td>void (echoes to output buffer)</td>
  <td>Inside a route handler — output goes to the client</td>
</tr>
<tr>
  <td><code>App::renderToString($tpl, $args = [])</code></td>
  <td><code>string</code></td>
  <td>Need the HTML as a value — for streaming, caching, email, or passing to another template</td>
</tr>
</table>

<div class="callout warn" style="margin-top:1.5rem">
<strong>Always escape user data.</strong> Templates are plain PHP — use <code>htmlspecialchars()</code> on any variable that comes from user input. ZealPHP does not auto-escape.
</div>

</div>
</section>

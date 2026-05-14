<?php use ZealPHP\App; $active = $active ?? 'learn/components'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 4,
      'title'    => 'Components',
      'subtitle' => 'PHP templates are reusable components. Three render methods. Live demos.',
      'prev'     => ['slug' => 'learn/first-page', 'title' => 'Your First Page'],
      'next'     => ['slug' => 'learn/routing', 'title' => 'Routing'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Write reusable components as plain PHP templates',
      'Three render methods: render, renderToString, renderStream',
      'When to pick each one — with live API endpoints',
    ]]); ?>

    <h2>A template is a component</h2>
    <p>
      In ZealPHP, a <em>component</em> is just a PHP file that <code>echo</code>s HTML.
      There's no class, no framework registration, no JSX. Drop a file under
      <code>template/components/</code> and call it from anywhere with <code>App::render('/components/&lt;name&gt;', [...])</code>.
    </p>

    <pre><code>// template/components/_card.php
&lt;?php $variant ??= 'default'; ?&gt;
&lt;article class="card card-&lt;?= htmlspecialchars($variant) ?&gt;"&gt;
  &lt;h3&gt;&lt;?= htmlspecialchars($title) ?&gt;&lt;/h3&gt;
  &lt;p&gt;&lt;?= $body ?&gt;&lt;/p&gt;
&lt;/article&gt;</code></pre>

    <pre><code>// Anywhere in a page or route:
App::render('/components/_card', [
    'title'   =&gt; 'Hello',
    'body'    =&gt; 'World',
    'variant' =&gt; 'highlight',
]);</code></pre>

    <h2>React vs. PHP — side by side</h2>
    <p>The same component in React and in ZealPHP. Same outcome, simpler primitives:</p>
    <pre><code>// React
function Card({ title, body, variant = "default" }) {
  return (
    &lt;article className={`card card-${variant}`}&gt;
      &lt;h3&gt;{title}&lt;/h3&gt;
      &lt;p&gt;{body}&lt;/p&gt;
    &lt;/article&gt;
  );
}</code></pre>
    <pre><code>// ZealPHP (template/components/_card.php)
&lt;?php $variant ??= 'default'; ?&gt;
&lt;article class="card card-&lt;?= $variant ?&gt;"&gt;
  &lt;h3&gt;&lt;?= htmlspecialchars($title) ?&gt;&lt;/h3&gt;
  &lt;p&gt;&lt;?= $body ?&gt;&lt;/p&gt;
&lt;/article&gt;</code></pre>
    <p>No bundler. No transpiler. No hydration. The HTML is generated server-side and sent to the browser as-is.</p>

    <h2>Three render methods</h2>
    <table class="ztable" style="width:100%;border-collapse:collapse;margin:1rem 0;font-size:.9rem">
      <thead style="text-align:left"><tr style="border-bottom:2px solid #e7e5e4">
        <th style="padding:.5rem">Method</th><th>Returns</th><th>Use when</th>
      </tr></thead>
      <tbody>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.5rem"><code>App::render($tpl, $args)</code></td><td><code>void</code> (echoes)</td><td>Direct output in route handler or inside another template</td></tr>
        <tr style="border-bottom:1px solid #f5f5f4"><td style="padding:.5rem"><code>App::renderToString($tpl, $args)</code></td><td><code>string</code></td><td>Compose HTML — for htmx fragments, email bodies, SSE events</td></tr>
        <tr><td style="padding:.5rem"><code>App::renderStream($tpl, $args)</code></td><td><code>Generator</code></td><td>SSR streaming — chunks flushed as they're yielded</td></tr>
      </tbody>
    </table>

    <h2>Three render methods, three demos</h2>
    <p>Same template (<code>template/components/_demo_clock.php</code>), three different APIs. The output is visually similar — the difference is in <em>how</em> the HTTP response is produced.</p>

    <?php App::render('/components/_tryit', ['title' => 'App::render() — direct echo', 'body' => <<<HTML
      <p><code>App::render(\$tpl, \$args)</code> echoes the template's HTML. ZealPHP captures the output buffer and sends it as the response.</p>
      <p><a class="lesson-chip" href="/api/learn/demo/render" target="_blank">Open /api/learn/demo/render →</a></p>
      <pre><code>curl http://localhost:8080/api/learn/demo/render</code></pre>
HTML]); ?>

    <?php App::render('/components/_tryit', ['title' => 'App::renderToString() — composable HTML', 'body' => <<<HTML
      <p><code>App::renderToString(\$tpl, \$args)</code> returns the HTML as a string so you can wrap, cache, email, or stream it inside an SSE event.</p>
      <p><a class="lesson-chip" href="/api/learn/demo/render-to-string" target="_blank">Open /api/learn/demo/render-to-string →</a></p>
      <pre><code>curl http://localhost:8080/api/learn/demo/render-to-string</code></pre>
HTML]); ?>

    <?php App::render('/components/_tryit', ['title' => 'App::renderStream() — chunked SSR', 'body' => <<<HTML
      <p><code>App::renderStream(\$tpl, \$args)</code> returns a Generator. Each <code>yield</code> is flushed immediately — perfect for SSR shells, long lists, or AI token streams. The demo below sleeps 0.25s between rows so the streaming is visible.</p>
      <p><a class="lesson-chip" href="/api/learn/demo/render-stream" target="_blank">Open /api/learn/demo/render-stream →</a></p>
      <pre><code>curl -N http://localhost:8080/api/learn/demo/render-stream
# -N disables curl's output buffering so you can watch rows arrive.</code></pre>
HTML]); ?>

    <?php App::render('/components/_deepdive', [
      'title' => 'Why the leading slash?',
      'body'  => '<p>If the template name starts with <code>/</code>, ZealPHP looks in <code>template/</code> directly. Without the slash, it first checks <code>template/&lt;basename-of-current-file&gt;/&lt;name&gt;.php</code> — useful for page-local components scoped to one URL. Most of the time you want the root lookup, so use the leading slash.</p>',
    ]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/first-page">← Your First Page</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/routing">Routing →</a>
    </div>
  </article>
</div>

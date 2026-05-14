<?php use ZealPHP\App; $active = $active ?? 'learn/first-page'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 3,
      'title'    => 'Your First Page',
      'subtitle' => 'Implicit public routing — drop a PHP file in public/, get a URL for free.',
      'prev'     => ['slug' => 'learn/create-app', 'title' => 'Create a ZealPHP App'],
      'next'     => ['slug' => 'learn/components', 'title' => 'Components'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Drop a PHP file in public/ and ZealPHP serves it at /filename',
      'Echo plain HTML or call App::render to use a layout template',
      'Pass data from the entry file to its template via render args',
    ]]); ?>

    <h2>The simplest possible page</h2>
    <p>Create <code>public/hello.php</code>:</p>
    <pre><code>&lt;?php
echo '&lt;h1&gt;Hello from ZealPHP&lt;/h1&gt;';
echo '&lt;p&gt;The current time is ' . date('H:i:s') . '&lt;/p&gt;';</code></pre>
    <p>Restart the server (or just save — the worker reloads on demand) and hit <code>http://localhost:8080/hello</code>. That's it. No route registration, no URL config. The file's name is the URL.</p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'The implicit public-file rule',
      'body'    => '<p><code>public/&lt;name&gt;.php</code> &rarr; <code>GET /&lt;name&gt;</code>. Subdirectories work too: <code>public/blog/post.php</code> &rarr; <code>GET /blog/post</code>. Lesson 5 covers nested routes in depth.</p>',
    ]); ?>

    <h2>Add a layout</h2>
    <p>Every interesting site has a shared header, footer, nav. Use <code>App::render('_master', [...])</code> to inject your page into a layout template:</p>
    <pre><code>&lt;?php use ZealPHP\App;
App::render('/_master', [
    'title' =&gt; 'Hello',
    'page'  =&gt; 'hello',
]);</code></pre>
    <p>This page you're reading is exactly five lines of PHP that call <code>App::render('/_master', [...])</code>. The <code>'page'</code> argument tells the master template which file under <code>template/pages/</code> to render as the body.</p>

    <?php App::render('/components/_callout', [
      'variant' => 'deep',
      'title'   => 'Why the leading slash?',
      'body'    => '<p><code>/_master</code> tells <code>App::render</code> to look in <code>template/_master.php</code>. Without the slash, the framework first checks <code>template/&lt;current-page-basename&gt;/_master.php</code> — useful for page-local components, but here we want the global layout. The Components lesson covers this in detail.</p>',
    ]); ?>

    <h2>Pass data to the template</h2>
    <p>The second argument to <code>App::render</code> is an array of variables that the template can read by name:</p>
    <pre><code>// public/about.php
App::render('/_master', [
    'title'   =&gt; 'About',
    'page'    =&gt; 'about',
    'author'  =&gt; 'Alice',
    'updated' =&gt; '2026-05-15',
]);</code></pre>
    <pre><code>&lt;!-- template/pages/about.php --&gt;
&lt;h1&gt;About&lt;/h1&gt;
&lt;p&gt;By &lt;?= htmlspecialchars($author) ?&gt; — last updated &lt;?= $updated ?&gt;&lt;/p&gt;</code></pre>
    <p>Variables are extracted into the template's scope. No props object, no React-like wrapper — just PHP variables.</p>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/create-app">← Create a ZealPHP App</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/components">Components →</a>
    </div>
  </article>
</div>

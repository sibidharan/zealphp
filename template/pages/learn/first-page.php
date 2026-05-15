<?php use ZealPHP\App; $active = $active ?? 'learn/first-page'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 3,
      'title'    => 'Your First Page',
      'subtitle' => 'Drop a file, get a URL. The filesystem is the router.',
      'prev'     => ['slug' => 'learn/create-app', 'title' => 'Create a ZealPHP App'],
      'next'     => ['slug' => 'learn/components', 'title' => 'Layouts & Components'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Create a page by adding a file to public/',
      'How implicit routing maps files to URLs',
      'Add dynamic content with PHP',
      'Handle query parameters',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      In most frameworks, adding a new page means editing a routing table, creating a controller,
      registering it somewhere, and maybe writing a view. That's a lot of ceremony for "show some HTML."
    </p>
    <p>
      ZealPHP takes a different approach: <strong>the filesystem is the router</strong>.
    </p>

    <h2>Step 1: Create a file</h2>
    <p>Create a file at <code>public/greeting.php</code>:</p>
    <pre><code class="language-php">&lt;?php
echo "&lt;h1&gt;Hello, World!&lt;/h1&gt;";
echo "&lt;p&gt;This page was served by ZealPHP.&lt;/p&gt;";</code></pre>

    <h2>Step 2: Visit the URL</h2>
    <p>Open <code>http://localhost:8080/greeting</code> in your browser. That's it &mdash; no routing
      config, no controller registration. ZealPHP saw a file at <code>public/greeting.php</code> and
      mapped it to <code>GET /greeting</code> automatically.</p>

    <h2>How implicit routing works</h2>
    <p>
      Think of the <code>public/</code> folder as a <strong>filing cabinet</strong>. Folders are drawers,
      files are documents. The URL is the path you'd describe to find a document:
    </p>
    <pre><code>public/index.php       &rarr; GET /
public/about.php       &rarr; GET /about
public/blog/post.php   &rarr; GET /blog/post
public/css/site.css    &rarr; GET /css/site.css  (static files too)</code></pre>
    <p>
      No configuration. No routing table. The file's location <em>is</em> its URL. If you rename
      the file, the URL changes. If you delete the file, the URL returns 404.
    </p>

    <h2>Adding dynamic content</h2>
    <p>These are PHP files, so you can use any PHP you want. Let's make the greeting personal:</p>
    <pre><code class="language-php">&lt;?php
$name = htmlspecialchars($_GET['name'] ?? 'World');
echo "&lt;h1&gt;Hello, {$name}!&lt;/h1&gt;";
echo "&lt;p&gt;The time is " . date('H:i:s') . "&lt;/p&gt;";</code></pre>
    <p>Visit <code>/greeting?name=Alice</code> and the page greets Alice by name.</p>

    <?php App::render('/components/_tryit', [
      'title' => 'See it live',
      'body'  => '<p>This very site uses implicit routing. The page you\'re reading right now is served from <code>public/learn.php</code>. Every page in the docs &mdash; <a href="/routing">/routing</a>, <a href="/streaming">/streaming</a>, <a href="/websocket">/websocket</a> &mdash; is a file in <code>public/</code>.</p>
<p>Try it: <a href="/api/learn/demo/greeting?name=ZealPHP" target="_blank">Open the greeting demo &rarr;</a></p>',
    ]); ?>

    <h2>What about messy URLs?</h2>
    <p>
      "But I don't want <code>.php</code> in my URLs!" &mdash; you won't see them. ZealPHP strips the
      extension automatically. <code>public/about.php</code> responds at <code>/about</code>, not
      <code>/about.php</code>. Requesting <code>/about.php</code> directly returns 403.
    </p>

    <h2>Using a layout</h2>
    <p>
      Right now, your page outputs raw HTML with no <code>&lt;head&gt;</code>, no stylesheet, no navigation.
      Every real page needs a shared layout. The next lesson teaches you how to wrap pages in a layout
      template using <code>App::render()</code>.
    </p>
    <p>Here's a preview &mdash; this is how most pages in ZealPHP apps look:</p>
    <pre><code class="language-php">&lt;?php use ZealPHP\App;
App::render('/_master', [
    'title' =&gt; 'About',
    'page'  =&gt; 'about',
]);</code></pre>
    <p>That's the entire file. Three lines. The master template handles the HTML shell, nav,
      footer, and CSS &mdash; your page template only has the content.</p>

    <?php App::render('/components/_concept_check', [
      'id'       => 'routing1',
      'question' => 'If you create a file at public/blog/post.php, what URL will serve it?',
      'correct'  => 'b',
      'explain'  => 'ZealPHP maps the file path (minus public/ and .php) to the URL. So public/blog/post.php &rarr; /blog/post.',
      'options'  => [
        'a' => '/blog/post.php',
        'b' => '/blog/post',
        'c' => '/public/blog/post',
      ],
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'Files in <code>public/</code> automatically become URLs &mdash; no routing config needed',
      'The URL mirrors the file path: <code>public/blog/post.php</code> &rarr; <code>/blog/post</code>',
      'Use <code>$_GET</code> for query parameters, standard PHP for dynamic content',
      '<code>.php</code> extensions are stripped &mdash; clean URLs by default',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/create-app"
         hx-get="/api/learn/page?slug=learn/create-app" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/create-app">&larr; Create a ZealPHP App</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/components"
         hx-get="/api/learn/page?slug=learn/components" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/components">Layouts &amp; Components &rarr;</a>
    </div>
  </article>
</div>

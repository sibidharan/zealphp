<?php use ZealPHP\App; $active = $active ?? 'learn/create-app'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 2,
      'title'    => 'Create a ZealPHP App',
      'subtitle' => 'From zero to a running app in under two minutes.',
      'prev'     => ['slug' => 'learn', 'title' => 'Hello, ZealPHP'],
      'next'     => ['slug' => 'learn/first-page', 'title' => 'Your First Page'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Install PHP 8.3, OpenSwoole, and uopz with one command',
      'Scaffold a project with composer create-project',
      'Start the dev server and verify it works',
      'Understand the project folder structure',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      Setting up a traditional PHP project means installing PHP, configuring a web server, creating
      virtual hosts, setting up URL rewriting, and hoping nothing conflicts. With ZealPHP, it's
      two commands.
    </p>

    <h2>Step 1: Install system dependencies</h2>
    <p>ZealPHP needs PHP 8.3+, the OpenSwoole extension (event loop + HTTP server), and the uopz
      extension (for session/header overrides). One script installs everything:</p>
    <pre><code class="language-bash">curl -fsSL https://php.zeal.ninja/install.sh | sudo bash</code></pre>
    <p>This installs PHP 8.3 (or higher), the <code>openswoole</code> and <code>uopz</code> PECL
      extensions, and Composer. It works on Ubuntu, Debian, and macOS.</p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Already have PHP 8.3+?',
      'body'    => 'You can skip the install script and just install the extensions: <code>pecl install openswoole</code> and <code>pecl install uopz</code>. Verify with <code>php -m | grep -E "openswoole|uopz"</code>.',
    ]); ?>

    <h2>Step 2: Scaffold a project</h2>
    <pre><code class="language-bash">composer create-project sibidharan/zealphp-project my-app
cd my-app</code></pre>
    <p>This creates a starter project with the right folder structure, a minimal <a href="https://github.com/sibidharan/zealphp/blob/master/app.php" target="_blank"><code>app.php</code></a>,
      and all dependencies pre-installed (the scaffold ships <code>vendor/</code> so you can run immediately).</p>

    <h2>Step 3: Start the server</h2>
    <pre><code class="language-bash">php app.php</code></pre>
    <p>Open <code>http://localhost:8080</code> in your browser. You should see the starter page.</p>
    <p>That's it. Your app is running. No Apache config, no virtual host, no <code>.htaccess</code>.</p>

    <h2>The folder structure</h2>
    <p>Here's what the scaffold created:</p>
    <pre><code>my-app/
&#9500;&#9472;&#9472; app.php              # Entry point &mdash; middleware, routes, $app-&gt;run()
&#9500;&#9472;&#9472; public/              # Pages &mdash; each .php file becomes a URL
&#9474;   &#9492;&#9472;&#9472; index.php        # &rarr; GET /
&#9500;&#9472;&#9472; api/                 # REST endpoints &mdash; file-based routing (ZealAPI)
&#9500;&#9472;&#9472; route/               # Advanced routes &mdash; WebSocket, Store tables, path params
&#9500;&#9472;&#9472; template/            # Layouts and reusable components
&#9474;   &#9500;&#9472;&#9472; _master.php      # Page layout shell (nav + content + footer)
&#9474;   &#9492;&#9472;&#9472; pages/           # Page-specific content
&#9500;&#9472;&#9472; src/                 # Business logic &mdash; PSR-4 autoloaded classes
&#9500;&#9472;&#9472; storage/             # SQLite databases, uploaded files
&#9492;&#9472;&#9472; vendor/              # Composer dependencies</code></pre>

    <p>The key rule: <strong>each folder has one job</strong>.</p>
    <ul>
      <li><strong>public/</strong> &mdash; Pages the user visits. Files map directly to URLs.</li>
      <li><strong>api/</strong> &mdash; REST endpoints. Each file defines one closure.</li>
      <li><strong>src/</strong> &mdash; Business logic. Classes, services, helpers &mdash; autoloaded.</li>
      <li><strong>template/</strong> &mdash; HTML layouts and reusable components.</li>
    </ul>

    <h2>CLI commands</h2>
    <p>The server management works through <code>app.php</code>:</p>
    <pre><code class="language-bash">php app.php                # Start (default port 8080)
php app.php start -p 9501  # Start on a specific port
php app.php stop           # Stop the server
php app.php restart        # Restart
php app.php status         # Check if running
php app.php logs           # Tail log files</code></pre>

    <?php App::render('/components/_deepdive', [
      'title' => 'What happens when you run php app.php?',
      'body'  => '<p>OpenSwoole starts an HTTP server inside the PHP process. It forks worker processes (one per CPU core by default), each handling thousands of concurrent connections using coroutines. Your routes, middleware, and templates are loaded once at startup and shared across all requests &mdash; unlike traditional PHP where everything is re-loaded per request.</p><p>This is why ZealPHP is fast: no bootup cost per request, and the process never dies between requests.</p>',
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'One install script sets up PHP, OpenSwoole, and uopz',
      '<code>composer create-project</code> scaffolds the app with the right structure',
      '<code>php app.php</code> starts the server &mdash; no web server config needed',
      'Each folder has one job: public/ for pages, api/ for REST, src/ for logic, template/ for layouts',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn"
         hx-get="/api/learn/page?slug=learn" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn">&larr; Hello, ZealPHP</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/first-page"
         hx-get="/api/learn/page?slug=learn/first-page" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/first-page">Your First Page &rarr;</a>
    </div>
  </article>
</div>

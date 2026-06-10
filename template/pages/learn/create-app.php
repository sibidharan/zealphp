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
      'Install PHP 8.3, OpenSwoole, and ext-zealphp with one command',
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
    <p>ZealPHP needs PHP 8.3+, the OpenSwoole extension (event loop + HTTP server), and the ext-zealphp
      extension (for session/header overrides). One script installs everything:</p>
    <pre><code class="language-bash">curl -fsSL https://php.zeal.ninja/install.sh | sudo bash</code></pre>
    <p>This installs PHP 8.3 (or higher), the <code>openswoole</code> and <code>zealphp</code>
      extensions, and Composer. It works on Ubuntu, Debian, and macOS.</p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Already have PHP 8.3+?',
      'body'    => 'You can skip the install script and just install the extensions: <code>pie install openswoole/openswoole</code> and <code>pie install zealphp/ext</code>. Verify with <code>php -m | grep -E "openswoole|zealphp"</code>.',
    ]); ?>

    <h2>Step 2: Scaffold a project</h2>
    <pre><code class="language-bash">composer create-project zealphp/project my-app
cd my-app</code></pre>
    <p>This creates a starter project with the right folder structure, a minimal <a href="https://github.com/sibidharan/zealphp/blob/master/app.php" target="_blank"><code>app.php</code></a>,
      and all dependencies installed automatically &mdash; you can run immediately.</p>

    <h2>Step 3: Start the server</h2>
    <pre><code class="language-bash">php app.php</code></pre>
    <p>Open <code>http://localhost:8080</code> in your browser. You should see the starter page.</p>
    <p>That&rsquo;s it. Your app is running. No Apache config, no virtual host, no <code>.htaccess</code>.</p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'What\'s App::superglobals(false)?',
      'body'    => 'You\'ll see this line near the top of <code>app.php</code>. It tells ZealPHP to run in <strong>coroutine mode</strong> &mdash; the recommended default for new projects. It means each request gets its own isolated state, so many requests can run at the same time without stepping on each other. You don\'t need to understand the details yet &mdash; <a href="/learn/mental-model">Lesson 4 (The Mental Model)</a> explains it. For now, just leave it as-is.',
    ]); ?>

    <h2>The folder structure (in one breath)</h2>
    <p>
      The scaffold lays out the directories every ZealPHP app uses: <code>public/</code> for pages,
      <code>api/</code> for REST, <code>route/</code> for explicit registrations, <code>template/</code>
      for layouts, <code>src/</code> for business logic. We cover each in detail later &mdash; the
      <a href="/learn/project-structure">Project Structure</a> lesson has the full layout map and the
      "where do I put X?" reference table. For now, just know the names exist; you&rsquo;ll touch
      <code>public/</code> first in the next lesson.
    </p>

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
      'body'  => '<p>OpenSwoole starts an HTTP server inside the PHP process. It forks worker processes (one per CPU core by default), each handling thousands of concurrent connections using coroutines. Your routes, middleware, and templates are loaded once at startup and shared across all requests — unlike traditional PHP where everything is re-loaded per request.</p><p>This is why ZealPHP is fast: no bootup cost per request, and the process never dies between requests.</p>',
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'One install script sets up PHP, OpenSwoole, and ext-zealphp',
      '<code>composer create-project</code> scaffolds the app with the right structure',
      '<code>php app.php</code> starts the server — no web server config needed',
      'Each folder has one job: public/ for pages, api/ for REST, src/ for logic, template/ for layouts',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn"
         hx-get="/api/learn/page?slug=learn" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn">← Hello, ZealPHP</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/first-page"
         hx-get="/api/learn/page?slug=learn/first-page" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/first-page">Your First Page →</a>
    </div>
  </article>
</div>

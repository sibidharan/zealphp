<?php use ZealPHP\App; $active = $active ?? 'learn/create-app'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 2,
      'title'    => 'Create a ZealPHP App',
      'subtitle' => 'From an empty directory to a running server in three commands.',
      'prev'     => ['slug' => 'learn', 'title' => 'Quick Start'],
      'next'     => ['slug' => 'learn/first-page', 'title' => 'Your First Page'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Install PHP 8.3, OpenSwoole, uopz with one command',
      'Scaffold a new project from the official template',
      'Run the dev server with php app.php',
      'Read the standard ZealPHP folder layout',
    ]]); ?>

    <h2>1. System dependencies</h2>
    <p>
      ZealPHP needs <strong>PHP 8.3+</strong>, <strong>OpenSwoole &ge; 22.0</strong>,
      and the <strong>uopz</strong> extension. On Ubuntu / Debian / WSL2, a single
      script installs everything:
    </p>
    <pre><code>curl -fsSL https://php.zeal.ninja/install.sh | sudo bash</code></pre>
    <p>The script auto-detects your distro and prints clean manual steps for ones it can't handle (Fedora, Arch, Alpine). See the full <a href="/getting-started">Getting Started</a> page for Docker and manual paths.</p>

    <h2>2. Create a project</h2>
    <p>The scaffold is a Composer template that ships <code>vendor/</code> checked in so you can <code>cd</code> in and run it immediately:</p>
    <pre><code>composer create-project sibidharan/zealphp-project myapp
cd myapp</code></pre>

    <h2>3. Run it</h2>
    <pre><code>php app.php</code></pre>
    <p>That's it. The dev server listens on <code>:8080</code> by default. Open <a href="http://localhost:8080/" target="_blank">http://localhost:8080/</a> and you'll see the starter page.</p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Other useful CLI flags',
      'body'    => '<p>The server has a friendly CLI:</p><pre><code>php app.php start -p 9501 -d   # daemonize on port 9501
php app.php stop -p 9501       # stop it
php app.php restart            # cycle
php app.php status             # is it up?
php app.php logs --access      # tail access log</code></pre>',
    ]); ?>

    <h2>4. Folder layout</h2>
    <pre><code>myapp/
├── app.php              # entry point — boots the server
├── composer.json
├── vendor/              # checked in for instant first-run
├── public/              # implicit GET routes — public/foo.php → /foo
│   ├── index.php
│   └── css/
├── api/                 # implicit file-based API routes
├── route/               # explicit + namespaced routes (loaded at startup)
├── template/            # PHP component templates (rendered by App::render)
│   ├── _master.php
│   └── pages/
├── storage/             # databases, uploaded files, etc.
└── .sessions/           # PHP session files (gitignored)</code></pre>

    <p>Each top-level folder is a framework convention. You'll add files to <code>public/</code>, <code>route/</code>, and <code>template/</code> for almost everything.</p>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn">← Quick Start</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/first-page">Your First Page →</a>
    </div>
  </article>
</div>

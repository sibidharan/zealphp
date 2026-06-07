<?php use ZealPHP\App; $active = $active ?? 'learn'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 1,
      'title'    => 'Hello, ZealPHP',
      'subtitle' => 'Build a web server in three lines of PHP. No Apache. No Nginx. Just PHP.',
      'next'     => ['slug' => 'learn/create-app', 'title' => 'Create a ZealPHP App'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'What ZealPHP is and the problem it solves',
      'Why OpenSwoole changes what PHP can do',
      'The mental model: PHP IS the server',
      'What you\'ll build across 26 lessons',
    ]]); ?>

    <h2>The three-line web server</h2>
    <p>Here is a complete web server in PHP:</p>
    <pre><code class="language-php">&lt;?php
require 'vendor/autoload.php';
ZealPHP\App::init('0.0.0.0', 8080)->run();</code></pre>
    <p>
      Run <code>php app.php</code>. Open <code>http://localhost:8080</code>. You have a web server.
    </p>
    <p>
      No Apache configuration. No Nginx. No php-fpm. No <code>.htaccess</code>. Just PHP, running
      its own HTTP server.
    </p>

    <h2>Why does this matter?</h2>
    <p>
      In traditional PHP &mdash; what you might know as the <strong>LAMP stack</strong>
      (Linux/Apache/MySQL/PHP) or its modern PHP-FPM cousin &mdash; you need a <strong>web server</strong>
      (Apache or Nginx) that listens for HTTP requests, then <strong>hands them off</strong> to PHP via
      FastCGI. Each request spawns a fresh process, shares nothing with other requests, and dies when done.
    </p>
    <p>
      That architecture worked for 25 years. But it can't do WebSocket. It can't stream AI responses
      token-by-token. It can't share state between requests without bolting on a sidecar service like Redis. It can't run background tasks
      without a queue worker. Every "modern" feature requires bolting on another service.
    </p>

    <div class="lidx-modes">
      <div>
        <h4 class="lidx-mode-title lidx-mode-title-trad">Traditional PHP</h4>
        <pre class="mermaid">graph TD
    B[Browser] --> N[Nginx]
    N --> F[php-fpm]
    F --> C[Your Code]
    C --> R[(Redis)]
    C --> Q[Queue Worker]
    C --> WS[Node.js WebSocket]
    C --> S[Supervisor]
    style R fill:#fef2f2,stroke:#f87171
    style Q fill:#fef2f2,stroke:#f87171
    style WS fill:#fef2f2,stroke:#f87171
    style S fill:#fef2f2,stroke:#f87171</pre>
        <p class="lidx-mode-caption">6 processes &middot; 4 config files &middot; 3 languages</p>
      </div>
      <div>
        <h4 class="lidx-mode-title lidx-mode-title-zeal">ZealPHP</h4>
        <pre class="mermaid">graph TD
    B[Browser] --> Z["php app.php"]
    Z --> C[Your Code]
    Z --> H[HTTP + SSE]
    Z --> W[WebSocket]
    Z --> SE[Sessions]
    Z --> SM[Shared Memory]
    Z --> T[Task Workers]
    style Z fill:#fffbeb,stroke:#f59e0b,stroke-width:2px
    style H fill:#ecfdf5,stroke:#059669
    style W fill:#ecfdf5,stroke:#059669
    style SE fill:#ecfdf5,stroke:#059669
    style SM fill:#ecfdf5,stroke:#059669
    style T fill:#ecfdf5,stroke:#059669</pre>
        <p class="lidx-mode-caption">1 process &middot; 0 config files &middot; 1 language</p>
      </div>
    </div>

    <h2>The mental model</h2>
    <p>
      Think of traditional PHP like a <strong>restaurant with a waiter</strong>. The waiter (Nginx) takes
      your order, walks to the kitchen (php-fpm), waits for the chef to finish, walks back, and
      delivers the plate. Every customer gets this roundtrip.
    </p>
    <p>
      ZealPHP is the <strong>chef standing at the counter</strong>. No waiter, no trip. The chef hears your
      order and hands you the plate directly. One process handles everything: HTTP, WebSocket, sessions,
      timers, shared memory.
    </p>
    <p>
      This is possible because of <strong>OpenSwoole</strong> &mdash; a PHP extension that gives PHP an
      event loop, coroutines, and its own HTTP server. ZealPHP wraps OpenSwoole with a developer-friendly
      API: routes, templates, sessions, middleware &mdash; everything you know from traditional PHP, but
      running inside a persistent process.
    </p>

    <h2>What you'll build</h2>
    <p>
      ZealPHP is <strong>frontend and backend agnostic</strong>. It can serve a JSON API for a React SPA,
      stream HTML for htmx, or run an unmodified WordPress or Laravel app. It's a runtime, not a framework religion.
    </p>
    <p>
      This tutorial is not a reference manual. It's a working app. Over 26 lessons, you'll build a
      <strong>Personal Notes app with an AI chat assistant</strong>:
    </p>
    <ol>
      <li><strong>Lessons 1&ndash;3 (Hello World):</strong> Install ZealPHP, create your first page</li>
      <li><strong>Lessons 4&ndash;12 (Foundations):</strong> Request lifecycle, routing, parameter injection, responses, middleware, streaming, shared state</li>
      <li><strong>Lessons 13&ndash;17 (Interactivity):</strong> Layouts &amp; components, React vs PHP, htmx, sessions, user accounts</li>
      <li><strong>Lessons 18&ndash;21 (Build the App):</strong> Notes CRUD, real-time sync, AI chat, multiplayer tic-tac-toe</li>
      <li><strong>Under the Hood:</strong> Advanced routing, async patterns, lifecycle modes &amp; legacy apps, deployment</li>
    </ol>
    <p>
      Every lesson you scroll through is <em>also a page in the real app</em>. The code that renders
      this lesson is the same code that powers the interactive demos. Register an account in Lesson 17,
      save notes in Lesson 18, and chat with an AI agent in Lesson 20 &mdash; all served from one
      <code>php app.php</code> process.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Already know PHP frameworks?',
      'body'    => 'Skip to <a href="/learn/htmx">Lesson 15 (Forms &amp; htmx)</a> if you\'re comfortable with routing and templates. Skip to <a href="/learn/notes">Lesson 18 (Personal Notes)</a> to jump straight into the app build.',
    ]); ?>

    <?php App::render('/components/_keytakeaways', ['items' => [
      'ZealPHP is a PHP framework built on OpenSwoole &mdash; PHP runs its own HTTP server',
      'One process handles HTTP, WebSocket, SSE, sessions, and shared memory',
      'No Apache, Nginx, Redis, or queue workers needed for most apps',
      'ZealPHP modernizes the Apache/FPM half of the LAMP stack &mdash; keep your DB, swap the request model',
      'This tutorial builds a real Notes + AI Chat + multiplayer Tic-Tac-Toe app across 26 lessons',
    ]]); ?>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-next" href="/learn/create-app"
         hx-get="/api/learn/page?slug=learn/create-app" hx-target=".lesson-content"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/create-app">Create a ZealPHP App &rarr;</a>
    </div>
  </article>
</div>

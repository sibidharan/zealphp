<?php use ZealPHP\App; $active = $active ?? 'learn'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 1,
      'title'    => 'Quick Start',
      'subtitle' => 'What ZealPHP is, in one paragraph — and why you would build with it.',
      'next'     => ['slug' => 'learn/create-app', 'title' => 'Create a ZealPHP App'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'What ZealPHP is and why it exists',
      'Why OpenSwoole changes what PHP can do',
      'Why server-rendered components beat a full SPA for most apps',
      'Why we picked htmx over React for the demo',
    ]]); ?>

    <h2>What is ZealPHP?</h2>
    <p>
      ZealPHP is an async PHP framework built on <strong>OpenSwoole</strong>.
      One server process handles HTTP, WebSocket, SSE, task workers, sessions, and shared memory —
      no Redis, no Node sidecar, no queue worker, no nginx-fastcgi handshake.
      You write routes and templates in plain PHP, and the runtime gives you Go-level concurrency
      with coroutines, channels, and an in-memory key-value <code>Store</code>.
    </p>

    <h2>Why we wrote this tutorial</h2>
    <p>
      Most framework docs are reference manuals. This one is a tutorial app.
      Every lesson you scroll through is also a <em>page in a real app</em> — register an account,
      save notes, talk to an AI agent that can read and modify those notes. The code that
      renders this page is the same code that powers the demo.
    </p>
    <p>
      By the end you'll have built a working PHP app with auth, a SQLite database, htmx-driven
      interactivity, server-sent events, a Python agent, and a WebSocket — all served from one
      <code>php app.php</code> process.
    </p>

    <h2>The tour</h2>
    <ol>
      <li><a href="/learn/create-app">Create a ZealPHP App</a> — install + scaffold + run</li>
      <li><a href="/learn/first-page">Your First Page</a> — implicit public routing</li>
      <li><a href="/learn/components">Components</a> — three render methods, live demos</li>
      <li><a href="/learn/routing">Routing</a> — implicit, explicit, namespaced, file-based</li>
      <li><a href="/learn/sessions">Sessions &amp; Auth</a> — register/login/logout with SQLite</li>
      <li><a href="/learn/htmx">Add htmx</a> — interactivity without JavaScript</li>
      <li><a href="/learn/notes">Build Personal Notes</a> — the real app</li>
      <li><a href="/learn/ai-chat">Add AI Chat</a> — SSE streaming + tool calls</li>
      <li><a href="/learn/async">Async &amp; Coroutines</a> — when concurrency helps</li>
      <li><a href="/learn/deployment">Deployment</a> — daemon, Nginx, Docker</li>
      <li><a href="/learn/philosophy">Philosophy</a> — when not to reach for React</li>
    </ol>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'Already know ZealPHP?',
      'body'    => 'Skip to <a href="/learn/notes">Lesson 8</a> and start building the app immediately. The earlier lessons are a refresher for the patterns you\'ll use.',
    ]); ?>

    <div class="lesson-chips"><a class="lesson-chip lesson-chip-next" href="/learn/create-app">Create a ZealPHP App →</a></div>
  </article>
</div>

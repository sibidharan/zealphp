<?php use ZealPHP\App; $active = $active ?? 'learn/philosophy'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 12,
      'title'    => 'Philosophy',
      'subtitle' => 'When to reach for ZealPHP — and when not to.',
      'prev'     => ['slug' => 'learn/deployment', 'title' => 'Deployment'],
    ]); ?>

    <h2>Plain PHP scales further than you think</h2>
    <p>
      ZealPHP on 4 OpenSwoole workers benchmarks at <strong>67,000 req/s</strong> with a
      21ms p90 latency. That's not a toy number. For most applications — SaaS dashboards,
      content sites, internal tools, AI wrappers — it's 10x more than you'll ever need.
      The bottleneck in almost every web app is the database or the external API, not the framework.
    </p>
    <p>
      The tutorial you just built uses a single SQLite file, session-based auth, htmx,
      and a Python subprocess for AI — and it handles concurrent users, streaming responses,
      and real-time WebSocket sync. No Redis. No message queue. No load balancer. One process.
    </p>

    <h2>JavaScript where it helps, not as a tax</h2>
    <p>
      React is brilliant when you need a highly interactive, state-heavy UI — a spreadsheet,
      a design tool, a collaborative editor. It's overkill for forms, lists, and dashboards.
      htmx lets you add targeted interactivity (submit-without-reload, swap-a-section,
      delete-an-item) with four HTML attributes. The server renders the HTML; the browser
      swaps it in.
    </p>
    <p>
      This tutorial used <strong>zero</strong> lines of custom React. The chat timeline
      and WebSocket sync are ~80 lines of vanilla JS — small enough to read in one sitting.
      Most of the app is server-rendered PHP.
    </p>

    <h2>Server-first is simpler</h2>
    <p>
      A React + Node + Redis + queue-worker stack has six moving parts.
      A ZealPHP app has one: <code>php app.php</code>. HTTP, WebSocket, SSE, task workers,
      sessions, shared memory, timers — all in one process.
    </p>
    <p>
      Fewer moving parts means fewer things to monitor, fewer things to break at 3am,
      fewer things to explain to the next developer. The boring architecture is the one
      that ships and stays shipped.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'When ZealPHP isn\'t the right choice',
      'body'    => '<p>If you need client-side state management (drag-and-drop, real-time collaboration, optimistic updates), a frontend framework is the right tool. If you need horizontal scaling across many machines, you\'ll want stateless workers + external session storage. ZealPHP is one server, one process — it scales vertically well but doesn\'t pretend to be Kubernetes.</p>',
    ]); ?>

    <h2>What you built</h2>
    <p>In 12 lessons you created:</p>
    <ul>
      <li>Session-based auth with SQLite + <code>password_hash</code></li>
      <li>A CRUD notes app with htmx — no page reloads</li>
      <li>An AI chat that streams tool calls via SSE</li>
      <li>Cross-tab sync via WebSocket</li>
      <li>Chat history with ZealAPI file-based endpoints</li>
      <li>A timing demo showing <code>go() + Channel</code> parallelism</li>
    </ul>
    <p>All served from one <code>php app.php</code> process. That's ZealPHP.</p>

    <div style="margin:2rem 0;text-align:center">
      <a href="https://github.com/sibidharan/zealphp" target="_blank"
         style="display:inline-block;padding:.75rem 1.5rem;background:var(--accent, #f59e0b);color:#fff;border-radius:8px;font-weight:700;text-decoration:none;font-size:1.05rem">
        Star on GitHub →
      </a>
    </div>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/deployment">← Deployment</a>
    </div>
  </article>
</div>

<?php use ZealPHP\App; $active = $active ?? 'learn/philosophy'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 13,
      'title'    => 'Philosophy',
      'subtitle' => 'When to reach for ZealPHP — and when not to.',
      'prev'     => ['slug' => 'learn/deployment', 'title' => 'Deployment'],
    ]); ?>

    <h2>Plain PHP scales further than you think</h2>
    <p>
      ZealPHP on 4 OpenSwoole workers benchmarks at <strong>117,000 req/s</strong> with a
      3ms p90 latency. That's not a toy number. For most applications — SaaS dashboards,
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

    <h2>Code conventions that scale</h2>
    <p>As your ZealPHP app grows, these conventions keep the codebase maintainable:</p>
    <ul>
      <li><strong>PSR-2 style</strong> — follow the <a href="https://www.php-fig.org/psr/psr-2/" target="_blank">PSR-2 coding standard</a> for consistent PHP formatting.</li>
      <li><strong>No inline JS or CSS in templates</strong> — JavaScript goes in <code>public/js/</code>, CSS in <code>public/css/</code>. Templates produce HTML, nothing else.</li>
      <li><strong>No function definitions in templates or API files</strong> — business logic belongs in <code>src/</code> classes, autoloaded via Composer PSR-4.</li>
      <li><strong>API endpoints in <code>api/</code>, not <code>route/</code></strong> — use ZealAPI's file-based routing for REST. Reserve <code>route/</code> for path-param routes and WebSocket.</li>
      <li><strong>Thin route handlers</strong> — a route handler calls a service class; it doesn't <em>contain</em> business logic.</li>
      <li><strong>If you need <code>function_exists()</code>, the function is in the wrong place</strong> — put it in a class.</li>
    </ul>
    <p>
      The <code>src/Learn/</code> namespace in the framework repo demonstrates this pattern:
      <code>Auth.php</code>, <code>Chat.php</code>, <code>Notes.php</code>, and <code>DB.php</code> are
      autoloaded classes that API and route handlers delegate to.
    </p>

    <h2>htmx — the interactivity layer</h2>
    <p>
      This site uses <code>hx-boost="true"</code> on <code>&lt;body&gt;</code>, which means every link
      and form is AJAX-ified automatically. The server renders full HTML; htmx swaps the content
      without a full reload. If JavaScript is disabled, everything still works — progressive enhancement.
    </p>
    <p>
      Reach for htmx attributes (<code>hx-get</code>, <code>hx-post</code>,
      <code>hx-target</code>, <code>hx-swap</code>) before writing custom <code>fetch()</code>.
      For server-push — real-time updates, streaming AI tokens — use WebSocket or SSE instead.
    </p>

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

    <div class="lesson-cta">
      <a href="https://github.com/sibidharan/zealphp" target="_blank" class="btn-cta">Star on GitHub →</a>
    </div>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/deployment">← Deployment</a>
    </div>
  </article>
</div>

<?php use ZealPHP\App; $active = $active ?? 'learn/deployment'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 14,
      'title'    => 'Ship It',
      'subtitle' => 'From localhost to production. Plus: when to use ZealPHP and when not to.',
      'prev'     => ['slug' => 'learn/async', 'title' => 'Async & Coroutines'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Run ZealPHP as a background daemon',
      'Set up Nginx as a reverse proxy (HTTP + WebSocket + SSE)',
      'Write a systemd service unit',
      'When ZealPHP is the right tool and when it isn\'t',
    ]]); ?>

    <h2>The problem</h2>
    <p>
      Your app runs on <code>localhost:8080</code>. You close the terminal and it dies. You need it
      on a real server, running 24/7, behind HTTPS, surviving reboots.
    </p>

    <h2>1. Daemon mode</h2>
    <pre><code class="language-bash">php app.php start -p 8080 -d    # daemonize
php app.php status              # check if running
php app.php stop                # stop
php app.php restart             # cycle</code></pre>
    <p>
      The <code>-d</code> flag puts the server in the background. PID files live at
      <code>/tmp/zealphp/zealphp_{port}.pid</code>. Logs default to <code>/tmp/zealphp/</code>.
    </p>

    <h2>2. Nginx reverse proxy</h2>
    <pre><code class="language-nginx">server {
    listen 80;
    server_name myapp.example.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        # WebSocket
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        # SSE &mdash; disable buffering
        proxy_buffering off;
        proxy_cache off;
    }
}</code></pre>
    <p><code>proxy_buffering off</code> is critical for SSE and streaming &mdash; without it, Nginx buffers the entire response before forwarding.</p>

    <h2>3. systemd service</h2>
    <pre><code class="language-ini">[Unit]
Description=ZealPHP App
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/myapp
ExecStart=/usr/bin/php app.php start -p 8080
ExecStop=/usr/bin/php app.php stop -p 8080
Restart=on-failure
RestartSec=5
Environment=OPENAI_API_KEY=sk-...

[Install]
WantedBy=multi-user.target</code></pre>

    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'Don\'t use -d with systemd',
      'body'    => '<p>systemd expects <code>Type=simple</code> &mdash; the process stays in the foreground. The <code>-d</code> flag is for manual invocation only. Daemonize AND systemd = systemd thinks the process died immediately.</p>',
    ]); ?>

    <h2>4. Docker</h2>
    <pre><code class="language-dockerfile">FROM php:8.3-cli
RUN apt-get update &amp;&amp; apt-get install -y libssl-dev libcurl4-openssl-dev \
    &amp;&amp; pecl install openswoole &amp;&amp; docker-php-ext-enable openswoole \
    &amp;&amp; pecl install uopz &amp;&amp; docker-php-ext-enable uopz
WORKDIR /app
COPY . .
RUN composer install --no-dev
EXPOSE 8080
CMD ["php", "app.php", "start", "-p", "8080"]</code></pre>

    <h2>5. Environment variables</h2>
    <pre><code>OPENAI_API_KEY            # Real AI chat; mock mode without it
ZEALPHP_LEARN_AI_MODEL    # Model name (default: gpt-4.1-mini)
ZEALPHP_LEARN_DB_PATH     # SQLite path (default: storage/learn.db)
ZEALPHP_WORKERS           # HTTP worker count (default: CPU cores)
ZEALPHP_PORT              # Listen port (default: 8080)
ZEALPHP_LOG_DIR           # Log directory (default: /tmp/zealphp)</code></pre>

    <h2>What you built</h2>
    <p>Over 12 lessons you created a real application:</p>
    <ul>
      <li><strong>Session-based auth</strong> with SQLite + <code>password_hash</code></li>
      <li><strong>CRUD notes app</strong> with htmx &mdash; no page reloads</li>
      <li><strong>AI chat assistant</strong> that streams tool calls via SSE</li>
      <li><strong>Cross-tab sync</strong> via WebSocket</li>
      <li><strong>Agent-via-API</strong> &mdash; Python calls the same endpoints as the frontend</li>
      <li><strong>Parallel I/O</strong> with <code>go() + Channel</code></li>
    </ul>
    <p>
      All served from one <code>php app.php</code> process. No Redis. No Node sidecar.
      No queue worker. One process, one language.
    </p>

    <h2>When ZealPHP is right</h2>
    <p>
      ZealPHP on 4 workers benchmarks at <strong>117,000 req/s</strong> with 3ms p90 latency.
      For SaaS dashboards, content sites, internal tools, AI wrappers &mdash; it's more than enough.
      The bottleneck is almost always the database or external API, not the framework.
    </p>
    <p>
      htmx covers 95% of interactivity needs with four HTML attributes. The remaining 5% (drag-and-drop,
      collaborative editing, client-side state) is where React earns its complexity.
    </p>

    <?php App::render('/components/_callout', [
      'variant' => 'info',
      'title'   => 'When ZealPHP isn\'t the right choice',
      'body'    => '<p><strong>Client-side state management</strong> (optimistic updates, complex drag-and-drop, real-time collaboration) &mdash; a frontend framework is the right tool.</p><p><strong>Horizontal scaling across machines</strong> &mdash; ZealPHP is one process. It scales vertically well but doesn\'t pretend to be Kubernetes. For multi-server, you\'ll need stateless workers + Redis sessions.</p>',
    ]); ?>

    <h2>The boring architecture</h2>
    <p>
      A React + Node + Redis + queue-worker stack has six moving parts. A ZealPHP app has one:
      <code>php app.php</code>. Fewer moving parts means fewer things to monitor, fewer things
      to break at 3am, fewer things to explain to the next developer.
    </p>
    <p>The boring architecture is the one that ships and stays shipped.</p>

    <?php App::render('/components/_keytakeaways', ['items' => [
      '<code>php app.php start -d</code> daemonizes; <code>systemd</code> keeps it alive',
      'Nginx reverse proxy needs <code>proxy_buffering off</code> for SSE/streaming',
      'One process handles HTTP, WebSocket, SSE, sessions, shared memory &mdash; no external services',
      'ZealPHP is right for most web apps; reach for React only when you need client-side state',
    ]]); ?>

    <div class="lesson-cta">
      <a href="https://github.com/sibidharan/zealphp" target="_blank" class="btn-cta">Star on GitHub &rarr;</a>
    </div>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/async"
         hx-get="/api/learn/page?slug=learn/async" hx-target=".learn-layout"
         hx-swap="outerHTML show:.learn-layout:top" hx-push-url="/learn/async">&larr; Async &amp; Coroutines</a>
    </div>
  </article>
</div>

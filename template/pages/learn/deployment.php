<?php use ZealPHP\App; $active = $active ?? 'learn/deployment'; ?>
<div class="learn-layout">
  <?php App::render('/_learn_sidebar', ['active' => $active]); ?>
  <article class="lesson-content">
    <?php App::render('/components/_lesson_header', [
      'number'   => 12,
      'title'    => 'Deployment',
      'subtitle' => 'From dev to prod — daemon mode, reverse proxy, env vars, Docker.',
      'prev'     => ['slug' => 'learn/async', 'title' => 'Async & Coroutines'],
      'next'     => ['slug' => 'learn/philosophy', 'title' => 'Philosophy'],
    ]); ?>

    <?php App::render('/components/_youwilllearn', ['items' => [
      'Run ZealPHP as a background daemon',
      'Set up Nginx as a reverse proxy (HTTP + WebSocket)',
      'Configure environment variables for production',
      'Write a systemd service unit',
    ]]); ?>

    <h2>1. Daemon mode</h2>
    <pre><code>php app.php start -p 8080 -d
php app.php status
php app.php stop
php app.php restart</code></pre>
    <p>The <code>-d</code> flag daemonizes the server. PID files live at <code>/tmp/zealphp/zealphp_{port}.pid</code>. Logs default to <code>/tmp/zealphp/</code> — override with <code>ZEALPHP_LOG_DIR</code>.</p>

    <h2>2. Nginx reverse proxy</h2>
    <pre><code>server {
    listen 80;
    server_name myapp.example.com;

    location / {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        # WebSocket support
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        # SSE — disable buffering
        proxy_buffering off;
        proxy_cache off;
    }
}</code></pre>
    <p>The <code>proxy_buffering off</code> line is critical for SSE and streaming routes — without it, Nginx buffers the entire response before sending it to the client.</p>

    <h2>3. systemd service</h2>
    <pre><code>[Unit]
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
Environment=ZEALPHP_LEARN_DB_PATH=/var/www/myapp/storage/learn.db

[Install]
WantedBy=multi-user.target</code></pre>

    <?php App::render('/components/_callout', [
      'variant' => 'warn',
      'title'   => 'Don\'t use -d with systemd',
      'body'    => '<p>systemd expects <code>Type=simple</code> — the process stays in the foreground. The <code>-d</code> flag is for manual invocation only. If you daemonize AND use systemd, systemd thinks the process died immediately.</p>',
    ]); ?>

    <h2>4. Environment variables</h2>
    <pre><code>| Variable                  | Default          | Purpose                        |
| ------------------------- | ---------------- | ------------------------------ |
| OPENAI_API_KEY            | (none)           | Real AI chat; mock without it  |
| ZEALPHP_LEARN_AI_MODEL    | gpt-4.1-mini     | Model for the Python agent     |
| ZEALPHP_LEARN_DB_PATH     | storage/learn.db | SQLite file location           |
| ZEALPHP_LEARN_RATE_LIMIT  | 30               | Chat turns per IP per hour     |
| ZEALPHP_LEARN_MAX_NOTES   | 256              | Max notes per user             |</code></pre>
    <p>Put these in a <code>.env</code> file (gitignored) and <code>source</code> it before starting, or set them in systemd's <code>Environment=</code> directives.</p>

    <h2>5. Docker</h2>
    <pre><code>FROM php:8.3-cli
RUN apt-get update &amp;&amp; apt-get install -y \
    libssl-dev libcurl4-openssl-dev \
    &amp;&amp; pecl install openswoole &amp;&amp; docker-php-ext-enable openswoole \
    &amp;&amp; pecl install uopz &amp;&amp; docker-php-ext-enable uopz

WORKDIR /app
COPY . .
RUN composer install --no-dev
EXPOSE 8080
CMD ["php", "app.php", "start", "-p", "8080"]</code></pre>

    <div class="lesson-chips">
      <a class="lesson-chip lesson-chip-prev" href="/learn/async">← Async & Coroutines</a>
      <a class="lesson-chip lesson-chip-next" href="/learn/philosophy">Philosophy →</a>
    </div>
  </article>
</div>

<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Running Legacy PHP Apps</h1>
<p class="section-desc">ZealPHP runs <strong>unmodified WordPress</strong> — admin dashboard, login, posts, plugins — out of the box. No patches, no forks, no compatibility layers. If it runs on Apache, it runs on ZealPHP.</p>

<div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem; margin: 2rem 0;">
  <div style="border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-md);">
    <img src="/img/wordpress-home.png" alt="WordPress homepage served by ZealPHP" style="width:100%; display:block;">
    <div style="padding: .5rem .75rem; background: var(--bg-alt); font-size: .82rem; color: var(--text-muted); text-align:center;">WordPress front page</div>
  </div>
  <div style="border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-md);">
    <img src="/img/wordpress-admin.png" alt="WordPress admin dashboard on ZealPHP" style="width:100%; display:block;">
    <div style="padding: .5rem .75rem; background: var(--bg-alt); font-size: .82rem; color: var(--text-muted); text-align:center;">Admin dashboard — full menu, widgets, Quick Draft</div>
  </div>
  <div style="border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow-md);">
    <img src="/img/wordpress-posts.png" alt="WordPress posts list on ZealPHP" style="width:100%; display:block;">
    <div style="padding: .5rem .75rem; background: var(--bg-alt); font-size: .82rem; color: var(--text-muted); text-align:center;">Posts management — CRUD, bulk actions, filters</div>
  </div>
</div>

<div class="callout info" style="margin-bottom: 2rem;">
<p><strong>Zero WordPress modifications required.</strong> Login, sessions, cookies, redirects, file uploads, REST API, pretty permalinks — everything works through ZealPHP's CGI worker with true global scope isolation. The same <code>app.php</code> works for Drupal, Laravel, or any traditional PHP application.</p>
</div>

<h2>How It Works</h2>
<p>Three framework features enable legacy app compatibility:</p>

<table class="ztable">
<tr><th>Feature</th><th>What it does</th><th>Apache equivalent</th></tr>
<tr>
  <td><code>App::superglobals(true)</code></td>
  <td><code>$_GET</code>, <code>$_POST</code>, <code>$_SERVER</code>, <code>$_SESSION</code>, <code>$_COOKIE</code> work as expected</td>
  <td>mod_php (default behavior)</td>
</tr>
<tr>
  <td><code>App::$ignore_php_ext = false</code></td>
  <td>Allows <code>.php</code> extensions in URLs (<code>/wp-login.php</code>, <code>/admin/edit.php</code>)</td>
  <td><code>AddHandler php-script .php</code></td>
</tr>
<tr>
  <td><code>App::includeFile()</code></td>
  <td>Runs each PHP file in a separate process at <strong>true global scope</strong> via the CGI worker</td>
  <td>mod_prefork MPM + CGI</td>
</tr>
</table>

<?php App::render('/components/_code', [
    'label' => 'Minimal legacy app configuration',
    'code'  => <<<'PHP'
<?php
require 'vendor/autoload.php';
use ZealPHP\App;

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 8080);
$app->run(['task_worker_num' => 0]);
// PHP files in public/ are served automatically with process isolation
PHP]); ?>

<h2>Porting from Apache .htaccess</h2>
<p>ZealPHP replaces <code>.htaccess</code> entirely. Here are real-world conversions:</p>

<h3>WordPress .htaccess</h3>
<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1rem 0;">
<div>
<?php App::render('/components/_code', [
    'label' => 'Before (.htaccess)',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
RewriteEngine On
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'After (app.php)',
    'code'  => <<<'PHP'
App::superglobals(true);
App::$ignore_php_ext = false;
$app = App::init('0.0.0.0', 8080);

$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] =
        App::$cwd . '/public/index.php';
    App::includeFile(
        App::$cwd . '/public/index.php'
    );
});

$app->run(['task_worker_num' => 0]);
PHP]); ?>
</div>
</div>

<h3>Redirect rules</h3>
<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1rem 0;">
<div>
<?php App::render('/components/_code', [
    'label' => 'Before (.htaccess)',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
RewriteRule ^old-page$ /new [R=301,L]
RewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'After (app.php)',
    'code'  => <<<'PHP'
$app->route('/old-page', function() {
    header('Location: /new');
    return 301;
});

$app->patternRoute('/blog/.*', function() {
    $path = preg_replace(
        '#^/blog/#', '/articles/',
        $_SERVER['REQUEST_URI']
    );
    header('Location: ' . $path);
    return 302;
});
PHP]); ?>
</div>
</div>

<h3>Quick reference</h3>
<table class="ztable">
<tr><th>Apache .htaccess</th><th>ZealPHP equivalent</th></tr>
<tr><td><code>RewriteEngine On</code></td><td>Not needed — ZealPHP routes natively</td></tr>
<tr><td><code>RewriteRule . /index.php [L]</code></td><td><code>$app->setFallback(function() { ... })</code></td></tr>
<tr><td><code>RewriteRule ^path$ /dest [R=301,L]</code></td><td><code>$app->route('/path', function() { header('Location: /dest'); return 301; })</code></td></tr>
<tr><td><code>DirectoryIndex index.php</code></td><td>Built-in — implicit routes serve <code>index.php</code> for directories</td></tr>
<tr><td><code>Options -Indexes</code></td><td>Not needed — ZealPHP never lists directories</td></tr>
<tr><td><code>&lt;FilesMatch "\.php$"&gt;</code></td><td>Not needed — ZealPHP IS the PHP runtime</td></tr>
</table>

<h2>Porting from nginx</h2>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap: 1rem; margin: 1rem 0;">
<div>
<?php App::render('/components/_code', [
    'label' => 'Before (nginx.conf)',
    'lang'  => 'nginx',
    'code'  => <<<'NGINX'
server {
    listen 80;
    root /var/www/html;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    location ~ \.php$ {
        fastcgi_pass unix:/run/php-fpm.sock;
    }
    location ~* \.(css|js|png)$ {
        expires 30d;
    }
}
NGINX]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'After (app.php)',
    'code'  => <<<'PHP'
App::superglobals(true);
App::$ignore_php_ext = false;
$app = App::init('0.0.0.0', 8080);

// try_files → fallback
$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] =
        App::$cwd . '/public/index.php';
    App::includeFile(
        App::$cwd . '/public/index.php'
    );
});

// Static files (css, js, png) served
// automatically by OpenSwoole
// Cache headers: add custom middleware

$app->run(['task_worker_num' => 0]);
PHP]); ?>
</div>
</div>

<table class="ztable">
<tr><th>nginx directive</th><th>ZealPHP equivalent</th></tr>
<tr><td><code>try_files $uri $uri/ /index.php</code></td><td><code>$app->setFallback(fn() => App::includeFile(...))</code></td></tr>
<tr><td><code>location ~ \.php$ { fastcgi_pass ...; }</code></td><td>Not needed — ZealPHP serves PHP directly</td></tr>
<tr><td><code>location ~* \.(css|js)$ { expires 30d; }</code></td><td>OpenSwoole <code>enable_static_handler</code> + middleware for headers</td></tr>
<tr><td><code>proxy_pass http://backend;</code></td><td>Use native <code>$app->route()</code> or reverse proxy in front</td></tr>
</table>

<h2>AI Config Converter</h2>
<p>Paste your <code>.htaccess</code> or nginx config — get a working <code>app.php</code> streamed in real-time. Powered by gpt-5.4-mini with the full ZealPHP API reference.</p>

<div class="converter-split" style="display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; margin:1.5rem 0;">
  <div style="border-right:1px solid var(--border);">
    <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.78rem; font-weight:600; color:var(--text-muted); display:flex; justify-content:space-between; align-items:center;">
      <span>Apache / nginx config</span>
      <select id="convert-preset" style="font-size:.75rem; padding:.2rem .4rem; border-radius:4px; border:1px solid var(--border); background:var(--bg);">
        <option value="">— paste your own —</option>
        <option value="wordpress">WordPress .htaccess</option>
        <option value="nginx-cms">nginx CMS</option>
        <option value="redirects">Redirect rules</option>
      </select>
    </div>
    <textarea id="convert-input" style="width:100%; min-height:280px; border:none; padding:.75rem; font-family:var(--font-mono); font-size:.82rem; background:var(--code-bg); color:var(--code-text); resize:vertical; outline:none;" placeholder="Paste your .htaccess or nginx server { } config here..."></textarea>
    <div style="padding:.5rem .75rem; background:var(--bg-alt); display:flex; align-items:center; gap:.5rem;">
      <button id="convert-btn" onclick="runConvert()" style="padding:.4rem 1.2rem; background:var(--accent); color:#fff; border:none; border-radius:5px; cursor:pointer; font-size:.82rem; font-weight:600;">Convert →</button>
      <span id="convert-status" style="font-size:.75rem; color:var(--text-muted);"></span>
    </div>
  </div>
  <div>
    <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.78rem; font-weight:600; color:var(--text-muted); display:flex; justify-content:space-between; align-items:center;">
      <span>ZealPHP app.php</span>
      <button onclick="copyOutput()" style="font-size:.72rem; padding:.15rem .5rem; border:1px solid var(--border); border-radius:4px; background:var(--bg); cursor:pointer; color:var(--text-muted);">Copy</button>
    </div>
    <pre id="convert-output" style="min-height:280px; padding:.75rem; margin:0; font-family:var(--font-mono); font-size:.82rem; background:var(--code-bg); color:var(--code-text); overflow:auto; white-space:pre-wrap;"><span style="color:var(--text-muted);">// Output will appear here...</span></pre>
    <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.72rem; color:var(--text-muted);">
      Rate limit: 5 conversions per 10 minutes · Powered by gpt-5.4-mini · <a href="https://github.com/sibidharan/zealphp/blob/master/examples/agents/config_converter.py" target="_blank">Source</a>
    </div>
  </div>
</div>

<style>
@media (max-width:768px) { .converter-split { grid-template-columns:1fr !important; } }
</style>

<script>
const PRESETS = {
  wordpress: `# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteBase /
RewriteRule ^index\\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress`,
  'nginx-cms': `server {
    listen 80;
    server_name example.com;
    root /var/www/html;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }
    location ~ \\.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
    }
    location ~* \\.(css|js|png|jpg|gif|ico)$ {
        expires 30d;
    }
}`,
  redirects: `RewriteEngine On
RewriteRule ^old-page$ /new-page [R=301,L]
RewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]
RewriteRule ^docs$ https://docs.example.com [R=301,L]`
};

document.getElementById('convert-preset').addEventListener('change', function() {
  if (this.value && PRESETS[this.value]) {
    document.getElementById('convert-input').value = PRESETS[this.value];
  }
});

function runConvert() {
  const input = document.getElementById('convert-input').value.trim();
  const output = document.getElementById('convert-output');
  const status = document.getElementById('convert-status');
  const btn = document.getElementById('convert-btn');

  if (!input) { status.textContent = 'Paste a config first'; return; }

  btn.disabled = true;
  btn.textContent = 'Converting...';
  status.textContent = 'Streaming from gpt-5.4-mini...';
  output.textContent = '';

  const es = new EventSource('/api/convert?' + new URLSearchParams({_t: Date.now()}));
  // EventSource is GET-only; use fetch+POST with ReadableStream instead
  es.close();

  fetch('/api/convert', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({config: input})
  }).then(response => {
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    function read() {
      reader.read().then(({done, value}) => {
        if (done) {
          btn.disabled = false;
          btn.textContent = 'Convert →';
          status.textContent = 'Done';
          return;
        }
        buffer += decoder.decode(value, {stream: true});
        const lines = buffer.split('\n');
        buffer = lines.pop();
        for (const line of lines) {
          if (line.startsWith('data: ')) {
            const text = line.slice(6);
            if (text === '[DONE]') continue;
            output.textContent += text + '\n';
          }
        }
        output.scrollTop = output.scrollHeight;
        read();
      });
    }
    read();
  }).catch(err => {
    output.textContent = '// Error: ' + err.message;
    btn.disabled = false;
    btn.textContent = 'Convert →';
    status.textContent = 'Failed';
  });
}

function copyOutput() {
  const text = document.getElementById('convert-output').textContent;
  navigator.clipboard.writeText(text).then(() => {
    const btn = event.target;
    btn.textContent = 'Copied!';
    setTimeout(() => btn.textContent = 'Copy', 1500);
  });
}
</script>

<?php App::render('/components/_code', [
    'label' => 'CLI usage (also available as a command-line tool)',
    'code'  => <<<'BASH'
# Pipe any config — get app.php on stdout
cat .htaccess | uv run examples/agents/config_converter.py

# Interactive mode
uv run examples/agents/config_converter.py
BASH, 'lang' => 'bash']); ?>

<h2>WordPress Example</h2>
<p>A complete <code>app.php</code> that runs WordPress on ZealPHP:</p>

<?php App::render('/components/_code', [
    'label' => 'app.php — WordPress on ZealPHP',
    'code'  => <<<'PHP'
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\G;

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 9501);

// Redirect /wp-admin to /wp-admin/index.php
$app->route('/wp-admin', function() {
    $qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /wp-admin/index.php' . $qs);
    return 301;
});

// Fallback: unmatched URLs → WordPress front controller
// Replaces Apache's: RewriteRule . /index.php [L]
$app->setFallback(function() {
    $g = G::instance();
    $g->server['PHP_SELF'] = '/index.php';
    $g->server['SCRIPT_NAME'] = '/index.php';
    $g->server['SCRIPT_FILENAME'] = App::$cwd . '/public/index.php';
    App::includeFile(App::$cwd . '/public/index.php');
});

$app->run(['task_worker_num' => 0]);
PHP]); ?>

<h2>Setup Steps</h2>
<p>See the full working example: <a href="https://github.com/sibidharan/zealphp-wordpress" target="_blank">github.com/sibidharan/zealphp-wordpress</a></p>
<ol style="line-height:2">
  <li>Create a ZealPHP project: <code>composer create-project sibidharan/zealphp-project my-wordpress</code></li>
  <li>Download WordPress into <code>public/</code>: <code>cd my-wordpress/public && wp core download</code></li>
  <li>Configure <code>public/wp-config.php</code> with your database settings</li>
  <li>Write <code>app.php</code> as shown above</li>
  <li>Start: <code>php app.php</code> (or <code>php app.php start -p 9501 -d</code> to daemonize)</li>
  <li>Visit <code>http://localhost:9501/wp-admin/install.php</code> to complete installation</li>
</ol>

<h2>CGI Worker Architecture</h2>
<p><code>App::includeFile()</code> runs each PHP file in a separate process via <code>proc_open</code>. This gives every request a clean PHP interpreter with true global scope — exactly like Apache's prefork MPM.</p>

<?php App::render('/components/_code', [
    'label' => 'How App::includeFile() works',
    'code'  => <<<'TEXT'
OpenSwoole Worker (long-lived)          CGI Worker (per-request)
┌─────────────────────────┐             ┌──────────────────────────┐
│                         │  proc_open  │  php cgi_worker.php      │
│  Route matched          │ ──────────► │                          │
│  App::includeFile()     │             │  TRUE global scope:      │
│                         │   stdin     │  ├─ $_SERVER, $_GET, etc. │
│  Serializes context:    │ ──────────► │  ├─ $_COOKIE, $_FILES    │
│  ├─ $_SERVER, $_GET     │  (POST body)│  │                       │
│  ├─ $_POST, $_COOKIE    │             │  ├─ uopz captures:       │
│  └─ Request body        │             │  │  header(), setcookie() │
│                         │   stdout    │  │  http_response_code()  │
│  Reads response:        │ ◄────────── │  │                       │
│  ├─ Body from stdout    │             │  ├─ include file.php     │
│  ├─ Metadata from stderr│   stderr    │  │  ← app runs at global │
│  │  (status, headers,   │ ◄────────── │  │    scope              │
│  │   cookies as JSON)   │             │  │                       │
│  └─ Applies to response │             │  └─ Process exits (clean)│
└─────────────────────────┘             └──────────────────────────┘
TEXT]); ?>

<h3>What the CGI worker handles</h3>
<table class="ztable">
<tr><th>Feature</th><th>How</th></tr>
<tr><td>All HTTP methods</td><td><code>$_SERVER['REQUEST_METHOD']</code> passed via context; request body piped to stdin (<code>php://input</code>)</td></tr>
<tr><td><code>header()</code> / <code>header_remove()</code></td><td>Captured via <code>uopz_set_return</code> — sent back as JSON metadata</td></tr>
<tr><td><code>setcookie()</code> / <code>setrawcookie()</code></td><td>Captured — applied to response by parent worker</td></tr>
<tr><td><code>http_response_code()</code> / <code>headers_list()</code></td><td>Captured — status and headers returned in metadata</td></tr>
<tr><td><code>exit()</code> / <code>die()</code></td><td><code>register_shutdown_function</code> flushes output and metadata</td></tr>
<tr><td>SSE streaming</td><td>Detects <code>text/event-stream</code>; streams via <code>flush()</code> like Apache</td></tr>
<tr><td>Static files</td><td>Served directly by OpenSwoole — never reaches PHP</td></tr>
<tr><td>File uploads / Sessions</td><td><code>$_FILES</code> via context; PHP native sessions work in CGI process</td></tr>
</table>

<h2>CLI Management</h2>

<?php App::render('/components/_code', [
    'label' => 'CLI commands',
    'code'  => <<<'BASH'
php app.php                     # Start with defaults
php app.php start -p 9501       # Start on port 9501
php app.php start -p 9501 -d   # Start daemonized
php app.php stop                # Stop the server (reads PID file)
php app.php status              # Check if server is running
php app.php start -w 8          # Start with 8 workers
php app.php --help              # Show all options
BASH, 'lang' => 'bash']); ?>

<h2>Limitations</h2>
<div class="callout warn">
<p><strong>Performance:</strong> Each PHP file request spawns a new process. Static files bypass this (served by OpenSwoole). For high-traffic production, convert hot paths to native ZealPHP routes.</p>
<p><strong>Streaming:</strong> SSE works in CGI mode via <code>flush()</code>. WebSocket requires native ZealPHP routes (<code>App::ws()</code>).</p>
<p><strong>Hybrid approach:</strong> Mix native routes (coroutine mode, high performance) with legacy PHP file serving (CGI mode) in the same app. Explicit <code>$app->route()</code> handlers run directly in the worker.</p>
</div>

</div>
</section>

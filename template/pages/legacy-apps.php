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
// That's it — PHP files in public/ are served automatically
PHP]); ?>

<h2>CGI Worker Architecture</h2>
<p>The key challenge with running legacy PHP apps on a long-lived server is <strong>global scope</strong>. PHP apps like WordPress use bare variable assignments (<code>$menu = []</code>) and <code>global</code> declarations that assume code runs at the top level of a script — not inside a closure or function.</p>

<p>ZealPHP solves this with <code>src/cgi_worker.php</code> — a standalone PHP script that runs each request in a <strong>fresh PHP process at true global scope</strong> via <code>proc_open</code>:</p>

<?php App::render('/components/_code', [
    'label' => 'CGI worker: how App::includeFile() works internally',
    'code'  => <<<'TEXT'
OpenSwoole Worker (long-lived)          CGI Worker (per-request)
┌─────────────────────────┐             ┌──────────────────────────┐
│                         │  proc_open  │  php cgi_worker.php      │
│  Route matched          │ ──────────► │                          │
│  App::includeFile()     │             │  TRUE global scope:      │
│                         │   stdin     │  ├─ $_SERVER from context │
│  Serializes context:    │ ──────────► │  ├─ $_GET, $_POST, etc.  │
│  ├─ $_SERVER            │  (POST body)│  ├─ $_COOKIE, $_FILES    │
│  ├─ $_GET, $_POST       │             │  │                       │
│  ├─ $_COOKIE, $_FILES   │             │  ├─ uopz captures:       │
│  └─ Request body        │             │  │  header() → array     │
│                         │   stdout    │  │  setcookie() → array  │
│  Reads response:        │ ◄────────── │  │  http_response_code() │
│  ├─ Body from stdout    │             │  │                       │
│  ├─ Metadata from stderr│   stderr    │  ├─ include file.php     │
│  │  (status, headers,   │ ◄────────── │  │  ← WordPress/app runs│
│  │   cookies as JSON)   │             │  │    at global scope    │
│  │                      │             │  │                       │
│  └─ Applies to response │             │  └─ Process exits (clean)│
└─────────────────────────┘             └──────────────────────────┘
TEXT]); ?>

<h3>Why CGI instead of fork?</h3>
<p>An earlier approach used <code>OpenSwoole\Process</code> (fork) with closures. While fork is faster, PHP closures create their own variable scope. When WordPress does <code>$_wp_submenu_nopriv = array()</code> at the top of an included file, the variable goes into the closure scope — not <code>$GLOBALS</code>. Functions using <code>global $_wp_submenu_nopriv</code> then see <code>null</code> instead of the array.</p>
<p>The CGI worker avoids this entirely: the PHP process starts fresh, and <code>include</code> at the top level of the script operates at true global scope.</p>

<h3>What the CGI worker handles</h3>
<table class="ztable">
<tr><th>Feature</th><th>How</th></tr>
<tr><td>All HTTP methods</td><td><code>$_SERVER['REQUEST_METHOD']</code> passed via context; request body piped to stdin (<code>php://input</code>)</td></tr>
<tr><td><code>header()</code></td><td>Captured via <code>uopz_set_return</code> — stored in array, sent back as JSON metadata</td></tr>
<tr><td><code>setcookie()</code></td><td>Captured via <code>uopz_set_return</code> — applied to response by parent worker</td></tr>
<tr><td><code>http_response_code()</code></td><td>Captured — status code returned in metadata</td></tr>
<tr><td><code>exit()</code> / <code>die()</code></td><td>Terminates the CGI process; <code>register_shutdown_function</code> flushes output and metadata</td></tr>
<tr><td>Static files</td><td>Served directly by OpenSwoole's <code>enable_static_handler</code> — never reaches PHP</td></tr>
<tr><td>File uploads</td><td><code>$_FILES</code> passed via context; temp files are on the same filesystem</td></tr>
<tr><td>Sessions</td><td>PHP's native session handling works in the CGI process (file-based by default)</td></tr>
</table>

<h2>Replacing .htaccess</h2>
<p>ZealPHP handles routing natively. Common Apache rewrite rules map directly to ZealPHP features:</p>

<table class="ztable">
<tr><th>Apache .htaccess</th><th>Purpose</th><th>ZealPHP equivalent</th></tr>
<tr>
  <td><code>RewriteEngine On</code></td>
  <td>Enable URL rewriting</td>
  <td>Not needed — ZealPHP routes natively</td>
</tr>
<tr>
  <td><pre style="margin:0">RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]</pre></td>
  <td>Front controller (WordPress pretty permalinks)</td>
  <td><code>$app->setFallback(function() { ... })</code></td>
</tr>
<tr>
  <td><code>DirectoryIndex index.php</code></td>
  <td>Default directory index</td>
  <td>Built-in — implicit routes serve <code>index.php</code> for directories</td>
</tr>
<tr>
  <td><code>Options -Indexes</code></td>
  <td>Disable directory listing</td>
  <td>Not applicable — ZealPHP never lists directories</td>
</tr>
<tr>
  <td><code>&lt;FilesMatch "\.php$"&gt;</code></td>
  <td>PHP handler</td>
  <td>Not needed — ZealPHP IS the PHP runtime</td>
</tr>
</table>

<h2>Replacing nginx Config</h2>
<table class="ztable">
<tr><th>nginx directive</th><th>Purpose</th><th>ZealPHP equivalent</th></tr>
<tr>
  <td><code>location / { try_files $uri $uri/ /index.php?$args; }</code></td>
  <td>Front controller pattern</td>
  <td><code>$app->setFallback(fn() => App::includeFile(...))</code></td>
</tr>
<tr>
  <td><code>location ~ \.php$ { fastcgi_pass ...; }</code></td>
  <td>PHP processing via FastCGI</td>
  <td>Not needed — ZealPHP serves PHP directly</td>
</tr>
<tr>
  <td><code>location ~* \.(css|js|png)$ { expires 30d; }</code></td>
  <td>Static file caching</td>
  <td>OpenSwoole <code>enable_static_handler</code> serves statics; add Cache-Control via middleware</td>
</tr>
</table>

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
<ol style="line-height:2">
  <li>Create a ZealPHP project: <code>composer create-project sibidharan/zealphp-project my-wordpress</code></li>
  <li>Download WordPress into <code>public/</code>: <code>cd my-wordpress/public && wp core download</code></li>
  <li>Configure <code>public/wp-config.php</code> with your database settings</li>
  <li>Write <code>app.php</code> as shown above</li>
  <li>Start: <code>php app.php</code> (or <code>php app.php start -p 9501 -d</code> to daemonize)</li>
  <li>Visit <code>http://localhost:9501/wp-admin/install.php</code> to complete installation</li>
</ol>

<h2>CLI Management</h2>
<p>ZealPHP includes built-in process management:</p>

<?php App::render('/components/_code', [
    'label' => 'CLI commands',
    'code'  => <<<'BASH'
php app.php                     # Start with defaults
php app.php start -p 9501       # Start on port 9501
php app.php start -p 9501 -d   # Start daemonized
php app.php stop                # Stop the server (reads PID file)
php app.php status              # Check if server is running
php app.php start -w 8          # Start with 8 workers
BASH, 'lang' => 'bash']); ?>

<h2>Limitations</h2>
<div class="callout warn">
<p><strong>Performance:</strong> Each PHP file request spawns a new process via <code>proc_open</code>. This is slower than coroutine mode but necessary for apps that rely on global state. Static files bypass this entirely (served by OpenSwoole). For high-traffic production use, consider converting hot paths to native ZealPHP routes.</p>
<p><strong>Persistent connections:</strong> Database connections are per-process and don't persist across requests. Connection pooling requires native ZealPHP integration.</p>
<p><strong>Streaming:</strong> Legacy apps cannot use ZealPHP's streaming/SSE/WebSocket features from within the CGI process. These require native ZealPHP routes.</p>
<p><strong>Hybrid approach:</strong> You can mix native ZealPHP routes (coroutine mode, high performance) with legacy PHP file serving (CGI mode) in the same application. Explicit routes defined via <code>$app->route()</code> run directly in the worker — only PHP files in <code>public/</code> go through the CGI worker.</p>
</div>

</div>
</section>

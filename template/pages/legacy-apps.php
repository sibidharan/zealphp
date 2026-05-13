<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Running Legacy PHP Apps</h1>
<p class="section-desc">ZealPHP can serve unmodified PHP applications like WordPress, Drupal, or any traditional PHP app. Superglobals mode + CGI-style process isolation provides Apache mod_php-like behavior on top of OpenSwoole's async architecture.</p>

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
  <td>Each request runs in a separate PHP process — clean global state, no "Cannot redeclare" errors</td>
  <td>mod_prefork MPM</td>
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

<h2>How Process Isolation Works</h2>
<p><code>App::includeFile()</code> runs each PHP file in a separate process via <code>proc_open</code>. This gives every request a clean PHP interpreter — exactly like Apache's prefork MPM.</p>

<?php App::render('/components/_code', [
    'label' => 'Request lifecycle in superglobals mode',
    'code'  => <<<'TEXT'
Browser Request
    │
    ▼
OpenSwoole Worker (long-lived, handles routing)
    │
    ├─ Static files (.css, .js, .png) → served by OpenSwoole directly
    │
    ├─ Explicit routes ($app->route) → handler runs in worker
    │
    └─ PHP files in public/ → App::includeFile()
         │
         ├─ Superglobals ON → proc_open('php cgi_worker.php file.php')
         │   │
         │   ▼
         │   New PHP Process (TRUE global scope)
         │   ├─ $_SERVER, $_GET, $_POST, $_COOKIE set from request
         │   ├─ header()/setcookie() captured via uopz
         │   ├─ include file.php ← WordPress runs here
         │   ├─ Output + response metadata sent back via pipes
         │   └─ Process exits (clean slate for next request)
         │
         └─ Superglobals OFF → direct include (coroutine-safe)
TEXT]); ?>

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
<p><strong>Performance:</strong> Each request spawns a new PHP process. This is slower than coroutine mode but necessary for apps that rely on global state. For high-traffic production use, consider converting hot paths to native ZealPHP routes.</p>
<p><strong>Persistent connections:</strong> Database connections are per-process and don't persist across requests. Connection pooling requires native ZealPHP integration.</p>
<p><strong>Streaming:</strong> Legacy apps cannot use ZealPHP's streaming/SSE/WebSocket features from within the CGI process. These require native ZealPHP routes.</p>
</div>

</div>
</section>

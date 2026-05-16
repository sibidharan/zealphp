<?php use ZealPHP\App; ?>

<section class="section">
  <div class="container">
    <h1 class="section-title">Getting Started</h1>
    <p class="section-desc">From a fresh machine to a running ZealPHP app — install dependencies, scaffold a project, write your first route, deploy.</p>

    <div style="margin:1.25rem 0;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <a href="/learn" class="btn-cta">Learn by Building &rarr;</a>
      <span style="font-size:.85rem;color:var(--text-muted)">14-lesson tutorial — build a Notes + AI Chat app with htmx, SQLite, and SSE streaming.</span>
    </div>

    <!-- TL;DR install — surfaced above the architecture diagram so visitors
         who just want to try it can copy a single line. Full walkthrough
         (with manual steps, scaffold, first page, deploy) lives below. -->
    <div class="callout info" style="margin:1.25rem 0 2rem;padding:1.1rem 1.3rem;border-left:4px solid var(--accent)">
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem;font-weight:700;color:var(--text)">
        <span style="font-size:1.1rem">⚡</span>
        <span>TL;DR — install in one line</span>
        <span style="margin-left:auto;font-size:.7rem;color:var(--text-muted);font-weight:500">Ubuntu / Debian · macOS · WSL2</span>
      </div>
      <div class="qs-block" style="background:#0c0a09;color:#e7e5e4;padding:.8rem 1rem;margin:0">
        <div class="qs-line" style="display:flex;align-items:center;gap:.5rem;font-family:var(--font-mono);font-size:.92rem">
          <span class="qs-prompt" style="color:#a8a29e">$</span>
          <span class="qs-cmd" style="flex:1">curl -fsSL https://php.zeal.ninja/install.sh | sudo bash</span>
          <button class="qs-copy" data-copy="curl -fsSL https://php.zeal.ninja/install.sh | sudo bash" style="background:rgba(245,158,11,.15);color:var(--accent);border:1px solid rgba(245,158,11,.3);padding:.25rem .6rem;border-radius:4px;font-size:.75rem;cursor:pointer">copy</button>
        </div>
      </div>
      <p style="margin:.65rem 0 0;font-size:.82rem;color:var(--text-muted)">
        Installs PHP 8.3 + OpenSwoole + uopz + composer. Auto-detects your distro and bails with manual steps if it can't install for you (Fedora, Arch, Alpine, etc.). The detailed walkthrough below covers manual install, Docker, scaffolding, and deploy. <a href="#install" style="color:var(--accent);font-weight:600">Inspect the script first ↓</a>
      </p>
    </div>

    <!-- One Server. Everything. -->
    <div class="arch-compare" style="margin:2rem 0 2.5rem">
      <div class="arch-box complex">
        <h3>Your AI app without ZealPHP</h3>
        <div class="arch-node">Express / FastAPI server</div>
        <div class="arch-node">Redis for session state</div>
        <div class="arch-node">Bull / Celery for background jobs</div>
        <div class="arch-node">Socket.io for WebSocket</div>
        <div class="arch-node">SSE proxy middleware</div>
        <div class="arch-node">Nginx reverse proxy</div>
        <div style="margin-top:.75rem;font-size:.78rem;color:#991b1b;font-weight:600">6 services. 6 failure points.</div>
      </div>
      <div class="arch-vs">vs</div>
      <div class="arch-box simple">
        <h3>Your AI app on ZealPHP</h3>
        <div class="arch-node">HTTP routes + API</div>
        <div class="arch-node">WebSocket (built-in)</div>
        <div class="arch-node">SSE streaming (built-in)</div>
        <div class="arch-node">Task workers (built-in)</div>
        <div class="arch-node">Shared memory Store (built-in)</div>
        <div class="arch-node">Sessions + Timers (built-in)</div>
        <div style="margin-top:.75rem;font-size:.78rem;color:#166534;font-weight:600">1 process. <code>php app.php</code></div>
      </div>
    </div>
    <p class="compare-verdict">No Redis. No message queue. No sidecar. No microservice fan-out.</p>

    <!-- Step nav -->
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin:1.5rem 0;font-size:.82rem">
      <a href="#prereqs" style="padding:.4rem .8rem;background:var(--bg-alt);border:1px solid var(--border);border-radius:5px;color:var(--text);text-decoration:none">1. Prerequisites</a>
      <a href="#known-risks" style="padding:.4rem .8rem;background:var(--bg-alt);border:1px solid var(--border);border-radius:5px;color:var(--text);text-decoration:none">2. Known risks</a>
      <a href="#install" style="padding:.4rem .8rem;background:var(--bg-alt);border:1px solid var(--border);border-radius:5px;color:var(--text);text-decoration:none">3. Install</a>
      <a href="#scaffold" style="padding:.4rem .8rem;background:var(--bg-alt);border:1px solid var(--border);border-radius:5px;color:var(--text);text-decoration:none">4. Scaffold</a>
      <a href="#first-page" style="padding:.4rem .8rem;background:var(--bg-alt);border:1px solid var(--border);border-radius:5px;color:var(--text);text-decoration:none">5. First page</a>
      <a href="#first-route" style="padding:.4rem .8rem;background:var(--bg-alt);border:1px solid var(--border);border-radius:5px;color:var(--text);text-decoration:none">6. Framework routes</a>
      <a href="#deploy" style="padding:.4rem .8rem;background:var(--bg-alt);border:1px solid var(--border);border-radius:5px;color:var(--text);text-decoration:none">7. Deploy</a>
    </div>

    <h2 id="prereqs" style="margin-top:2rem">1. Prerequisites</h2>
    <table class="ztable">
      <tr><th>Package</th><th>Version</th><th>Why</th></tr>
      <tr><td><code>PHP</code></td><td>8.3+</td><td>Tested on 8.3 and 8.4; OpenSwoole 26.2+ adds PHP 8.5 support</td></tr>
      <tr><td><code>OpenSwoole</code></td><td>22.1+</td><td>Async runtime, HTTP/WebSocket server, coroutines (26.2+ for PHP 8.5)</td></tr>
      <tr><td><code>uopz</code></td><td>any</td><td>Overrides <code>header()</code>, <code>setcookie()</code>, <code>session_*</code> at runtime</td></tr>
      <tr><td><code>composer</code></td><td>2.x</td><td>Dependency management</td></tr>
      <tr><td><code>uv</code> (optional)</td><td>any</td><td>Only for AI agent examples (Python)</td></tr>
    </table>

    <h2 id="known-risks" style="margin-top:2.5rem">2. Before you ship: known risks</h2>
    <div class="callout warn">
      <strong>ZealPHP runs as a long-lived process.</strong> This changes the rules from PHP-FPM:
      <ul style="margin:.5rem 0 0;padding-left:1.25rem">
        <li style="margin-bottom:.35rem"><strong>Global state leaks</strong> — code written for request-per-process may accidentally retain state across requests. Audit your superglobals and <code>static</code> variables.</li>
        <li style="margin-bottom:.35rem"><strong>Coroutine safety</strong> — references to <code>G::instance()</code> must not be held across <code>yield</code> points; each coroutine has its own context.</li>
        <li style="margin-bottom:.35rem"><strong>uopz compatibility bridge is experimental</strong> — <code>session_start()</code>, <code>header()</code>, etc. are virtualized via <a href="https://pecl.php.net/package/uopz" target="_blank" rel="noopener">uopz</a>. Edge cases exist; report them.</li>
        <li style="margin-bottom:.35rem"><strong>Memory growth</strong> — workers stay alive between requests; profile for leaks under sustained load.</li>
        <li><strong>API stability</strong> — v0.2.x; breaking changes possible until v1.0. Pin a version in <code>composer.json</code>.</li>
      </ul>
      <p style="margin:.5rem 0 0">Report issues at <a href="https://github.com/sibidharan/zealphp/issues" target="_blank" rel="noopener">GitHub Issues</a>. Security disclosures: see <a href="https://github.com/sibidharan/zealphp/blob/master/SECURITY.md" target="_blank" rel="noopener">SECURITY.md</a>.</p>
    </div>

    <h2 id="install" style="margin-top:2.5rem">3. Install</h2>

    <div class="callout info" style="margin-bottom:1rem">
      <strong>PHP 8.3, 8.4, or 8.5.</strong> OpenSwoole 22.1+ works on PHP 8.3 and 8.4; OpenSwoole 26.2+ (released Feb 2026) added PHP 8.5 support. If you only have one PHP version available, 8.3 is the safest default.
    </div>

    <p>One-line install on Ubuntu/Debian — pipes <code>setup.sh</code> straight from this site, no clone required:</p>

    <?php App::render('/components/_code', [
      'label' => 'One-line install (Ubuntu/Debian)',
      'lang' => 'bash',
      'code' => <<<'BASH'
curl -fsSL https://php.zeal.ninja/install.sh | sudo bash
# Installs: PHP 8.3, OpenSwoole, uopz, composer
BASH
    ]); ?>

    <div class="callout info" style="margin-top:.75rem">
      <strong>Want to inspect before piping to <code>sudo</code>?</strong>
      <br>
      <code style="display:block;margin-top:.5rem;padding:.5rem .75rem;background:rgba(0,0,0,.05);border-radius:4px;font-size:.85rem">curl -fsSL https://php.zeal.ninja/install.sh -o install.sh &amp;&amp; less install.sh &amp;&amp; sudo bash install.sh</code>
      Or fetch from GitHub directly to pin a specific commit:
      <code style="display:block;margin-top:.5rem;padding:.5rem .75rem;background:rgba(0,0,0,.05);border-radius:4px;font-size:.85rem">curl -fsSL https://raw.githubusercontent.com/sibidharan/zealphp/master/setup.sh | sudo bash</code>
    </div>

    <p style="margin-top:1.5rem">If you'd rather clone first (e.g. you want to send a PR):</p>

    <?php App::render('/components/_code', [
      'label' => 'From a cloned checkout',
      'lang' => 'bash',
      'code' => <<<'BASH'
git clone https://github.com/sibidharan/zealphp.git
cd zealphp
sudo bash setup.sh
BASH
    ]); ?>

    <p style="margin-top:1.5rem">Or install manually:</p>

    <?php App::render('/components/_code', [
      'label' => 'Manual install',
      'lang' => 'bash',
      'code' => <<<'BASH'
# 1. PHP 8.3
sudo add-apt-repository ppa:ondrej/php
sudo apt install php8.3 php8.3-cli php8.3-dev php8.3-mbstring php-pear

# 2. OpenSwoole (via PECL)
sudo pecl install openswoole
echo "extension=openswoole.so" | sudo tee /etc/php/8.3/cli/conf.d/zz-openswoole.ini
echo "short_open_tag=On" | sudo tee -a /etc/php/8.3/cli/conf.d/zz-openswoole.ini

# 3. uopz (via PECL)
sudo pecl install uopz
echo "extension=uopz.so" | sudo tee /etc/php/8.3/cli/conf.d/zz-uopz.ini

# 4. Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

# 5. Verify
php -m | grep -E 'openswoole|uopz'
BASH
    ]); ?>

    <div class="callout info" style="margin-top:1rem">
      <strong>Why <code>short_open_tag=On</code>?</strong> ZealPHP templates often use <code>&lt;?= $var ?&gt;</code> for compact output. This is technically a "short echo tag" (always on in PHP 8) but enabling <code>short_open_tag</code> matches the recommended setup for OpenSwoole.
    </div>

    <div class="callout info" style="margin-top:1rem">
      <strong>Docker?</strong> The framework repo includes a <code>Dockerfile</code> and <code>docker-compose.yml</code>.
      Run <code>docker compose up</code> from the cloned repo to get a fully configured container.
    </div>

    <h2 id="scaffold" style="margin-top:2.5rem">4. Scaffold a project</h2>

    <p>Three paths depending on what you're building:</p>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;margin-top:1rem">
      <div class="card" style="border:1px solid var(--border);padding:1.25rem">
        <h3 style="margin-bottom:.5rem">Starter project</h3>
        <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:.75rem">Clean app tree with examples. Best for new apps.</p>
        <?php App::render('/components/_code', [
          'label' => '',
          'lang' => 'bash',
          'code' => <<<'BASH'
composer create-project \
  sibidharan/zealphp-project:^0.2.10 \
  my-app
cd my-app && php app.php
BASH
        ]); ?>
      </div>
      <div class="card" style="border:1px solid var(--border);padding:1.25rem">
        <h3 style="margin-bottom:.5rem">Framework repo</h3>
        <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:.75rem">This very site, running locally. Read source + live demos.</p>
        <?php App::render('/components/_code', [
          'label' => '',
          'lang' => 'bash',
          'code' => <<<'BASH'
git clone \
  https://github.com/sibidharan/zealphp.git
cd zealphp
composer install && php app.php
BASH
        ]); ?>
      </div>
      <div class="card" style="border:1px solid var(--border);padding:1.25rem">
        <h3 style="margin-bottom:.5rem">WordPress</h3>
        <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:.75rem">Unmodified WordPress on OpenSwoole.</p>
        <?php App::render('/components/_code', [
          'label' => '',
          'lang' => 'bash',
          'code' => <<<'BASH'
git clone \
  https://github.com/sibidharan/zealphp-wordpress.git
cd zealphp-wordpress
composer install && php app.php
BASH
        ]); ?>
      </div>
    </div>

    <!-- How it works -->
    <div style="background:var(--bg-alt);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;margin-top:2rem">
      <h3 style="margin-bottom:.75rem">How ZealPHP works — the LAMP mental model</h3>
      <p style="color:var(--text-muted);font-size:.9rem;line-height:1.7;margin-bottom:.75rem">
        In LAMP, <strong>Apache</strong> was the server and PHP ran inside it. In ZealPHP, <strong>OpenSwoole</strong> is the server and PHP runs inside it. Same idea, different engine.
      </p>
      <table class="ztable" style="font-size:.85rem">
        <tr><th>LAMP</th><th>ZealPHP</th></tr>
        <tr><td>Apache / Nginx</td><td>OpenSwoole (built into <code>php app.php</code>)</td></tr>
        <tr><td><code>htdocs/about.php</code> → <code>/about.php</code></td><td><code>public/about.php</code> → <code>/about</code></td></tr>
        <tr><td><code>$_GET</code>, <code>$_POST</code>, <code>$_SESSION</code></td><td>Same — all work unchanged</td></tr>
        <tr><td><code>session_start()</code>, <code>header()</code></td><td>Same — overridden via uopz</td></tr>
        <tr><td>One process per request</td><td>One process, thousands of concurrent coroutines</td></tr>
        <tr><td>Restart Apache after config changes</td><td>Restart <code>php app.php</code> after code changes</td></tr>
        <tr><td>Needs Redis for shared state</td><td>Built-in <code>Store</code> — cross-worker shared memory</td></tr>
        <tr><td>Needs Socket.io / Ratchet for WebSocket</td><td>Built-in <code>App::ws()</code></td></tr>
      </table>
      <p style="color:var(--text-muted);font-size:.85rem;margin-top:.75rem">
        The difference: your PHP process stays alive between requests. That means persistent connections, shared memory, WebSocket, streaming — all without leaving PHP.
      </p>
    </div>

    <h2 id="first-page" style="margin-top:2.5rem">5. Your first page — just drop a file</h2>

    <p>Create a file in <code>public/</code>. It becomes a route. No framework code needed.</p>

    <?php App::render('/components/_code', [
      'label' => 'public/hello.php — plain PHP, like you\'ve always written',
      'code' => <<<'PHP'
<?php
session_start();
$_SESSION['visits'] = ($_SESSION['visits'] ?? 0) + 1;
?>
<h1>Hello from ZealPHP</h1>
<p>You've visited this page <?= $_SESSION['visits'] ?> time(s).</p>
<p>Query string: <?= htmlspecialchars($_GET['name'] ?? 'world') ?></p>
PHP
    ]); ?>

    <p style="margin-top:.75rem">Start the server and visit <code>http://localhost:8080/hello?name=PHP</code>:</p>

    <?php App::render('/components/_code', [
      'label' => '',
      'lang' => 'bash',
      'code' => 'php app.php'
    ]); ?>

    <p style="margin-top:.75rem">That's it. No <code>$app->route()</code>, no annotations, no config files. Same for APIs — drop a file in <code>api/</code>:</p>

    <?php App::render('/components/_code', [
      'label' => 'api/users/get.php → GET /api/users',
      'code' => <<<'PHP'
<?php
$get = function() {
    return ['users' => ['alice', 'bob'], 'count' => 2];
};
PHP
    ]); ?>

    <div class="callout info" style="margin-top:1rem">
      <strong>This is how you migrate.</strong> Move your existing PHP files into <code>public/</code>. They work immediately. When you need WebSocket, streaming, or coroutines — that's when you use <code>$app->route()</code>. See <a href="/routing">Routing</a> for the full picture.
    </div>

    <h2 id="first-route" style="margin-top:2.5rem">6. Framework routes — when you need more</h2>

    <p>For URL parameters, WebSocket, streaming, or middleware — use programmatic routes in <code>app.php</code>:</p>

    <?php App::render('/components/_code', [
      'label' => 'app.php — minimal app',
      'code' => <<<'PHP'
<?php
require 'vendor/autoload.php';
use ZealPHP\App;

$app = App::init('0.0.0.0', 8080);

// Return array → auto JSON
$app->route('/api/hello', function() {
    return ['message' => 'Hello from ZealPHP', 'time' => time()];
});

// URL params (Flask-style)
$app->route('/user/{id}', function($id) {
    return ['user_id' => $id];
});

// Return int → HTTP status
$app->route('/forbidden', fn() => 403);

// Streaming
$app->route('/stream', function() {
    return (function() {
        yield "<h1>Streaming</h1>\n";
        for ($i = 1; $i <= 5; $i++) {
            yield "Chunk $i<br>\n";
            usleep(200000);
        }
    })();
});

$app->run(['task_worker_num' => 0]);
PHP
    ]); ?>

    <p>Restart (<code>Ctrl+C</code>, then <code>php app.php</code>) and visit:</p>
    <ul style="line-height:2;margin-left:1.5rem">
      <li><code>http://localhost:8080/api/hello</code> — JSON</li>
      <li><code>http://localhost:8080/user/42</code> — URL param</li>
      <li><code>http://localhost:8080/forbidden</code> — 403</li>
      <li><code>http://localhost:8080/stream</code> — streaming response</li>
    </ul>

    <div class="callout info" style="margin-top:1rem">
      <strong>What next?</strong>
      <a href="/routing">Routing</a> · <a href="/responses">Response types</a> · <a href="/coroutines">Coroutines</a> · <a href="/streaming">Streaming</a> · <a href="/ws">WebSocket</a> · <a href="/middleware">Middleware</a> · <a href="/api">File-based REST API</a>
    </div>

    <h2 id="deploy" style="margin-top:2.5rem">7. Deploy</h2>

    <p>ZealPHP includes built-in CLI management. For production, use the bundled systemd service:</p>

    <?php App::render('/components/_code', [
      'label' => 'Install as systemd service',
      'lang' => 'bash',
      'code' => <<<'BASH'
# 1. Adjust paths in deploy/zealphp.service (WorkingDirectory, User)
sudo cp deploy/zealphp.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now zealphp

# 2. Check status & logs
sudo systemctl status zealphp
journalctl -u zealphp -f
BASH
    ]); ?>

    <p style="margin-top:1rem">Or run standalone (without systemd):</p>

    <?php App::render('/components/_code', [
      'label' => 'CLI management',
      'lang' => 'bash',
      'code' => <<<'BASH'
php app.php start -p 8080 -d   # daemonize on port 8080
php app.php status              # check if running
php app.php stop                # stop
php app.php start -w 16 -d     # 16 workers, daemonized
php app.php --help              # all flags
BASH
    ]); ?>

    <h2 style="margin-top:2.5rem">Verification</h2>
    <p>Confirm everything is wired up:</p>
    <?php App::render('/components/_code', [
      'label' => 'Smoke test',
      'lang' => 'bash',
      'code' => <<<'BASH'
# Extensions loaded?
php -m | grep -E 'openswoole|uopz'

# Server responds?
curl -s http://localhost:8080/ | head -5

# Composer dependencies?
composer show sibidharan/zealphp
BASH
    ]); ?>

    <div class="callout warn" style="margin-top:1.5rem">
      <strong>Troubleshooting</strong><br>
      <strong>Port in use?</strong> Run <code>php app.php stop</code> or use <code>-p 9000</code> for a different port.<br>
      <strong>Extension not loaded?</strong> Check <code>php --ini</code> for the config path, ensure <code>extension=openswoole.so</code> is in a loaded <code>.ini</code>.<br>
      <strong>Permission denied on port 80?</strong> Use a port above 1024, or run with <code>setcap</code> / behind a reverse proxy.
    </div>

    <div style="margin:2.5rem 0 0;display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
      <a href="/learn" class="btn-cta">Learn by Building &rarr;</a>
      <span style="font-size:.85rem;color:var(--text-muted)">Everything verified? Build a Notes + AI Chat app over 14 lessons &mdash; htmx, SQLite, and SSE streaming.</span>
    </div>

  </div>
</section>

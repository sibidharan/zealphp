<?php use ZealPHP\App; ?>

<section class="section">
  <div class="container">
    <h1 class="section-title">Getting Started</h1>
    <p class="section-desc">From a fresh machine to a running ZealPHP app — install dependencies, scaffold a project, write your first route, deploy.</p>

    <div class="gs-learn-cta">
      <a href="/learn" class="btn-cta">Learn by Building &rarr;</a>
      <span class="gs-muted-sm">14-lesson tutorial — build a Notes + AI Chat app with htmx, SQLite, and SSE streaming.</span>
    </div>

    <!-- TL;DR install — surfaced above the architecture diagram so visitors
         who just want to try it can copy a single line. Full walkthrough
         (with manual steps, scaffold, first page, deploy) lives below. -->
    <div class="callout info gs-tldr">
      <div class="gs-tldr-head">
        <span class="gs-tldr-bolt">⚡</span>
        <span>TL;DR — install in one line</span>
        <span class="gs-tldr-platforms">Ubuntu / Debian · macOS · WSL2</span>
      </div>
      <div class="qs-block gs-qs-block">
        <div class="qs-line gs-qs-line">
          <span class="qs-prompt gs-qs-prompt">$</span>
          <span class="qs-cmd gs-qs-cmd">curl -fsSL https://php.zeal.ninja/install.sh | sudo bash</span>
          <button class="qs-copy gs-qs-copy" data-copy="curl -fsSL https://php.zeal.ninja/install.sh | sudo bash">copy</button>
        </div>
      </div>
      <p class="gs-tldr-foot">
        Installs PHP 8.3 + OpenSwoole + ext-zealphp + composer. Auto-detects your distro and bails with manual steps if it can't install for you (Fedora, Arch, Alpine, etc.). The detailed walkthrough below covers manual install, Docker, scaffolding, and deploy. <a href="#install" class="gs-tldr-foot-link">Inspect the script first ↓</a>
      </p>
    </div>

    <!-- One Server. Everything. -->
    <div class="arch-compare gs-arch-compare">
      <div class="arch-box complex">
        <h3>Your AI app without ZealPHP</h3>
        <div class="arch-node">Express / FastAPI server</div>
        <div class="arch-node">Redis for session state</div>
        <div class="arch-node">Bull / Celery for background jobs</div>
        <div class="arch-node">Socket.io for WebSocket</div>
        <div class="arch-node">SSE proxy middleware</div>
        <div class="arch-node">Nginx reverse proxy</div>
        <div class="gs-arch-fail">6 services. 6 failure points.</div>
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
        <div class="gs-arch-win">1 process. <code>php app.php</code></div>
      </div>
    </div>
    <p class="compare-verdict">No Redis. No message queue. No sidecar. No microservice fan-out.</p>

    <!-- Step nav -->
    <div class="gs-stepnav">
      <a href="#prereqs" class="gs-step-link">1. Prerequisites</a>
      <a href="#known-risks" class="gs-step-link">2. Known risks</a>
      <a href="#install" class="gs-step-link">3. Install</a>
      <a href="#scaffold" class="gs-step-link">4. Scaffold</a>
      <a href="#first-page" class="gs-step-link">5. First page</a>
      <a href="#first-route" class="gs-step-link">6. Framework routes</a>
      <a href="#deploy" class="gs-step-link">7. Deploy</a>
    </div>

    <h2 id="prereqs" class="gs-h2-mt">1. Prerequisites</h2>
    <table class="ztable">
      <tr><th>Package</th><th>Version</th><th>Why</th></tr>
      <tr><td><code>PHP</code></td><td>8.3+</td><td>Tested on 8.3 and 8.4; OpenSwoole 26.2+ adds PHP 8.5 support</td></tr>
      <tr><td><code>OpenSwoole</code></td><td>22.1+</td><td>Async runtime, HTTP/WebSocket server, coroutines (26.2+ for PHP 8.5)</td></tr>
      <tr><td><code>ext-zealphp</code></td><td>&ge;0.1.0</td><td>Overrides <code>header()</code>, <code>setcookie()</code>, <code>session_*</code> at runtime (or <code>uopz</code> as fallback)</td></tr>
      <tr><td><code>composer</code></td><td>2.x</td><td>Dependency management</td></tr>
      <tr><td><code>uv</code> (optional)</td><td>any</td><td>Only for AI agent examples (Python)</td></tr>
    </table>

    <h2 id="known-risks" class="gs-h2-mt-lg">2. Before you ship: known risks</h2>
    <div class="callout warn">
      <strong>ZealPHP runs as a long-lived process.</strong> This changes the rules from PHP-FPM:
      <ul class="gs-risk-list">
        <li class="gs-risk-item"><strong>Use <code>$g-&gt;get</code> / <code>$g-&gt;session</code> (recommended)</strong> — works in every mode, no extension needed. With ext-zealphp, <code>$_GET</code> / <code>$_SESSION</code> are also per-coroutine safe (saved/restored on every yield/resume). Without ext-zealphp in coroutine mode, PHP superglobals are process-wide and would leak. See the <a href="/coroutines#state-parity">parity rule</a>. Also audit <code>static</code> variables for cross-request leaks.</li>
        <li class="gs-risk-item"><strong>Coroutine safety</strong> — references to <code>RequestContext::instance()</code> (a.k.a. <code>$g</code>) must not be held across <code>yield</code> points; each coroutine has its own context.</li>
        <li class="gs-risk-item"><strong>ext-zealphp function overrides are alpha</strong> — <code>session_start()</code>, <code>header()</code>, etc. are intercepted via <a href="https://github.com/sibidharan/zealphp/tree/master/ext/zealphp" target="_blank" rel="noopener">ext-zealphp</a> (our own extension). Edge cases exist; report them.</li>
        <li class="gs-risk-item"><strong>Memory growth</strong> — workers stay alive between requests; profile for leaks under sustained load.</li>
        <li><strong>API stability</strong> — v0.3.x; breaking changes possible until v1.0. Pin a version in <code>composer.json</code>.</li>
      </ul>
      <p class="gs-callout-mt-sm">Report issues at <a href="https://github.com/sibidharan/zealphp/issues" target="_blank" rel="noopener">GitHub Issues</a>. Security disclosures: see <a href="https://github.com/sibidharan/zealphp/blob/master/SECURITY.md" target="_blank" rel="noopener">SECURITY.md</a>.</p>
    </div>

    <h2 id="install" class="gs-h2-mt-lg">3. Install</h2>

    <div class="callout info gs-mb-1">
      <strong>PHP 8.3, 8.4, or 8.5.</strong> OpenSwoole 22.1+ works on PHP 8.3 and 8.4; OpenSwoole 26.2+ (released Feb 2026) added PHP 8.5 support. If you only have one PHP version available, 8.3 is the safest default.
    </div>

    <p>One-line install on Ubuntu/Debian — pipes <code>setup.sh</code> straight from this site, no clone required:</p>

    <?php App::render('/components/_code', [
      'label' => 'One-line install (Ubuntu/Debian)',
      'lang' => 'bash',
      'code' => <<<'BASH'
curl -fsSL https://php.zeal.ninja/install.sh | sudo bash
# Installs: PHP 8.3, OpenSwoole, ext-zealphp, composer
BASH
    ]); ?>

    <div class="callout info gs-p-mt-sm">
      <strong>Want to inspect before piping to <code>sudo</code>?</strong>
      <br>
      <code class="gs-inline-code-block">curl -fsSL https://php.zeal.ninja/install.sh -o install.sh &amp;&amp; less install.sh &amp;&amp; sudo bash install.sh</code>
      Or fetch from GitHub directly to pin a specific commit:
      <code class="gs-inline-code-block">curl -fsSL https://raw.githubusercontent.com/zealphp/zealphp/master/setup.sh | sudo bash</code>
    </div>

    <p class="gs-mt-15">If you'd rather clone first (e.g. you want to send a PR):</p>

    <?php App::render('/components/_code', [
      'label' => 'From a cloned checkout',
      'lang' => 'bash',
      'code' => <<<'BASH'
git clone https://github.com/sibidharan/zealphp.git
cd zealphp
sudo bash setup.sh
BASH
    ]); ?>

    <p class="gs-mt-15">Or install manually:</p>

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

# 3. ext-zealphp (ZealPHP's own extension — ships with the framework)
git clone --depth 1 https://github.com/sibidharan/zealphp.git /tmp/zealphp-src
cd /tmp/zealphp-src/ext/zealphp && phpize && ./configure && make && sudo make install
echo "extension=zealphp.so" | sudo tee /etc/php/8.3/cli/conf.d/50-zealphp.ini

# 4. Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

# 5. Verify
php -m | grep -E 'openswoole|zealphp'
BASH
    ]); ?>

    <div class="callout info gs-mt-1">
      <strong>Why <code>short_open_tag=On</code>?</strong> ZealPHP templates often use <code>&lt;?= $var ?&gt;</code> for compact output. This is technically a "short echo tag" (always on in PHP 8) but enabling <code>short_open_tag</code> matches the recommended setup for OpenSwoole.
    </div>

    <div class="callout info gs-mt-1">
      <strong>Docker?</strong> The framework repo includes a <code>Dockerfile</code> and <code>docker-compose.yml</code>.
      Run <code>docker compose up</code> from the cloned repo to get a fully configured container.
    </div>

    <h2 id="scaffold" class="gs-h2-mt-lg">4. Scaffold a project</h2>

    <p>Three paths depending on what you're building:</p>

    <div class="gs-scaffold-grid">
      <div class="card gs-scaffold-card">
        <h3 class="gs-card-title">Starter project</h3>
        <p class="gs-card-desc">Clean app tree with examples. Best for new apps.</p>
        <?php App::render('/components/_code', [
          'label' => '',
          'lang' => 'bash',
          'code' => <<<'BASH'
composer create-project \
  zealphp/project:^0.4.8 \
  my-app
cd my-app && php app.php
BASH
        ]); ?>
      </div>
      <div class="card gs-scaffold-card">
        <h3 class="gs-card-title">Framework repo</h3>
        <p class="gs-card-desc">This very site, running locally. Read source + live demos.</p>
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
      <div class="card gs-scaffold-card">
        <h3 class="gs-card-title">WordPress</h3>
        <p class="gs-card-desc">Unmodified WordPress on OpenSwoole.</p>
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
    <div class="gs-howitworks">
      <h3 class="gs-howitworks-h3">How ZealPHP works — the LAMP mental model</h3>
      <p class="gs-howitworks-p">
        In LAMP, <strong>Apache</strong> was the server and PHP ran inside it. In ZealPHP, <strong>OpenSwoole</strong> is the server and PHP runs inside it. Same idea, different engine.
      </p>
      <table class="ztable gs-table-sm">
        <tr><th>LAMP</th><th>ZealPHP</th></tr>
        <tr><td>Apache / Nginx</td><td>OpenSwoole (built into <code>php app.php</code>)</td></tr>
        <tr><td><code>htdocs/about.php</code> → <code>/about.php</code></td><td><code>public/about.php</code> → <code>/about</code></td></tr>
        <tr><td><code>$_GET</code>, <code>$_POST</code>, <code>$_SESSION</code></td><td>Use <code>$g->get</code> / <code>$g->post</code> / <code>$g->session</code> via <code>RequestContext::instance()</code> — works in both modes, no extension needed. With ext-zealphp, <code>$_GET</code>/<code>$_SESSION</code> are also per-coroutine safe in both modes. See <a href="/coroutines#state-parity">the parity rule</a>.</td></tr>
        <tr><td><code>session_start()</code>, <code>header()</code></td><td>Same — overridden via ext-zealphp; populates the per-coroutine <code>$g->session</code> in coroutine mode</td></tr>
        <tr><td>One process per request</td><td>One process, thousands of concurrent coroutines</td></tr>
        <tr><td>Restart Apache after config changes</td><td>Restart <code>php app.php</code> after code changes</td></tr>
        <tr><td>Needs Redis for shared state</td><td>Built-in <code>Store</code> — cross-worker shared memory</td></tr>
        <tr><td>Needs Socket.io / Ratchet for WebSocket</td><td>Built-in <code>App::ws()</code></td></tr>
      </table>
      <p class="gs-howitworks-foot">
        The difference: your PHP process stays alive between requests. That means persistent connections, shared memory, WebSocket, streaming — all without leaving PHP.
      </p>
    </div>

    <h2 id="first-page" class="gs-h2-mt-lg">5. Your first page — just drop a file</h2>

    <p>Create a file in <code>public/</code>. It becomes a route. No framework code needed.</p>

    <?php App::render('/components/_code', [
      'label' => 'public/hello.php — coroutine-safe form (works in both modes)',
      'code' => <<<'PHP'
<?php
use ZealPHP\RequestContext;

$g = RequestContext::instance();
session_start();
$g->session['visits'] = ($g->session['visits'] ?? 0) + 1;
?>
<h1>Hello from ZealPHP</h1>
<p>You've visited this page <?= $g->session['visits'] ?> time(s).</p>
<p>Query string: <?= htmlspecialchars($g->get['name'] ?? 'world') ?></p>
PHP
    ]); ?>

    <div class="callout warn gs-mt-1">
      <strong>Two modes, one rule: <a href="/coroutines#state-parity">always use <code>$g->*</code></a>.</strong>
      The default <code>app.php</code> runs in <strong>coroutine mode</strong> (<code>App::superglobals(false)</code>) — <code>$g-&gt;session</code> / <code>$g-&gt;get</code> / <code>$g-&gt;post</code> are the recommended accessors (per-coroutine, always safe). With ext-zealphp, <code>$_GET</code>/<code>$_SESSION</code> are automatically per-coroutine safe in both modes (saved/restored on every yield/resume).
      <br><br>
      If you're porting a legacy <code>.htaccess</code> + <code>$_*</code> codebase, use one of the lifecycle presets before <code>App::init()</code>: <code>App::mode(App::MODE_LEGACY_CGI)</code> for unmodified WordPress/Drupal (pre-warmed subprocess pool, true per-request isolation), or <code>App::mode(App::MODE_COROUTINE_LEGACY)</code> to run request-style PHP concurrently with per-coroutine isolation of superglobals, <code>$GLOBALS</code>, statics, and <code>require_once</code> re-execution (requires ext-zealphp; <code>define()</code> isolation is a separate opt-in via <code>App::defineIsolation(true)</code>). The raw <code>App::superglobals(true)</code> flag is the underlying knob these presets configure. See <a href="/coroutines#lifecycle-modes">Lifecycle modes</a> and the <a href="/legacy-apps">Legacy apps</a> page for the full migration matrix.
    </div>

    <p class="gs-p-mt-sm">Start the server and visit <code>http://localhost:8080/hello?name=PHP</code>:</p>

    <?php App::render('/components/_code', [
      'label' => '',
      'lang' => 'bash',
      'code' => 'php app.php'
    ]); ?>

    <p class="gs-p-mt-sm">That's it. No <code>$app->route()</code>, no annotations, no config files. Same for APIs — drop a file in <code>api/</code>:</p>

    <?php App::render('/components/_code', [
      'label' => 'api/device/list.php → /api/device/list',
      'code' => <<<'PHP'
<?php
$list = function() {
    return ['devices' => ['sensor-a', 'sensor-b'], 'count' => 2];
};
PHP
    ]); ?>

    <div class="callout info gs-mt-1">
      <strong>This is how you migrate.</strong> Move your existing PHP files into <code>public/</code>. They work immediately. When you need WebSocket, streaming, or coroutines — that's when you use <code>$app->route()</code>. See <a href="/routing">Routing</a> for the full picture.
    </div>

    <h2 id="first-route" class="gs-h2-mt-lg">6. Framework routes — when you need more</h2>

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
    <ul class="gs-visit-list">
      <li><code>http://localhost:8080/api/hello</code> — JSON</li>
      <li><code>http://localhost:8080/user/42</code> — URL param</li>
      <li><code>http://localhost:8080/forbidden</code> — 403</li>
      <li><code>http://localhost:8080/stream</code> — streaming response</li>
    </ul>

    <div class="callout info gs-mt-1">
      <strong>What next?</strong>
      <a href="/routing">Routing</a> · <a href="/responses">Response types</a> · <a href="/coroutines">Coroutines</a> · <a href="/streaming">Streaming</a> · <a href="/ws">WebSocket</a> · <a href="/middleware">Middleware</a> · <a href="/api">File-based REST API</a>
    </div>

    <h2 id="deploy" class="gs-h2-mt-lg">7. Deploy</h2>

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

    <p class="gs-mt-1">Or run standalone (without systemd):</p>

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

    <h2 class="gs-h2-mt-lg">Verification</h2>
    <p>Confirm everything is wired up:</p>
    <?php App::render('/components/_code', [
      'label' => 'Smoke test',
      'lang' => 'bash',
      'code' => <<<'BASH'
# Extensions loaded?
php -m | grep -E 'openswoole|zealphp'

# Server responds?
curl -s http://localhost:8080/ | head -5

# Composer dependencies?
composer show zealphp/zealphp
BASH
    ]); ?>

    <div class="callout warn gs-mt-15">
      <strong>Troubleshooting</strong><br>
      <strong>Port in use?</strong> Run <code>php app.php stop</code> or use <code>-p 9000</code> for a different port.<br>
      <strong>Extension not loaded?</strong> Check <code>php --ini</code> for the config path, ensure <code>extension=openswoole.so</code> is in a loaded <code>.ini</code>.<br>
      <strong>Permission denied on port 80?</strong> Use a port above 1024, or run with <code>setcap</code> / behind a reverse proxy.
    </div>

    <div class="gs-learn-cta-end">
      <a href="/learn" class="btn-cta">Learn by Building &rarr;</a>
      <span class="gs-muted-sm">Everything verified? Build a Notes + AI Chat app over 14 lessons &mdash; htmx, SQLite, and SSE streaming.</span>
    </div>

  </div>
</section>

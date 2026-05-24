<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">Running Legacy PHP Apps</h1>
<p class="section-desc">WordPress compatibility showcase: admin dashboard, login, posts, REST API working through the ZealPHP CGI worker bridge in compatibility mode &mdash; with <a href="#limitations">documented limits</a> and an honest startup-cost trade-off. The bridge exists so traditional PHP code that assumes a fresh process per request (WordPress, Drupal, define()-heavy plugins) can run on OpenSwoole&apos;s long-lived workers. The same <code>app.php</code> works for Drupal, Laravel-on-FPM-shape, and other traditional PHP applications.</p>

<div class="callout info legacy-callout-proof">
  <strong>Production proof point.</strong> Selfmade Ninja Labs (<a href="https://labs.selfmade.ninja">labs.selfmade.ninja</a>) — a large PHP/MongoDB dashboard with OAuth, SSE streaming, and a custom MongoDB ORM — runs the same codebase on both Apache and ZealPHP in production. Two servers, one volume, zero downtime during migration. <a href="/case-studies/sna-labs">Read the case study →</a>
</div>

<div class="legacy-screenshots">
  <div class="legacy-shot">
    <img src="/img/wordpress-home.png" alt="WordPress homepage served by ZealPHP" class="legacy-shot-img">
    <div class="legacy-shot-caption">WordPress front page</div>
  </div>
  <div class="legacy-shot">
    <img src="/img/wordpress-admin.png" alt="WordPress admin dashboard on ZealPHP" class="legacy-shot-img">
    <div class="legacy-shot-caption">Admin dashboard — full menu, widgets, Quick Draft</div>
  </div>
  <div class="legacy-shot">
    <img src="/img/wordpress-posts.png" alt="WordPress posts list on ZealPHP" class="legacy-shot-img">
    <div class="legacy-shot-caption">Posts management — CRUD, bulk actions, filters</div>
  </div>
</div>

<div class="callout info legacy-callout-zero">
<p><strong>Compatibility-mode story.</strong> In the showcase deploy, WordPress runs without source patches: login, sessions, cookies, redirects, file uploads, REST API, and pretty permalinks all work through the CGI worker bridge (<code>App::superglobals(true) + processIsolation(true)</code>). True global-scope isolation is preserved per request via <code>proc_open</code>, at a ~30&ndash;50&nbsp;ms per-request cost &mdash; the price of the isolation that <code>define()</code>-heavy plugins need. The <a href="/vs-fpm">v0.3.0 persistent worker pool</a> is the planned fix for that startup cost. See <a href="#limitations">known limits</a> before betting your production WordPress on it.</p>
</div>

<h2 id="limitations">Known limitations — things ZealPHP won't do</h2>
<p>Before going deep, do the 30-second dealbreaker scan. If your app depends on any of the categories marked ❌ below <em>and</em> you can't put a front proxy in front of ZealPHP, this isn't the right runtime. If you pass this gate, the porting story is clean — keep reading.</p>

<h3 class="legacy-mt-sm">Apache-side features not supported</h3>
<table class="ztable">
<tr><th>Feature</th><th>Why not</th><th>Workaround if any</th></tr>
<tr><td><strong>Server-Side Includes (SSI)</strong> — <code>Options +Includes</code>, <code>XBitHack</code>, <code>.shtml</code> parsing, <code>&lt;!--#include --&gt;</code></td><td>SSI was Apache's pre-PHP templating system. Anyone porting an SSI site is replacing it with PHP anyway.</td><td>Use <code>App::render()</code> / <code>App::include()</code> — that's what they do.</td></tr>
<tr><td><strong>mod_speling</strong> — <code>CheckSpelling</code>, <code>CheckCaseOnly</code>, fuzzy URL matching for typos</td><td>Security-questionable (cache pollution, info disclosure), low-value, low-usage.</td><td>Send a real 404 and let users retype.</td></tr>
<tr><td><strong>mod_imagemap</strong> — server-side <code>&lt;map&gt;</code> files</td><td>Dead since ~1995. Browsers do client-side imagemaps.</td><td>Use HTML <code>&lt;map&gt;</code> / <code>&lt;area&gt;</code> in templates.</td></tr>
<tr><td><strong>mod_dav</strong> — WebDAV (PROPFIND, MKCOL, etc.)</td><td>Different protocol scope; ZealPHP is an HTTP framework, not a file server.</td><td>Use a dedicated WebDAV server (Nextcloud, Apache mod_dav).</td></tr>
<tr><td><strong>mod_perl, mod_python, mod_ruby</strong></td><td>ZealPHP is a PHP framework.</td><td>Run those languages in their own runtimes.</td></tr>
<tr><td><strong>mod_isapi</strong> (Windows IIS extensions)</td><td>Windows-IIS-only API; OpenSwoole is Linux-first.</td><td>N/A — port the underlying logic to PHP.</td></tr>
<tr><td><strong>mod_lua hooks</strong> — <code>LuaHook*</code>, <code>LuaMapHandler</code>, etc.</td><td>Apache's scriptable hook layer. PSR-15 middleware is the native equivalent.</td><td>Write a PSR-15 middleware.</td></tr>
<tr><td><strong>CERN meta files</strong> — <code>MetaDir</code>, <code>MetaFiles</code>, <code>MetaSuffix</code></td><td>Dead since ~1996.</td><td>Use the built-in <a href="/middleware#header"><code>HeaderMiddleware</code></a> to attach response headers.</td></tr>
<tr><td><strong>mod_status, mod_info</strong> (server-info / server-status pages)</td><td>Built-in observability lands in v0.3.</td><td>Roll your own <code>/metrics</code> route in the meantime.</td></tr>
<tr><td><strong>mod_proxy_balancer</strong> (load balancing)</td><td>Out of scope.</td><td>Put HAProxy / Nginx / Caddy in front.</td></tr>
<tr><td><strong>AuthLDAP*</strong> (LDAP authentication)</td><td>Niche in PHP apps; the standard PHP LDAP extension is the integration path.</td><td>Custom middleware using PHP's <code>ldap_*</code> functions.</td></tr>
<tr><td><strong>AuthDigest*</strong> (HTTP Digest Auth)</td><td>Largely replaced by Bearer/Cookie auth over TLS. Browser support is patchy.</td><td>HTTPS + Basic Auth, or token-based auth.</td></tr>
<tr><td><strong>Full mod_autoindex customisation</strong> — <code>AddIcon</code>, <code>AddAlt</code>, <code>IndexStyleSheet</code>, <code>HeaderName</code>, <code>ReadmeName</code></td><td>Basic directory listing is on the roadmap (opt-in only). Apache's icon/description customisation surface is niche and design-heavy.</td><td>Override <code>template/_autoindex.php</code> in your project for custom rendering when basic autoindex ships.</td></tr>
</table>

<h3 class="legacy-mt-sm">nginx-side features not supported (or partial)</h3>
<table class="ztable">
<tr><th>Feature</th><th>Why / Status</th><th>Workaround</th></tr>
<tr><td><strong>Name-based virtual hosts</strong> — multiple <code>server { server_name a.com b.com; }</code> blocks</td><td>⚠ Partial. One ZealPHP instance serves all <code>Host</code> values.</td><td>Host-routing middleware that dispatches on <code>$g-&gt;server['HTTP_HOST']</code>, OR run one ZealPHP instance per host behind Caddy/Traefik.</td></tr>
<tr><td><strong><code>proxy_pass</code> (reverse proxy)</strong></td><td>⚠ Not built-in. ZealPHP is an origin server, not a proxy.</td><td>Put Caddy/Traefik/Nginx in front, OR write a small handler that uses OpenSwoole's HTTP client to forward.</td></tr>
<tr><td><strong><code>X-Accel-Redirect</code> / <code>X-Sendfile</code></strong></td><td>Different model — ZealPHP IS the origin.</td><td>Return <code>$response-&gt;sendFile($protectedPath)</code> directly from the authorised handler (uses kernel sendfile).</td></tr>
<tr><td><strong><code>limit_rate</code></strong> (response bandwidth throttle)</td><td>⚠ Not built-in. <code>limit_req</code> and <code>limit_conn</code> ARE shipped — see <a href="/middleware#rate-limit"><code>RateLimitMiddleware</code></a> + <a href="/middleware#concurrency-limit"><code>ConcurrencyLimitMiddleware</code></a>.</td><td>5-line response wrapper: <code>$response-&gt;write($chunk); OpenSwoole\Coroutine::sleep($delay);</code> between chunks.</td></tr>
<tr><td><strong><code>early_hints</code></strong> (HTTP 103)</td><td>⚠ Not implemented. Niche browser feature.</td><td>Defer; revisit if demand emerges.</td></tr>
<tr><td><strong><code>directio</code></strong> (O_DIRECT)</td><td>⚠ OpenSwoole doesn't expose O_DIRECT.</td><td>Rely on filesystem cache; for huge files use <code>$response-&gt;sendFile()</code>.</td></tr>
<tr><td><strong><code>stream { … }</code> block</strong> (L4 TCP/UDP proxy)</td><td>Different protocol scope.</td><td>Use HAProxy or sniproxy.</td></tr>
<tr><td><strong><code>mail { … }</code> block</strong> (SMTP/IMAP proxy)</td><td>Different protocol scope.</td><td>Postfix / Dovecot.</td></tr>
<tr><td><strong><code>grpc_pass</code></strong> (gRPC proxy)</td><td>Out of scope.</td><td>Envoy or a real gRPC server.</td></tr>
</table>

<h3 class="legacy-mt-sm">ZealPHP internal limitations</h3>
<table class="ztable">
<tr><th>Feature</th><th>Why</th><th>Workaround</th></tr>
<tr><td><code>coprocess()</code> in coroutine mode</td><td>Process-spawning isn't safe inside a coroutine; the API throws <code>Exception</code> (<code>src/utils.php:312</code>). Intentional.</td><td>Use native coroutines (<code>go()</code>) for parallelism in coroutine mode.</td></tr>
<tr><td><code>App::include()</code> Closure return with param injection in subprocess mode (<code>superglobals=true</code>)</td><td>Reflection-driven param injection doesn't survive the process boundary.</td><td>Use coroutine mode for Closure returns, or have the closure invoke itself within the included file.</td></tr>
<tr><td>Multi-port HTTP/HTTPS on a single instance (<code>listen 80; listen 443 ssl;</code>)</td><td>OpenSwoole binds one listening socket per server.</td><td>Run two instances behind a proxy that does TLS termination + HTTP→HTTPS redirect, OR investigate <code>Server::addListener()</code>.</td></tr>
<tr><td>HTTP/3 (QUIC)</td><td>Not yet supported by OpenSwoole.</td><td>Use a front proxy (Caddy supports HTTP/3); ZealPHP serves over HTTP/1.1 or HTTP/2 internally.</td></tr>
</table>

<p class="legacy-mt-prose"><strong>Headline:</strong> the dealbreakers list is short and the items on it are either (a) dead tech nobody uses anymore (SSI, imagemaps, CERN meta files), (b) protocol-scope mismatches that belong to dedicated servers (WebDAV, SMTP, L4 proxy), or (c) features intentionally delegated to a front proxy (multi-host TLS, load balancing, HTTP/3, reverse proxy). For the ~95% of PHP apps that don't depend on these, the migration is clean.</p>

<h2 class="legacy-mt-xl">Migration ergonomics — one-liner Apache parity</h2>
<p>Earlier ZealPHP releases needed a 5-line boot preamble to set <code>$_SERVER</code> globals before serving a legacy file. <code>App::include()</code> owns that preamble now: leading slash optional, Apache-document-root convention (paths are relative to <code>public/</code>), and the framework auto-populates <code>$_SERVER['PHP_SELF']</code> / <code>SCRIPT_NAME</code> / <code>SCRIPT_FILENAME</code> exactly as Apache's mod_php does.</p>

<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => 'Before — manual preamble + absolute paths',
    'code'  => <<<'PHP'
$app->setFallback(function () {
    $g = RequestContext::instance();
    $g->server['PHP_SELF']        = '/index.php';
    $g->server['SCRIPT_NAME']     = '/index.php';
    $g->server['SCRIPT_FILENAME'] =
        App::$cwd . '/public/index.php';
    App::includeFile(
        App::$cwd . '/public/index.php'
    );
});
PHP]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'After — Apache document-root convention',
    'code'  => <<<'PHP'
$app->setFallback(fn() => App::include('/index.php'));

// Paths are relative to public/ (Apache DocumentRoot
// convention) — leading slash optional. The framework
// auto-populates $_SERVER preamble, blocks traversal
// outside public/, and honours the universal return
// contract — see /responses#return-contract.
PHP]); ?>
</div>
</div>

<h2 class="legacy-mt-xl">How It Works</h2>
<p>Three framework features enable legacy app compatibility:</p>

<table class="ztable">
<tr><th>Feature</th><th>What it does</th><th>Apache equivalent</th></tr>
<tr>
  <td><code>App::superglobals(true)</code></td>
  <td><code>$_GET</code>, <code>$_POST</code>, <code>$_SERVER</code>, <code>$_SESSION</code>, <code>$_COOKIE</code> work as expected. See <a href="/coroutines#state-parity">the <code>$g</code> vs <code>$_*</code> parity rule</a> for the cross-mode story.</td>
  <td>mod_php (default behavior)</td>
</tr>
<tr>
  <td><code>App::$ignore_php_ext = false</code></td>
  <td>Allows <code>.php</code> extensions in URLs (<code>/wp-login.php</code>, <code>/admin/edit.php</code>)</td>
  <td><code>AddHandler php-script .php</code></td>
</tr>
<tr>
  <td><code>App::include()</code><br><small>(was <code>App::includeFile()</code> — deprecated alias)</small></td>
  <td>Runs a PHP file from <code>public/</code> through the framework. With <code>processIsolation(true)</code>, dispatches through the <code>cgiMode()</code> backend (default <code>'pool'</code> — warm FPM-style worker pool; see <a href="#pool-buys">below</a> for measured cost); with <code>processIsolation(false)</code>, runs in-process via <code>executeFile()</code>. Either way, the file's return value flows through the <a href="/responses#return-contract">universal return contract</a>.</td>
  <td>mod_prefork MPM + CGI / PHP-FPM</td>
</tr>
<tr>
  <td>Auto-<code>$_SERVER</code> preamble</td>
  <td><code>App::include()</code> populates <code>$_SERVER['PHP_SELF']</code>, <code>SCRIPT_NAME</code>, <code>SCRIPT_FILENAME</code> for the included file before invoking it.</td>
  <td>mod_php's automatic CGI environment</td>
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

<h2 id="wordpress-tested" class="legacy-mt-xl">WordPress — tested end-to-end</h2>

<p>The companion repo <a href="https://github.com/sibidharan/zealphp-wordpress"><code>sibidharan/zealphp-wordpress</code></a> ships an unmodified WordPress 6.7.1 install bound to ZealPHP via a ~30-line <code>app.php</code> (full WP frontend, wp-admin, REST API, plugin system, htaccess-equivalent rewrites). Tested live against this branch in Chrome:</p>

<table class="ztable legacy-mb">
<tr><th>Configuration</th><th>Result</th><th>Notes</th></tr>
<tr>
  <td><strong>cgiMode('proc')</strong> + <code>App::cgiSubprocessAutoload(false)</code><br><small>(the default)</small></td>
  <td>200 + 50,719 B on every request, ~165 ms warm</td>
  <td>5 / 5 requests render the full WP homepage (Blog title, posts, Sample Page link). Matches v0.2.0's behaviour exactly.</td>
</tr>
<tr>
  <td>cgiMode('proc') + <code>App::cgiSubprocessAutoload(true)</code></td>
  <td>First request OK, 2nd+ deadlock</td>
  <td>Loading <code>vendor/autoload.php</code> in every subprocess adds ~30 ms, which makes WordPress's <code>wp_cron()</code> 10 ms non-blocking POST queue up at the parent faster than workers can drain. Only opt in if your <code>public/*.php</code> needs <code>\ZealPHP\App</code> inside the subprocess.</td>
</tr>
<tr>
  <td>cgiMode('pool')</td>
  <td>First request OK (50 KB), 2nd+ return <strong>0 bytes</strong></td>
  <td><strong>Known limitation</strong> — WordPress's <code>$wp_did_header</code> sentinel persists in the warm subprocess; the bootstrap chain's <code>if (!isset($wp_did_header))</code> gates the entire body render. A FPM-style <code>$GLOBALS</code> snapshot/restore in <code>pool_worker.php</code> would fix it; tracked as v0.3.1. For WordPress today, use <code>cgiMode('proc')</code>.</td>
</tr>
</table>

<p><strong>One-line WP config</strong> — drop this in your <code>app.php</code> and unmodified WordPress works:</p>

<?php App::render('/components/_code', [
    'label' => 'app.php — unmodified WordPress on ZealPHP',
    'code'  => <<<'PHP'
<?php
require 'vendor/autoload.php';
use ZealPHP\App;

App::superglobals(true);                  // mod_php-style superglobals
App::cgiMode('proc');                     // fresh PHP per request (v0.2.0 parity)
// App::cgiSubprocessAutoload(false);     // default — DO NOT enable for WP
App::$ignore_php_ext = false;             // allow /wp-login.php in URLs

$app = App::init('0.0.0.0', 9501);

$app->setFallback(function() {            // WP front controller
    App::include('/index.php');
});

$app->run(['task_worker_num' => 0]);
PHP]); ?>

<h3 id="wordpress-autoload-pitfall" class="legacy-mt-md">Why <code>cgiSubprocessAutoload(false)</code> is the default</h3>

<p>Issue #18 (the v0.2.41 WP-on-proc regression vs v0.2.0). Between v0.2.0 and v0.2.20, <code>cgi_worker.php</code> gained a <code>require_once vendor/autoload.php</code> at startup so apps that needed <code>\ZealPHP\App</code> inside the subprocess could use it (issue #17). The autoload load measures <strong>~30 ms</strong> per subprocess spawn on a Ryzen 9 7900X.</p>

<p>For modern apps that don't make 10 ms-timeout self-calls, 30 ms is invisible. But WordPress's <code>wp_cron()</code> fires a non-blocking <code>POST /wp-cron.php</code> with <code>timeout = 0.01</code>. The cgi_worker subprocess takes longer to start than the wp-cron client's timeout window allows — the POST connection arrives at the parent OpenSwoole worker before any worker is free to accept it, accumulating as half-closed sockets. By the 2nd request, the pool is fully blocked.</p>

<p><strong>The fix:</strong> gate the autoload on <code>App::cgiSubprocessAutoload(true)</code> — default off — restoring v0.2.0's zero-overhead subprocess start path. Apps that need ZealPHP classes inside CGI dispatch (rare — most legacy apps ship their own bootstrap) opt in. Pinned by <code>tests/Unit/CgiSubprocessAutoloadTest.php</code> (5 tests including a source-level canary against future regressions).</p>

<h2 id="pool-buys" class="legacy-mt-xl">What <code>cgiMode('pool')</code> buys you — measured</h2>

<p><code>cgiMode('pool')</code> is the default since v0.2.41 — the FPM-style warm worker pool. The numbers below are measured on this machine (Ryzen 9 7900X, OpenSwoole 26.2, PHP 8.3) so you can compare them directly to the bench results elsewhere in the docs.</p>

<table class="ztable legacy-mb">
<tr><th>Path</th><th>Throughput</th><th>Per-request latency</th><th>What we measured</th></tr>
<tr>
  <td><strong>Coroutine mode</strong><br><small>HTTP /json route, no CGI dispatch</small></td>
  <td>13,210 req/s</td>
  <td>0.76 ms (mean)</td>
  <td>Full HTTP stack: middleware + route handler + JSON encode. <code>ab -n 500 -c 10</code> against the live demo server. This is the "modern route" baseline.</td>
</tr>
<tr>
  <td><strong>cgiMode('pool') direct</strong><br><small>4 workers, fixture <code>echo "ok";</code></small></td>
  <td>10,983 req/s</td>
  <td>0.091 ms avg<br>p50 0.028 ms · p99 0.047 ms</td>
  <td><code>scripts/bench-fcgi-pool.php</code> — drives <code>WorkerPool::dispatch()</code> directly with 1000 requests, no HTTP overhead. Measures pure pool round-trip cost (write frame to stdin, subprocess executes, read response).</td>
</tr>
<tr>
  <td><strong>cgiMode('proc')</strong><br><small>fresh <code>proc_open</code> per request</small></td>
  <td>49 req/s</td>
  <td>20.2 ms avg<br>p50 19.4 ms · p99 30.8 ms</td>
  <td><code>scripts/bench-fcgi-proc.php</code> — same fixture as pool bench, single caller, 200 iter. Cold PHP startup dominates the 19.4 ms — each request pays the full interpreter spin-up cost.</td>
</tr>
<tr>
  <td><strong>pool / proc speedup</strong></td>
  <td colspan="3"><strong>~224× faster</strong> (10,983 / 49). This is the v0.2.41 default-flip justification.</td>
</tr>
</table>

<p><strong>Reading the numbers</strong>: the pool itself adds ~90 microseconds of dispatch overhead per request (p50 = 28 μs, p99 = 47 μs). That overhead vanishes inside any non-trivial PHP file — by the time WordPress's autoloader has run, the ~90 μs IPC cost is invisible. The headline difference vs <code>cgiMode('proc')</code> is the <strong>~224× speedup</strong> from skipping the cold PHP startup on every request (proc pays 19.4 ms p50 vs pool's 0.028 ms p50 on the same fixture).</p>

<p>Reproduce locally:</p>

<?php App::render('/components/_code', [
    'label' => 'Measure pool vs proc on your own box',
    'code'  => <<<'BASH'
# Pool — warm FPM-style subprocesses, IPC-only dispatch
php scripts/bench-fcgi-pool.php 1000 4 500

# Proc — fresh proc_open per request, full PHP cold-start
php scripts/bench-fcgi-proc.php 200

# Same fixture (`echo "ok";`) for both → direct comparison.
BASH]); ?>

<p><strong>vs PHP-FPM</strong>: same semantic model (warm subprocess pool, recycled after N requests), so per-request cost should be in the same ballpark as your FPM pool. We haven't run an apples-to-apples bench against a real FPM install on the same box yet — <a href="/vs-fpm">/vs-fpm</a> covers the measurement story. The honest claim is "FPM-equivalent semantics + ZealPHP-managed (one less daemon to install)."</p>

<h3 class="legacy-mt-md">What you actually get from pool mode (beyond the bench numbers)</h3>

<ul>
  <li><strong>True global-scope isolation per request.</strong> Each pool subprocess is a fresh exec'd PHP process (NOT a fork of the HTTP worker), so it doesn't inherit ZealPHP's autoloader, classes, or memory. <code>define()</code>-heavy plugins, classes that re-declare in plugin updates, function tables that grow per request — none of it leaks.</li>
  <li><strong>FPM-style recycling.</strong> <code>cgiPoolMaxRequests(N)</code> (default 500) makes each pool worker exit cleanly after N requests; the parent respawns it. Defense against slow memory leaks in user PHP that would otherwise compound over millions of requests.</li>
  <li><strong>Per-HTTP-worker private pools.</strong> No global IPC bottleneck — each OpenSwoole HTTP worker owns its own <code>cgiPoolSize</code> subprocesses with a <code>Coroutine\Channel</code> queue. Scales with worker count.</li>
  <li><strong>Real <code>$_GET</code> / <code>$_POST</code> / <code>$_SERVER</code> / <code>$_COOKIE</code> inside the subprocess.</strong> Pinned by 5 unit tests in <code>tests/Unit/CGI/CgiPoolDispatchTest.php</code> (testPostSuperglobalReachesSubprocess, etc.).</li>
  <li><strong>Auto-respawn on crash.</strong> <code>proc_get_status</code> polled before each dispatch; dead workers are replaced transparently.</li>
</ul>

<h3 id="hybrid-with-coroutines" class="legacy-mt-md">The hybrid: parent runs coroutines, public/*.php gets isolation</h3>

<p>The mode-table on the <a href="/coroutines#lifecycle-modes">coroutines page</a> documents this as <strong>Mode 6 — Coroutine + Process Isolation</strong>. It's the "best of both worlds" combo you can opt into when you want the modern app to be concurrent AND have occasional legacy isolated PHP:</p>

<?php App::render('/components/_code', [
    'label' => 'Mode 6 — coroutines at the parent, isolated subprocess per public/*.php',
    'code'  => <<<'PHP'
<?php
require 'vendor/autoload.php';
use ZealPHP\App;

// Modern app — per-coroutine $g in the parent worker (no race)
App::superglobals(false);

// public/*.php → CGI pool subprocess (true global-scope isolation per request)
App::processIsolation(true);

// Parent runs coroutines. With HOOK_ALL, the pipe read inside cgiPool
// YIELDS while the subprocess executes — other coroutines run in parallel.
// Both default to null → resolve to !sg → these values. Explicit for clarity:
App::enableCoroutine(true);
App::hookAll(\OpenSwoole\Runtime::HOOK_ALL);

// cgiMode('pool') is the default since v0.2.41. Tune pool size to your needs:
App::cgiPoolSize(8);            // 8 isolated PHP subprocesses per HTTP worker
App::cgiPoolMaxRequests(1000);  // recycle after 1000 requests (FPM parity)

$app = App::init('0.0.0.0', 8080);
$app->run();

// Route handlers in route/*.php run at full coroutine speed.
// public/wp-login.php dispatches to the isolated pool.
// Parent yields on the pipe read → multiple coroutines dispatch in parallel.
PHP]); ?>

<p>The validator at <code>App::run()</code> would refuse <code>superglobals(true) + enableCoroutine(true)</code> (the race shape) — but with <code>sg=false</code>, both throws are unreachable. Mode 6 is fully supported and pinned by <code>tests/Unit/LifecycleModesMatrixTest.php#testMode6CoroutineIsolatedHybrid</code>.</p>

<h2 id="dual-runtime" class="legacy-mt-xl">Dual-runtime — one codebase, Apache AND ZealPHP at once</h2>

<p>The strongest migration story isn't "rewrite for ZealPHP." It's "run the <em>same source tree</em> on both servers simultaneously" — Apache+mod_php for the battle-tested path, ZealPHP for speed and coroutines — and cut over gradually with zero risk. This is exactly how <a href="/case-studies/sna-labs">Selfmade Ninja Labs migrated</a>: one volume, two servers, same files (running on dev today, production cutover next).</p>

<p>The mechanism is a tiny <strong>compat shim</strong> that gives application code a single accessor — <code>$g-&gt;get</code>, <code>$g-&gt;session</code>, etc. — that resolves correctly in whichever runtime is loading the file:</p>

<?php App::render('/components/_code', [
    'label' => 'compat/g.php — shipped with ZealPHP at vendor/sibidharan/zealphp/compat/g.php',
    'code'  => <<<'PHP'
<?php
// Include this ONCE at the top of every entry point — on BOTH servers.
if (!isset($GLOBALS['g'])) {
    if (class_exists('\ZealPHP\RequestContext', false)) {
        // ZealPHP is loaded → use the framework's per-request context.
        // Coroutine mode: per-coroutine, concurrency-safe. The ONLY safe
        // accessor there ($_GET/$_SESSION are intentionally empty).
        $GLOBALS['g'] = \ZealPHP\RequestContext::instance();
    } else {
        // Apache + mod_php → ZealPHP isn't here at all. Build $g from
        // references to PHP's real superglobals so $g->get IS $_GET.
        $GLOBALS['g'] = (object) [
            'get'     => &$_GET,    'post'    => &$_POST,
            'server'  => &$_SERVER, 'cookie'  => &$_COOKIE,
            'files'   => &$_FILES,  'request' => &$_REQUEST,
            'session' => &$_SESSION,
        ];
    }
}
$g = $GLOBALS['g'];
PHP]); ?>

<p>Then application code uses only <code>$g-&gt;X</code> — never <code>$_GET</code>/<code>$_SESSION</code> directly:</p>

<?php App::render('/components/_code', [
    'label' => 'public/dashboard.php — runs unchanged on Apache and ZealPHP',
    'code'  => <<<'PHP'
<?php
require_once __DIR__ . '/../vendor/sibidharan/zealphp/compat/g.php';

session_start();
$g->session['hits'] = ($g->session['hits'] ?? 0) + 1;
$filter = $g->get['filter'] ?? 'all';
echo "Filter: {$filter}, hits: {$g->session['hits']}";
PHP]); ?>

<div class="legacy-shim-note">
  <strong class="legacy-shim-note-title">Why this can't be a framework feature</strong>
  <p class="legacy-shim-note-body">
    The Apache branch of the shim runs <em>precisely when ZealPHP is not loaded</em>. Under Apache+mod_php there's no OpenSwoole, no Composer autoloader bootstrapped, no <code>ZealPHP\</code> namespace — so nothing in the framework's autoloaded <code>src/</code> can execute. The bridge therefore HAS to be a standalone, dependency-free file the app includes unconditionally. ZealPHP ships the canonical copy at <code>compat/g.php</code> (and a test guards it against drift), but it is <em>included by your app</em>, not loaded by the framework. That's not a limitation — it's the only design that can possibly work across the "with ZealPHP / without ZealPHP" boundary.
  </p>
</div>

<table class="ztable">
<tr><th>Runtime</th><th><code>class_exists(RequestContext)</code></th><th><code>$g-&gt;get</code> resolves to</th><th><code>$_GET</code></th></tr>
<tr><td>ZealPHP — coroutine mode</td><td><code>true</code></td><td>per-coroutine <code>RequestContext::$get</code></td><td>empty (by design)</td></tr>
<tr><td>ZealPHP — superglobals mode</td><td><code>true</code></td><td><code>RequestContext::$get</code> → bridged to <code>$_GET</code></td><td>populated (v0.2.27+)</td></tr>
<tr><td>Apache + mod_php</td><td><code>false</code></td><td>shim's <code>&amp;$_GET</code> reference</td><td>populated natively by PHP</td></tr>
</table>

<p class="legacy-mt-prose"><strong>Coroutine-mode dual-runtime apps must use <code>$g-&gt;X</code> and keep the shim permanently</strong> — it's the only accessor that works on both servers (coroutine mode keeps superglobals empty to avoid cross-coroutine races). This is distinct from the <a href="/vs-fpm">drop-in LAMP / Mixed-mode</a> story, where <code>superglobals(true)</code> lets ZealPHP-only apps read <code>$_GET</code>/<code>$_SESSION</code> directly and skip the shim entirely (v0.2.27+).</p>

<h2 class="legacy-mt-xl">Apache rewrite recipes — the 12 patterns</h2>
<p>Real <code>.htaccess</code> files are full of <code>RewriteRule</code> destinations that end in <code>.php</code>. Each recipe below shows the Apache directive and its ZealPHP equivalent. Every example uses the <a href="/coroutines#state-parity"><code>$g</code> form</a> for query-string injection — works in both modes, no per-coroutine leak in coroutine mode. The legacy <code>$_GET</code> equivalent appears as a comment for readers porting older code.</p>

<h3 id="recipe-a" class="legacy-mt-md">Recipe A — Strip <code>.php</code> extension (clean URLs)</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
# URL /about → public/about.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME}\.php -f
RewriteRule ^(.+)$ $1.php [L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php — built-in, zero user code',
    'code'  => <<<'PHP'
// The implicit /{file} route already matches both
// /about and /about.php to public/about.php when
// $ignore_php_ext is true.
App::$ignore_php_ext = true;
PHP]); ?>
</div>
</div>

<h3 id="recipe-b" class="legacy-mt-md">Recipe B — Pretty URL → real <code>.php</code> file (with route param)</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
RewriteRule ^my-page$              /pages/my-page.php             [L]
RewriteRule ^article/([0-9]+)\.html$ /article.php?id=$1           [L,QSA]
RewriteRule ^user/([a-z0-9-]+)$    /user/profile.php?slug=$1      [L,QSA]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php',
    'code'  => <<<'PHP'
use ZealPHP\RequestContext;

$app->route('/my-page',
    fn() => App::include('/pages/my-page.php'));

$app->patternRoute('/article/([0-9]+)\.html', function ($id) {
    $g = RequestContext::instance();
    $g->get['id'] = $id;          // legacy: $_GET['id'] = $id
    return App::include('/article.php');
});

$app->patternRoute('/user/([a-z0-9-]+)', function ($slug) {
    $g = RequestContext::instance();
    $g->get['slug'] = $slug;      // legacy: $_GET['slug'] = $slug
    return App::include('/user/profile.php');
});
PHP]); ?>
</div>
</div>

<h3 id="recipe-c" class="legacy-mt-md">Recipe C — Front controller (WordPress / Drupal / Laravel)</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
# WordPress / Laravel — try real file, else hand to index.php
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]

# Drupal 7 / older CMSes — pass the path as ?q=
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php?q=$1 [L,QSA]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php',
    'code'  => <<<'PHP'
use ZealPHP\RequestContext;

// WordPress / Laravel — fallback handler.
// Implicit router already tried public/{file}.php;
// setFallback() catches everything else.
$app->setFallback(fn() => App::include('/index.php'));

// Drupal — populate $g->get['q'] (legacy: $_GET['q']):
$app->setFallback(function () {
    $g = RequestContext::instance();
    $g->get['q'] = ltrim(parse_url(
        $g->server['REQUEST_URI'], PHP_URL_PATH
    ) ?? '', '/');
    return App::include('/index.php');
});
PHP]); ?>
</div>
</div>

<h3 id="recipe-d" class="legacy-mt-md">Recipe D — API prefix → single front controller</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
# /api/v1/users/42 → /api/index.php
# with REQUEST_URI preserved
RewriteRule ^api/(.*)$ /api/index.php [L,QSA]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php',
    'code'  => <<<'PHP'
use ZealPHP\RequestContext;

$app->nsPathRoute('api', '{path}', function (string $path) {
    $g = RequestContext::instance();
    $g->get['path'] = $path;       // legacy: $_GET['path'] = $path
    return App::include('/api/index.php');
});
PHP]); ?>
</div>
</div>

<h3 id="recipe-e" class="legacy-mt-md">Recipe E — Specific <code>.php</code> file in subdirectory</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
RewriteRule ^admin/?$         /admin/login.php       [L]
RewriteRule ^admin/users$     /admin/users/index.php [L]
RewriteRule ^checkout/done$   /shop/thankyou.php     [L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php',
    'code'  => <<<'PHP'
$app->route('/admin',         fn() => App::include('/admin/login.php'));
$app->route('/admin/users',   fn() => App::include('/admin/users/index.php'));
$app->route('/checkout/done', fn() => App::include('/shop/thankyou.php'));
PHP]); ?>
</div>
</div>

<h3 id="recipe-f" class="legacy-mt-md">Recipe F — Block direct access to internal <code>.php</code> files</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
# WordPress: prevent direct hits on wp-includes/*.php
RewriteRule ^wp-includes/(.+\.php)$ - [F,L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php',
    'code'  => <<<'PHP'
// Refuse with 403 (universal return contract — int = status).
$app->nsPathRoute('wp-includes', '{rest}(\.php)?', fn() => 403);
PHP]); ?>
</div>
</div>

<h3 id="recipe-g" class="legacy-mt-md">Recipe G — HTTPS canonical scheme</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
RewriteCond %{HTTPS} off
RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php — middleware',
    'code'  => <<<'PHP'
use Psr\Http\{Server\MiddlewareInterface,
              Message\ResponseInterface};
use OpenSwoole\Core\Psr\Response;

$app->addMiddleware(new class implements MiddlewareInterface {
    public function process($request, $handler): ResponseInterface {
        if (($request->getServerParams()['HTTPS'] ?? '') !== 'on') {
            $url = 'https://' . $request->getUri()->getHost()
                 . $request->getUri()->getPath();
            return (new Response(''))->withStatus(301)
                ->withHeader('Location', $url);
        }
        return $handler->handle($request);
    }
});
PHP]); ?>
</div>
</div>

<h3 id="recipe-h" class="legacy-mt-md">Recipe H — Canonical host (www vs apex)</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
RewriteCond %{HTTP_HOST} !^www\. [NC]
RewriteRule (.*) https://www.%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php — middleware (same shape as G)',
    'code'  => <<<'PHP'
// Inspect Host header; if missing www., 301 to the www form.
// See Recipe G for the full middleware skeleton.
PHP]); ?>
</div>
</div>

<h3 id="recipe-i" class="legacy-mt-md">Recipe I — Maintenance mode</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
RewriteCond %{REMOTE_ADDR} !^203\.0\.113\.42$
RewriteRule .* /maintenance.html [R=503,L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php — middleware',
    'code'  => <<<'PHP'
// Check $g->server['REMOTE_ADDR']; non-allow-listed IPs get
// a 503 response served via App::include('/maintenance.php').
// Universal return contract handles status + body.
PHP]); ?>
</div>
</div>

<h3 id="recipe-j" class="legacy-mt-md">Recipe J — Custom error pages (Apache <code>ErrorDocument</code>)</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
ErrorDocument 404 /custom-404.php
ErrorDocument 500 /custom-500.php
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php',
    'code'  => <<<'PHP'
$app->setErrorHandler(404, fn() => App::include('/custom-404.php'));

$app->setErrorHandler(500, fn($exception) =>
    App::include('/custom-500.php', ['exception' => $exception]));

// Args passed to App::include() are extracted into the file's
// scope — custom-500.php sees $exception as a local variable.
PHP]); ?>
</div>
</div>

<h3 id="recipe-k" class="legacy-mt-md">Recipe K — SEO redirect (old paths to new)</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
RedirectMatch 301 ^/old-section/(.*)$ /new-section/$1
RewriteRule ^blog/(.*)$ /articles/$1 [R=301,L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php',
    'code'  => <<<'PHP'
$app->patternRoute('/old-section/(.*)',
    fn($rest, $response) => $response->redirect("/new-section/{$rest}", 301));

$app->patternRoute('/blog/(.*)',
    fn($rest, $response) => $response->redirect("/articles/{$rest}", 301));
PHP]); ?>
</div>
</div>

<h3 id="recipe-l" class="legacy-mt-md">Recipe L — Trailing-slash enforcement</h3>
<div class="legacy-two-col">
<div>
<?php App::render('/components/_code', [
    'label' => '.htaccess',
    'lang'  => 'apache',
    'code'  => <<<'APACHE'
RewriteCond %{REQUEST_URI} !(\.[a-zA-Z]+|/)$
RewriteRule (.*) /$1/ [R=301,L]
APACHE]); ?>
</div>
<div>
<?php App::render('/components/_code', [
    'label' => 'app.php — built-in',
    'code'  => <<<'PHP'
// 301 to the trailing-slash form for directories under public/.
App::$directory_slash = true;
PHP]); ?>
</div>
</div>

<h3 class="legacy-mt-lg">Recipe summary</h3>
<table class="ztable">
<tr><th>Pattern</th><th>ZealPHP equivalent</th></tr>
<tr><td>A — Strip <code>.php</code></td><td>Built-in: <code>App::$ignore_php_ext = true</code></td></tr>
<tr><td>B — Pretty URL → <code>.php</code> + param</td><td><code>patternRoute</code> + <code>App::include</code> + <code>$g-&gt;get</code></td></tr>
<tr><td>C — Front controller</td><td><code>setFallback(fn() =&gt; App::include('/index.php'))</code></td></tr>
<tr><td>D — API prefix</td><td><code>nsPathRoute('api', '{path}', ...) + App::include</code></td></tr>
<tr><td>E — Specific file mapping</td><td><code>$app-&gt;route('/url', fn() =&gt; App::include('/file.php'))</code></td></tr>
<tr><td>F — Block direct access</td><td><code>nsPathRoute(...) =&gt; 403</code> (int = status)</td></tr>
<tr><td>G/H — HTTPS / www canonical</td><td>PSR-15 middleware</td></tr>
<tr><td>I — Maintenance mode</td><td>PSR-15 middleware + <code>App::include</code></td></tr>
<tr><td>J — Error pages</td><td><code>setErrorHandler(N, fn() =&gt; App::include('/...'))</code></td></tr>
<tr><td>K — SEO 301</td><td><code>patternRoute</code> + <code>$response-&gt;redirect(..., 301)</code></td></tr>
<tr><td>L — Trailing slash</td><td>Built-in: <code>App::$directory_slash = true</code></td></tr>
</table>

<h2 class="legacy-mt-xl">Real-world full-<code>.htaccess</code> migration — worked example</h2>
<p>A production-style Q&amp;A platform <code>.htaccess</code> with ~30 rewrite rules, headers, charsets, and caching. Each row maps directly to a ZealPHP construct. ✅ = built-in. ⚠ = small custom middleware (on the roadmap). 💡 = PHP-level idiom.</p>

<table class="ztable">
<tr><th>Apache directive</th><th>ZealPHP equivalent</th><th>Support</th></tr>
<tr><td><code>php_value upload_max_filesize 512M</code></td><td><code>ini_set('upload_max_filesize', '512M');</code> in <code>app.php</code> boot, or <code>php.ini</code></td><td>✅</td></tr>
<tr><td><code>ServerSignature Off</code></td><td>No-op — OpenSwoole sends no server-signature footer</td><td>✅</td></tr>
<tr><td><code>Options -Indexes</code></td><td>No-op — ZealPHP never lists directories</td><td>✅</td></tr>
<tr><td><code>AddDefaultCharset utf-8</code></td><td><code>App::addMiddleware(new CharsetMiddleware())</code> (reads <code>App::$default_charset</code>)</td><td>✅</td></tr>
<tr><td><code>AddCharset utf-8 .css .js …</code></td><td>Same <a href="/middleware#charset"><code>CharsetMiddleware</code></a></td><td>✅</td></tr>
<tr><td><code>AddType font/woff2 .woff2</code> (and friends)</td><td><a href="/middleware#mime-type"><code>MimeTypeMiddleware</code></a> for non-static responses; <code>mime_type</code> option on <code>App::run()</code> for static-handler files</td><td>✅</td></tr>
<tr><td><code>Header set Access-Control-Allow-Origin "*"</code></td><td><code>App::addMiddleware(new CorsMiddleware([...]))</code></td><td>✅</td></tr>
<tr><td><code>&lt;FilesMatch ".(css|jpg|…)$"&gt; Header set Cache-Control "max-age=2628000"</code></td><td><a href="/middleware#cache-control"><code>CacheControlMiddleware</code></a> — extension-keyed map</td><td>✅</td></tr>
<tr><td><code>RewriteEngine on</code> / <code>RewriteBase /</code></td><td>N/A — native routing</td><td>✅</td></tr>
<tr><td><code>RewriteRule ^/?qn/([^/]+)?$ "qn.php?id=$1"</code></td><td><code>patternRoute('/qn/([^/]+)?', fn($id) =&gt; { $g-&gt;get['id'] = $id; return App::include('/qn.php'); })</code></td><td>✅ Recipe B</td></tr>
<tr><td><code>RewriteRule ^/?watch/([^/]+)?$ "watch.php?v=$1"</code></td><td>Same Recipe B pattern</td><td>✅</td></tr>
<tr><td><code>RewriteRule ^/?_/([^/]+)/([^/]+)?$ "_data.php?switch=$1&amp;query=$2"</code></td><td><code>patternRoute('/_/([^/]+)/([^/]+)?', fn($switch, $query) =&gt; { $g-&gt;get['switch']=$switch; $g-&gt;get['query']=$query; return App::include('/_data.php'); })</code></td><td>✅</td></tr>
<tr><td><code>RewriteRule ^/?account/([^/]+)?$</code> + <code>^/?account/([^/]+)/([^/]+)?$</code> (overloaded)</td><td>Two <code>patternRoute</code> calls, more specific first</td><td>✅</td></tr>
<tr><td><code>RewriteRule ^/?api/([^/]+)/([^/]+)?$ "api.php?rquest=$2&amp;ns=$1"</code> (swapped captures)</td><td>Capture order in closure params matches regex; assign by name: <code>fn($ns, $rquest) =&gt; { $g-&gt;get['ns']=$ns; ... }</code></td><td>✅</td></tr>
<tr><td><code>RewriteRule ^/?contents/(.+)?$ "contents.php?path=$1"</code> (greedy <code>.+</code>)</td><td><code>patternRoute('/contents/(.+)?', ...)</code></td><td>✅</td></tr>
<tr><td><code>RewriteRule ^/?help/(.+)/$ "http://%{HTTP_HOST}/help/$1" [R=301]</code> (strip trailing slash)</td><td><code>patternRoute('/help/(.+)/', fn($p, $r) =&gt; $r-&gt;redirect("/help/{$p}", 301))</code></td><td>✅</td></tr>
<tr><td><code>RewriteRule ^/?help?$ "http://%{HTTP_HOST}/help/" [R=301]</code></td><td><code>$app-&gt;route('/help', fn($r) =&gt; $r-&gt;redirect('/help/', 301))</code></td><td>✅</td></tr>
<tr><td><code>RewriteRule ^/?help/(.+)?$ "help.php?topic=$1"</code></td><td>Standard Recipe B pattern</td><td>✅</td></tr>
<tr><td><code>RewriteCond %{THE_REQUEST} ^...\.php... HTTP/; RewriteRule ^(.+)\.php$ "..." [R=404]</code> (refuse direct <code>.php</code>)</td><td><a href="/middleware#block-php-ext"><code>BlockPhpExtMiddleware</code></a> — returns <code>404</code> when URI ends in <code>.php</code></td><td>✅</td></tr>
<tr><td><code>RewriteCond %{REQUEST_FILENAME}\.test.php -f; RewriteRule ^([^/.]+)$ $1.test.php</code> (extensionless <code>.test.php</code> resolver)</td><td>Custom route that file-existence-checks then includes</td><td>⚠ Document fall-through semantics</td></tr>
<tr><td><code>RewriteCond %{REQUEST_FILENAME}\.php -f; RewriteRule ^([^/.]+)$ $1.php</code></td><td>Built-in via implicit <code>/{file}</code> route + <code>$ignore_php_ext = true</code></td><td>✅ Recipe A</td></tr>
<tr><td><code>RewriteCond %{REQUEST_FILENAME} !-d; RewriteRule ^([^/]+)/$ "..." [R=301]</code> (strip trailing slash for non-directories)</td><td><code>App::stripTrailingSlash(true)</code> — inverse of <code>App::$directory_slash</code></td><td>✅</td></tr>
<tr><td><code>RewriteCond ... !-f; RewriteCond ... !-d; RewriteRule ^([^/]+)/?$ "profile.php?username=$1"</code></td><td><code>setFallback</code> with one-segment URL check, return <code>404</code> otherwise</td><td>✅ Recipe C generalised</td></tr>
</table>

<p class="legacy-mt-prose"><strong>Coverage:</strong> ~80% fully supported with no new framework work (every <code>patternRoute</code> + <code>App::include</code> case, redirects, CORS, MIME via static handler, extension resolver, front-controller fallback). ~20% need a small middleware addition or 5-line custom inline.</p>

<h2 class="legacy-mt-xl">Apache <code>AllowOverride</code> coverage matrix</h2>
<p>Every directive that can appear in a <code>.htaccess</code> file (sourced from <a href="https://httpd.apache.org/docs/current/mod/overrides.html" target="_blank">Apache's overrides reference</a>), grouped by <code>AllowOverride</code> category. ✅ built-in / ⚠ custom middleware / 💡 PHP-level / ❌ obsolete or unsupported.</p>

<h3 class="legacy-mt-sm"><code>AllowOverride All</code> — request shape, server identity, conditionals</h3>
<table class="ztable">
<tr><th>Apache</th><th>ZealPHP</th></tr>
<tr><td><code>&lt;Files&gt;</code>, <code>&lt;FilesMatch&gt;</code></td><td>✅ Route patterns + middleware conditionals on <code>$g-&gt;server['REQUEST_URI']</code></td></tr>
<tr><td><code>&lt;If&gt;</code>, <code>&lt;ElseIf&gt;</code>, <code>&lt;Else&gt;</code></td><td>✅ Native PHP control flow — Apache <code>ap_expr</code> becomes PHP expressions</td></tr>
<tr><td><code>&lt;IfModule&gt;</code>, <code>&lt;IfDefine&gt;</code>, <code>&lt;IfDirective&gt;</code>, <code>&lt;IfFile&gt;</code>, <code>&lt;IfSection&gt;</code>, <code>&lt;IfVersion&gt;</code></td><td>✅ N/A — PHP <code>if (class_exists / extension_loaded / file_exists / version_compare)</code></td></tr>
<tr><td><code>LimitRequestBody</code></td><td>✅ <code>'package_max_length' =&gt; N</code> in <code>$app-&gt;run()</code></td></tr>
<tr><td><code>LimitXMLRequestBody</code></td><td>💡 <code>libxml_disable_entity_loader</code> + check <code>Content-Length</code></td></tr>
<tr><td><code>LogIOTrackTTFB</code></td><td>💡 Custom logging middleware</td></tr>
<tr><td>Lua hooks (<code>mod_lua</code>)</td><td>✅ N/A — PSR-15 middleware is the equivalent</td></tr>
<tr><td><code>RLimitCPU / MEM / NPROC</code></td><td>💡 PHP-level (<code>set_time_limit</code> / <code>memory_limit</code>) or OS-level (<code>ulimit</code>, systemd <code>LimitNPROC=</code>)</td></tr>
<tr><td><code>ServerSignature</code></td><td>✅ Built-in (always off — no signature footer)</td></tr>
<tr><td>SSI directives (<code>SSIErrorMsg</code>, etc.)</td><td>❌ SSI not supported</td></tr>
</table>

<h3 class="legacy-mt-sm"><code>AllowOverride AuthConfig</code> — authentication and authorisation</h3>
<table class="ztable">
<tr><th>Apache</th><th>ZealPHP</th></tr>
<tr><td><code>AuthType Basic</code> + <code>AuthName</code> + <code>AuthUserFile</code> + <code>Require</code></td><td>✅ <a href="/middleware#basic-auth"><code>BasicAuthMiddleware</code></a> — htpasswd file or callback verifier, same DX as <code>CorsMiddleware</code></td></tr>
<tr><td><code>AuthType Digest</code> + <code>AuthDigest*</code></td><td>⚠ Niche; deferred. Document recommends BasicAuth + HTTPS.</td></tr>
<tr><td><code>AuthLDAP*</code></td><td>❌ Custom middleware via PHP's <code>ldap_*</code> extension</td></tr>
<tr><td><code>Anonymous*</code></td><td>❌ Niche</td></tr>
<tr><td><code>Require valid-user / user X / group Y</code></td><td>✅ <a href="/middleware#basic-auth"><code>BasicAuthMiddleware</code></a> config</td></tr>
<tr><td><code>&lt;Limit&gt;</code>, <code>&lt;LimitExcept&gt;</code></td><td>✅ Route <code>methods</code> array</td></tr>
<tr><td><code>&lt;RequireAll&gt;</code>, <code>&lt;RequireAny&gt;</code>, <code>&lt;RequireNone&gt;</code>, <code>Satisfy</code></td><td>✅ <a href="/middleware#basic-auth"><code>BasicAuthMiddleware</code></a> config (boolean composition)</td></tr>
<tr><td><code>Session*</code> (mod_session)</td><td>✅ N/A — PHP native sessions via ZealPHP's <code>Session</code> family</td></tr>
<tr><td><code>SSL*</code> (cipher suite, SSLRequire, etc.)</td><td>💡 OpenSwoole TLS config at server boot</td></tr>
<tr><td><code>CGIPassAuth</code></td><td>✅ Auth headers already in <code>$g-&gt;server['HTTP_AUTHORIZATION']</code></td></tr>
</table>

<h3 class="legacy-mt-sm"><code>AllowOverride FileInfo</code> — response headers, content negotiation, rewrites, env vars (the big one)</h3>
<table class="ztable">
<tr><th>Apache</th><th>ZealPHP</th></tr>
<tr><td><code>RewriteEngine</code>, <code>RewriteBase</code>, <code>RewriteCond</code>, <code>RewriteRule</code>, <code>RewriteOptions</code></td><td>✅ Native routing — covered exhaustively by Recipes A–L above</td></tr>
<tr><td><code>Redirect</code>, <code>RedirectMatch</code>, <code>RedirectPermanent</code>, <code>RedirectTemp</code></td><td>✅ <code>$response-&gt;redirect($url, $status)</code> — see Recipe K</td></tr>
<tr><td><code>Header set / append / unset / add / merge</code></td><td>✅ <a href="/middleware#header"><code>HeaderMiddleware</code></a> — declarative <code>add() / set() / unset()</code> with conditional variants</td></tr>
<tr><td><code>RequestHeader</code></td><td>✅ <a href="/middleware#header"><code>HeaderMiddleware</code></a>, request-side variant</td></tr>
<tr><td><code>ErrorDocument N /foo.php</code></td><td>✅ <code>$app-&gt;setErrorHandler(N, fn() =&gt; App::include('/foo.php'))</code> — Recipe J</td></tr>
<tr><td><code>AddDefaultCharset</code>, <code>AddCharset</code></td><td>✅ <a href="/middleware#charset"><code>CharsetMiddleware</code></a> — appends <code>; charset=utf-8</code> to text-ish responses; reads <code>App::$default_charset</code></td></tr>
<tr><td><code>AddType X .Y</code> (MIME types)</td><td>✅ <a href="/middleware#mime-type"><code>MimeTypeMiddleware</code></a> for non-static responses; static handler MIME map for files</td></tr>
<tr><td><code>AddEncoding gzip .gz</code></td><td>✅ OpenSwoole <code>http_compression</code></td></tr>
<tr><td><code>AddHandler X .Y</code></td><td>✅ N/A — ZealPHP IS the runtime</td></tr>
<tr><td><code>AddInputFilter</code>, <code>AddOutputFilter</code>, <code>SetOutputFilter</code>, <code>AddOutputFilterByType</code></td><td>✅ Apache filter chains → PSR-15 middleware (compression, ETag, range, headers, etc.)</td></tr>
<tr><td><code>Substitute "s/foo/bar/"</code> (mod_substitute)</td><td>✅ <a href="/middleware#body-rewrite"><code>BodyRewriteMiddleware</code></a> — single-line regex substitution on response body</td></tr>
<tr><td><code>AddLanguage</code>, <code>DefaultLanguage</code>, <code>LanguagePriority</code></td><td>⚠ <code>ContentNegotiationMiddleware</code> if demand emerges</td></tr>
<tr><td><code>BrowserMatch</code>, <code>SetEnvIf</code>, <code>SetEnv</code>, <code>UnsetEnv</code>, <code>PassEnv</code></td><td>💡 PHP-level: read <code>$g-&gt;server['HTTP_USER_AGENT']</code>, set <code>$g-&gt;server['MY_VAR']</code></td></tr>
<tr><td><code>Cookie*</code> (mod_usertrack)</td><td>✅ Built-in: <code>setcookie()</code> override supports all attrs incl. <code>samesite</code></td></tr>
<tr><td><code>FileETag</code></td><td>✅ Built-in via <code>ETagMiddleware</code> (md5 of body)</td></tr>
<tr><td><code>EnableMMAP</code>, <code>EnableSendfile</code></td><td>✅ <code>$response-&gt;sendFile()</code> uses kernel sendfile transparently</td></tr>
<tr><td><code>ForceType X</code></td><td>✅ <a href="/middleware#mime-type"><code>MimeTypeMiddleware</code></a> or one-line <code>$response-&gt;header('Content-Type', $type)</code></td></tr>
<tr><td><code>Action handler /script</code></td><td>✅ N/A — ZealPHP routes are explicit</td></tr>
<tr><td><code>AcceptPathInfo</code></td><td>✅ Native routing handles this; also <code>App::$path_info</code></td></tr>
<tr><td><code>QualifyRedirectURL</code></td><td>⚠ Niche</td></tr>
<tr><td><code>DefaultType</code></td><td>✅ Deprecated by Apache too</td></tr>
<tr><td><code>CGI*</code>, <code>CharsetSourceEnc</code>, <code>CharsetDefault</code>, <code>CharsetOptions</code></td><td>✅ N/A or 💡 PHP-level</td></tr>
<tr><td><code>ScriptInterpreterSource</code></td><td>✅ N/A (Windows-only CGI quirk)</td></tr>
<tr><td><code>ISAPI*</code></td><td>✅ N/A (Windows IIS, irrelevant)</td></tr>
</table>

<h3 class="legacy-mt-sm"><code>AllowOverride Indexes</code> — directory listings, autoindex, expires headers</h3>
<table class="ztable">
<tr><th>Apache</th><th>ZealPHP</th></tr>
<tr><td><code>DirectoryIndex index.php index.html</code></td><td>✅ <code>App::$directory_index = ['index.php', 'index.html']</code></td></tr>
<tr><td><code>DirectorySlash On</code></td><td>✅ <code>App::$directory_slash = true</code></td></tr>
<tr><td><code>FallbackResource</code></td><td>✅ <code>App::setFallback(fn() =&gt; ...)</code></td></tr>
<tr><td><code>DirectoryIndexRedirect</code></td><td>⚠ Trivial via redirect in fallback</td></tr>
<tr><td><code>ExpiresActive</code>, <code>ExpiresByType</code>, <code>ExpiresDefault</code> (mod_expires)</td><td>✅ <a href="/middleware#expires"><code>ExpiresMiddleware</code></a> — <code>Expires:</code> by content type; pairs with <a href="/middleware#cache-control"><code>CacheControlMiddleware</code></a></td></tr>
<tr><td>mod_autoindex full surface (<code>AddIcon</code>, <code>IndexStyleSheet</code>, etc.)</td><td>❌ Not supported (basic autoindex is on the roadmap)</td></tr>
<tr><td><code>ImapBase</code>, <code>ImapDefault</code> (server-side imagemaps)</td><td>❌ Dead tech (~1995)</td></tr>
<tr><td><code>MetaDir</code>, <code>MetaFiles</code>, <code>MetaSuffix</code> (CERN meta files)</td><td>❌ Dead tech</td></tr>
</table>

<h3 class="legacy-mt-sm"><code>AllowOverride Limit</code> — legacy host-based access control</h3>
<table class="ztable">
<tr><th>Apache</th><th>ZealPHP</th></tr>
<tr><td><code>Allow from X</code>, <code>Deny from Y</code>, <code>Order Allow,Deny</code></td><td>✅ <a href="/middleware#ip-access"><code>IpAccessMiddleware</code></a> — CIDR allow/deny lists with allow-first or deny-first ordering</td></tr>
<tr><td><code>&lt;Limit METHOD&gt;</code>, <code>&lt;LimitExcept METHOD&gt;</code></td><td>✅ Route <code>methods</code> array</td></tr>
</table>

<h3 class="legacy-mt-sm"><code>AllowOverride Options</code> — feature toggles and filter chains</h3>
<table class="ztable">
<tr><th>Apache</th><th>ZealPHP</th></tr>
<tr><td><code>Options Indexes</code></td><td>❌ Not supported (basic autoindex on roadmap)</td></tr>
<tr><td><code>Options FollowSymLinks</code>, <code>SymLinksIfOwnerMatch</code></td><td>💡 PHP-level: <code>realpath()</code> follows symlinks; <code>App::includeCheck()</code> is the safety gate</td></tr>
<tr><td><code>Options ExecCGI</code></td><td>✅ N/A — no CGI handlers outside legacy-app mode</td></tr>
<tr><td><code>Options Includes</code> / <code>IncludesNoExec</code> / <code>XBitHack</code> (SSI)</td><td>❌ SSI not supported</td></tr>
<tr><td><code>Options MultiViews</code></td><td>⚠ Custom middleware if needed; uncommon</td></tr>
<tr><td><code>ContentDigest</code></td><td>⚠ Trivial custom middleware</td></tr>
<tr><td><code>CheckSpelling</code>, <code>CheckCaseOnly</code> (mod_speling)</td><td>❌ Not supported</td></tr>
<tr><td><code>FilterChain</code>, <code>FilterDeclare</code> (mod_filter)</td><td>⚠ Map to PSR-15 middleware</td></tr>
<tr><td><code>SSLOptions</code></td><td>💡 OpenSwoole TLS config</td></tr>
</table>

<h3 class="legacy-mt-sm">Headline coverage</h3>
<table class="ztable">
<tr><th>Category</th><th>ZealPHP coverage</th></tr>
<tr><td>Rewrites &amp; redirects (<code>mod_rewrite</code>, <code>mod_alias</code>)</td><td><strong>100% — native routing</strong></td></tr>
<tr><td>Directory &amp; front-controller (<code>mod_dir</code>)</td><td><strong>100% — built-in</strong></td></tr>
<tr><td>HTTP method limits (<code>&lt;Limit&gt;</code>)</td><td><strong>100% — route <code>methods</code> array</strong></td></tr>
<tr><td>MIME, charset, encoding</td><td><strong>✅ gzip ✅ <code>CharsetMiddleware</code> ✅ <code>MimeTypeMiddleware</code></strong></td></tr>
<tr><td>Headers &amp; cookies</td><td><strong>✅ Cookie + <code>HeaderMiddleware</code> (declarative response-header manipulation)</strong></td></tr>
<tr><td>Cache &amp; expires</td><td><strong>✅ <code>ExpiresMiddleware</code> + <code>CacheControlMiddleware</code> (extension-based static-asset caching)</strong></td></tr>
<tr><td>ETag</td><td><strong>✅ built-in</strong></td></tr>
<tr><td>Compression</td><td><strong>✅ OpenSwoole <code>http_compression</code></strong></td></tr>
<tr><td>Error documents</td><td><strong>✅ <code>App::setErrorHandler()</code></strong></td></tr>
<tr><td>Range / conditional requests</td><td><strong>✅ <code>RangeMiddleware</code> + <code>ETagMiddleware</code></strong></td></tr>
<tr><td>HTTP Basic Auth</td><td><strong>✅ <code>BasicAuthMiddleware</code> (htpasswd file or callback verifier)</strong></td></tr>
<tr><td>LDAP / Digest auth</td><td><strong>❌ niche — PHP extension integration documented</strong></td></tr>
<tr><td>Env vars per request</td><td><strong>💡 PHP-level inline</strong></td></tr>
<tr><td>IP allow/deny</td><td><strong>✅ <code>IpAccessMiddleware</code> (CIDR allow/deny lists)</strong></td></tr>
<tr><td>SSI</td><td><strong>❌ not supported — use templates</strong></td></tr>
<tr><td>Autoindex</td><td><strong>❌ basic listing on roadmap; full Apache customisation surface not planned</strong></td></tr>
<tr><td>Body rewrite (<code>mod_substitute</code>)</td><td><strong>✅ <code>BodyRewriteMiddleware</code> (single-line regex substitution; multi-line variants on roadmap)</strong></td></tr>
<tr><td>Content negotiation</td><td><strong>⚠ custom middleware on demand</strong></td></tr>
<tr><td>Server identity</td><td><strong>✅ no-op / ⚠ trivial</strong></td></tr>
<tr><td>Dead tech (imap, speling, CERN meta, ISAPI, mod_dav, XBitHack)</td><td><strong>❌ N/A — not goals</strong></td></tr>
</table>

<p class="legacy-mt-prose"><strong>Verdict:</strong> ZealPHP covers the practical 80–90% of <code>.htaccess</code> capability that real PHP apps actually use, has clear middleware-extension paths for another 10%, and explicitly disclaims the dead-tech ~5%.</p>

<h2 class="legacy-mt-xl">nginx coverage matrix</h2>
<p>For users porting from <code>nginx.conf</code>. Sourced from <a href="https://nginx.org/en/docs/http/ngx_http_core_module.html" target="_blank">ngx_http_core_module</a> + <a href="https://nginx.org/en/docs/http/ngx_http_rewrite_module.html" target="_blank">ngx_http_rewrite_module</a>.</p>

<h3 class="legacy-mt-sm">Virtual host &amp; listen</h3>
<table class="ztable">
<tr><th>nginx</th><th>ZealPHP / OpenSwoole</th></tr>
<tr><td><code>server { … }</code></td><td>✅ One <code>App::init(host, port)</code> instance per server block. Multi-app deployments run multiple instances on different ports (PID-file-per-port already supports this)</td></tr>
<tr><td><code>listen 80;</code> / <code>listen 443 ssl http2;</code></td><td>✅ <code>App::init('0.0.0.0', 80)</code>; for TLS pass <code>ssl_cert_file</code> / <code>ssl_key_file</code> / <code>enable_http2</code> in <code>$app-&gt;run()</code> settings</td></tr>
<tr><td><code>server_name a.com b.com;</code> (name-based vhosts)</td><td>✅ <a href="/middleware#host-router"><code>HostRouterMiddleware</code></a> — dispatches on <code>$g-&gt;server['HTTP_HOST']</code> to per-host handlers; OR run one instance per host behind Caddy/Traefik for true isolation</td></tr>
</table>

<h3 class="legacy-mt-sm">Routing — the <code>location</code> family</h3>
<table class="ztable">
<tr><th>nginx</th><th>ZealPHP</th></tr>
<tr><td><code>location /prefix/ { … }</code></td><td>✅ <code>$app-&gt;route('/prefix/...', ...)</code> or <code>nsPathRoute('prefix', ...)</code></td></tr>
<tr><td><code>location = /exact { … }</code></td><td>✅ <code>$app-&gt;route('/exact', ...)</code></td></tr>
<tr><td><code>location ~ \.php$ { … }</code> (regex)</td><td>✅ <code>patternRoute('.*\.php$', ...)</code></td></tr>
<tr><td><code>location ~* \.(css|js)$ { … }</code> (case-insensitive)</td><td>⚠ ZealPHP patterns are case-sensitive; wrap regex with <code>(?i)</code> flag</td></tr>
<tr><td><code>location ^~ /static/ { … }</code> (prefix wins over regex)</td><td>✅ Route registration order determines priority</td></tr>
<tr><td><code>location @named { … }</code> (named locations)</td><td>✅ <code>App::setErrorHandler(N, fn() =&gt; App::include('/fallback.php'))</code></td></tr>
<tr><td><code>root /var/www/html;</code></td><td>✅ Built-in — <code>public/</code> is the document root by convention</td></tr>
<tr><td><code>alias /var/some/other/path;</code></td><td>✅ Custom route + <code>App::include()</code> with the aliased path</td></tr>
<tr><td><code>index index.php index.html;</code></td><td>✅ <code>App::$directory_index = ['index.php', 'index.html']</code></td></tr>
<tr><td><code>try_files $uri $uri/ /index.php?$args;</code></td><td>✅ Recipe C — implicit router tries <code>public/{file}.php</code>, then <code>setFallback</code> catches the rest</td></tr>
<tr><td><code>error_page 404 /custom-404.html;</code></td><td>✅ <code>App::setErrorHandler(404, ...)</code> — Recipe J</td></tr>
<tr><td><code>X-Accel-Redirect</code></td><td>⚠ ZealPHP IS the origin; the offload pattern collapses to <code>$response-&gt;sendFile()</code> after auth</td></tr>
</table>

<h3 class="legacy-mt-sm">Rewrite module</h3>
<table class="ztable">
<tr><th>nginx</th><th>ZealPHP</th></tr>
<tr><td><code>rewrite ^/old$ /new last;</code></td><td>✅ Route <code>/old</code> + <code>App::include('/new.php')</code>, or use <code>patternRoute</code></td></tr>
<tr><td><code>rewrite ^/old$ /new redirect;</code> (302)</td><td>✅ <code>return $response-&gt;redirect('/new', 302);</code></td></tr>
<tr><td><code>rewrite ^/old$ /new permanent;</code> (301)</td><td>✅ <code>return $response-&gt;redirect('/new', 301);</code></td></tr>
<tr><td><code>return 301 https://$host$request_uri;</code></td><td>✅ Universal HTTPS middleware (Recipe G) or inline <code>$response-&gt;redirect(...)</code></td></tr>
<tr><td><code>return 200 "OK\n";</code> (inline body)</td><td>✅ <code>return "OK\n";</code> (<a href="/responses#return-contract">return contract</a>)</td></tr>
<tr><td><code>if ($http_user_agent ~ MSIE) { … }</code></td><td>✅ <code>if (preg_match('/MSIE/', $g-&gt;server['HTTP_USER_AGENT']))</code></td></tr>
<tr><td><code>if (-f $request_filename) { … }</code></td><td>✅ <code>if (file_exists(App::$cwd . '/public/' . $path))</code></td></tr>
<tr><td><code>set $foo bar;</code></td><td>✅ Plain PHP variable</td></tr>
</table>

<h3 class="legacy-mt-sm">Body, headers, transmission, keep-alive, types, cache, logs, rate limits, auth, proxy, TLS</h3>
<table class="ztable">
<tr><th>nginx</th><th>ZealPHP / OpenSwoole</th></tr>
<tr><td><code>client_max_body_size 100m;</code></td><td>✅ <code>'package_max_length' =&gt; 100 * 1024 * 1024</code></td></tr>
<tr><td><code>sendfile on;</code> / <code>tcp_nopush</code> / <code>tcp_nodelay</code></td><td>✅ Built-in via <code>$response-&gt;sendFile()</code> and OpenSwoole socket options</td></tr>
<tr><td><code>keepalive_timeout 75s;</code></td><td>✅ OpenSwoole <code>'keepalive_timeout' =&gt; N</code></td></tr>
<tr><td><code>types { … }</code> / <code>default_type</code></td><td>⚠ OpenSwoole <code>static_handler_locations</code> MIME map</td></tr>
<tr><td><code>open_file_cache</code></td><td>✅ OpenSwoole built-in static-file caching</td></tr>
<tr><td><code>disable_symlinks on;</code></td><td>✅ <code>App::includeCheck()</code> rejects paths outside <code>public/</code></td></tr>
<tr><td><code>access_log</code> / <code>error_log</code></td><td>✅ Built-in: <code>access_log()</code>, <code>elog()</code>, <code>zlog()</code> (configurable via <code>ZEALPHP_*</code> env vars)</td></tr>
<tr><td><code>log_format custom "…";</code></td><td>✅ <code>App::$access_log_format</code> — Apache <code>%h %l %u %t "%r" %&gt;s %b "%{Referer}i" "%{User-Agent}i" %D</code> tokens supported</td></tr>
<tr><td><code>limit_rate</code> / <code>limit_req</code> / <code>limit_conn</code></td><td>✅ <a href="/middleware#rate-limit"><code>RateLimitMiddleware</code></a> (sliding window in <code>Store</code>) + <a href="/middleware#concurrency-limit"><code>ConcurrencyLimitMiddleware</code></a> (in-flight cap via <code>Counter</code>); <code>limit_rate</code> bandwidth throttle is a 5-line response wrapper</td></tr>
<tr><td><code>limit_except GET POST { deny all; }</code></td><td>✅ Route <code>methods</code> array</td></tr>
<tr><td><code>auth_basic</code> + <code>auth_basic_user_file</code></td><td>✅ <a href="/middleware#basic-auth"><code>BasicAuthMiddleware</code></a></td></tr>
<tr><td><code>proxy_pass http://backend;</code></td><td>⚠ Not built-in. Use Caddy/Traefik/Nginx in front, OR a handler using OpenSwoole's HTTP client</td></tr>
<tr><td><code>fastcgi_pass unix:/run/php-fpm.sock;</code></td><td>✅ N/A — ZealPHP IS the PHP runtime</td></tr>
<tr><td><code>ssl_certificate</code>, <code>ssl_certificate_key</code>, <code>ssl_protocols</code>, <code>ssl_ciphers</code></td><td>✅ OpenSwoole <code>'ssl_*'</code> settings</td></tr>
<tr><td><code>if_modified_since</code> / <code>etag on;</code></td><td>✅ <code>ETagMiddleware</code> handles <code>If-None-Match</code></td></tr>
<tr><td><code>expires 30d;</code></td><td>✅ <a href="/middleware#expires"><code>ExpiresMiddleware</code></a> + <a href="/middleware#cache-control"><code>CacheControlMiddleware</code></a></td></tr>
<tr><td><code>client_max_body_size</code> / <code>large_client_header_buffers</code> / <code>max_headers</code></td><td>✅ <code>App::$limit_request_fields</code> + <code>$limit_request_field_size</code> + <code>$limit_request_line</code> (Apache <code>LimitRequestFields</code> family). <code>client_max_body_size</code> is <code>'package_max_length'</code> in <code>$app-&gt;run()</code>.</td></tr>
<tr><td><code>merge_slashes on;</code></td><td>💡 Middleware normalising <code>$g-&gt;server['REQUEST_URI']</code></td></tr>
<tr><td><code>server_tokens off;</code></td><td>✅ No Server header sent by default</td></tr>
<tr><td><code>chunked_transfer_encoding on;</code></td><td>✅ OpenSwoole handles chunked encoding for streaming responses</td></tr>
<tr><td><code>gzip on;</code> / <code>gzip_types</code></td><td>✅ OpenSwoole <code>http_compression</code></td></tr>
</table>

<p class="legacy-mt-prose"><strong>Headline:</strong> nginx-as-front-controller patterns (<code>try_files</code>, <code>location</code>, <code>rewrite</code>, <code>return</code>, <code>error_page</code>) port 1:1 to ZealPHP's native routing — same way <code>.htaccess</code> rewrites do. The "I serve PHP via FastCGI" half of nginx configs is N/A. The proxy/upstream/load-balancing half is intentionally delegated to a real front proxy.</p>

<h2 class="legacy-mt-xl">Rewrite rules — internal vs. external</h2>
<p>
  Apache <code>RewriteRule</code> has two flavors that get conflated all the time. The flag in
  brackets decides whether the URL bar in the user's browser changes.
</p>
<ul>
  <li><strong>No <code>[R]</code> flag = internal rewrite.</strong> Apache serves the destination's
    file but the URL bar still shows the original URL. <em>No Location header sent.</em> The user
    never sees the internal path. Most SEO-safe, used for friendly URLs over a front controller.</li>
  <li><strong><code>[R=301]</code> or <code>[R=302]</code> = external redirect.</strong> Apache
    sends a Location header; browser does a fresh request to the destination; URL bar changes.
    Used to permanently move a page or for vanity short-links.</li>
</ul>
<p>
  These map to two <em>different</em> ZealPHP patterns. <strong>Don't use <code>header('Location: …')</code>
  for a non-<code>[R]</code> rewrite</strong> — that would expose the internal URL the original
  rule was hiding.
</p>

<h2 class="legacy-mt-xl">Custom error pages for legacy apps</h2>
<p>Mirror <code>.htaccess</code>'s <code>ErrorDocument 404 /custom-404.php</code> with <code>App::setErrorHandler()</code> — see Recipe J above:</p>

<?php App::render('/components/_code', [
    'label' => 'Apache ErrorDocument equivalent',
    'code'  => <<<'PHP'
$app->setErrorHandler(404, function ($status) {
    // Hand 404s to WordPress so it can render its own theme template.
    return App::include('/wp/index.php');
});

$app->setErrorHandler(500, function ($exception) {
    // Send a JSON envelope to API clients, HTML to browsers.
    $g = ZealPHP\RequestContext::instance();
    if (str_contains($g->server['HTTP_ACCEPT'] ?? '', 'application/json')) {
        return ['error' => 'Internal Server Error', 'trace_id' => uniqid()];
    }
    return App::renderToString('error/500', ['exception' => $exception]);
});
PHP]); ?>

<p class="legacy-mt-half">Handlers receive <code>$status</code>, <code>$exception</code>, <code>$request</code>, <code>$response</code> by param injection — same machinery as regular routes. Returns follow the <a href="/responses#return-contract">universal return contract</a>. See <a href="/responses">Responses</a> for details and the <a href="https://github.com/sibidharan/zealphp/blob/master/docs/error-handling.md"><code>docs/error-handling.md</code></a> deep dive.</p>

<h2 class="legacy-mt-xl">AI Config Converter</h2>
<p>Paste your <code>.htaccess</code> or nginx config — get a working <code>app.php</code> streamed in real-time. The converter knows about the 12 recipes above, the <a href="#limitations">known limitations matrix</a>, the universal return contract, and the <code>$g</code>-vs-<code>$_*</code> parity rule — so it emits modern <code>App::include()</code> form, refuses unsupported directives explicitly (rather than silently dropping them), and uses <code>$g-&gt;get['x']</code> over <code>$_GET['x']</code>. Powered by gpt-5.4-mini with the full ZealPHP API reference.</p>

<div class="converter-split">
  <div class="legacy-convert-left">
    <div class="legacy-convert-bar">
      <span>Apache / nginx config</span>
      <select id="convert-preset" class="legacy-convert-preset">
        <option value="">— paste your own —</option>
        <option value="wordpress">WordPress .htaccess</option>
        <option value="nginx-cms">nginx CMS</option>
        <option value="redirects">Redirect rules</option>
      </select>
    </div>
    <textarea id="convert-input" class="legacy-convert-input" placeholder="Paste your .htaccess or nginx server { } config here..."></textarea>
    <div class="legacy-convert-actions">
      <button id="convert-btn" onclick="runConvert()" class="legacy-convert-btn">Convert →</button>
      <span id="convert-status" class="legacy-convert-status"></span>
    </div>
  </div>
  <div>
    <div class="legacy-convert-bar">
      <span>ZealPHP app.php</span>
      <button onclick="copyOutput()" class="legacy-convert-copy">Copy</button>
    </div>
    <pre id="convert-output" class="legacy-convert-output"><span class="legacy-convert-placeholder">// Output will appear here...</span></pre>
    <div class="legacy-convert-footer">
      Rate limit: 5 conversions per 10 minutes · Powered by gpt-5.4-mini · <a href="https://github.com/sibidharan/zealphp/blob/master/examples/agents/config_converter.py" target="_blank">Source</a>
    </div>
  </div>
</div>

<?php App::render('/components/_code', [
    'label' => 'CLI usage (also available as a command-line tool)',
    'code'  => <<<'BASH'
# Pipe any config — get app.php on stdout
cat .htaccess | uv run examples/agents/config_converter.py

# Interactive mode
uv run examples/agents/config_converter.py
BASH, 'lang' => 'bash']); ?>

<h2 class="legacy-mt-xl">WordPress example</h2>
<p>A complete <code>app.php</code> that runs WordPress on ZealPHP:</p>

<?php App::render('/components/_code', [
    'label' => 'app.php — WordPress on ZealPHP',
    'code'  => <<<'PHP'
<?php
require 'vendor/autoload.php';
use ZealPHP\App;
use ZealPHP\RequestContext;

App::superglobals(true);
App::$ignore_php_ext = false;

$app = App::init('0.0.0.0', 9501);

// Redirect /wp-admin to /wp-admin/index.php
$app->route('/wp-admin', function ($response) {
    $g = RequestContext::instance();
    $qs = !empty($g->server['QUERY_STRING'])
        ? '?' . $g->server['QUERY_STRING'] : '';
    return $response->redirect('/wp-admin/index.php' . $qs, 301);
});

// Fallback: unmatched URLs → WordPress front controller
// Replaces Apache's: RewriteRule . /index.php [L]
$app->setFallback(fn() => App::include('/index.php'));

$app->run(['task_worker_num' => 0]);
PHP]); ?>

<h2 class="legacy-mt-xl">Setup Steps</h2>
<p>See the full working example: <a href="https://github.com/sibidharan/zealphp-wordpress" target="_blank">github.com/sibidharan/zealphp-wordpress</a></p>
<ol class="legacy-list-steps">
  <li>Create a ZealPHP project: <code>composer create-project sibidharan/zealphp-project my-wordpress</code></li>
  <li>Download WordPress into <code>public/</code>: <code>cd my-wordpress/public &amp;&amp; wp core download</code></li>
  <li>Configure <code>public/wp-config.php</code> with your database settings</li>
  <li>Write <code>app.php</code> as shown above</li>
  <li>Start: <code>php app.php</code> (or <code>php app.php start -p 9501 -d</code> to daemonize)</li>
  <li>Visit <code>http://localhost:9501/wp-admin/install.php</code> to complete installation</li>
</ol>

<h2 class="legacy-mt-xl">CGI Worker Architecture</h2>
<p><code>App::include()</code> dispatches to two different paths depending on the mode:</p>
<ul>
  <li><strong>Coroutine mode</strong> (<code>App::superglobals(false)</code>) — runs the file in-process via the shared <code>App::executeFile()</code> core. Captures output, applies the <a href="/responses#return-contract">universal return contract</a>.</li>
  <li><strong>Superglobals mode</strong> (<code>App::superglobals(true)</code>) — dispatches to <code>src/cgi_worker.php</code> via <code>proc_open</code>. Each request gets a clean PHP interpreter with true global scope — exactly like Apache's prefork MPM. The subprocess captures the file's return value over the stderr metadata channel and threads it back through the same contract.</li>
</ul>

<?php App::render('/components/_code', [
    'label' => 'How App::include() works (superglobals mode)',
    'code'  => <<<'TEXT'
OpenSwoole Worker (long-lived)          CGI Worker (per-request)
┌─────────────────────────┐             ┌──────────────────────────┐
│                         │  proc_open  │  php cgi_worker.php      │
│  Route matched          │ ──────────► │                          │
│  App::include('/x.php') │             │  TRUE global scope:      │
│                         │   stdin     │  ├─ $_SERVER, $_GET, etc.│
│  Serializes context:    │ ──────────► │  ├─ $_COOKIE, $_FILES    │
│  ├─ $_SERVER, $_GET     │  (POST body)│  │                       │
│  ├─ $_POST, $_COOKIE    │             │  ├─ uopz captures:       │
│  └─ Request body        │             │  │  header(), setcookie()│
│                         │   stdout    │  │  http_response_code() │
│  Reads response:        │ ◄────────── │  │                       │
│  ├─ Body from stdout    │             │  ├─ include file.php     │
│  ├─ Metadata from stderr│   stderr    │  │  ← app runs at global │
│  │  (status, headers,   │ ◄────────── │  │    scope              │
│  │   cookies, return    │             │  │                       │
│  │   value as JSON)     │             │  └─ Process exits (clean)│
│  └─ Applies to response │             │                          │
└─────────────────────────┘             └──────────────────────────┘
TEXT]); ?>

<h3 class="legacy-mt-sm">What the CGI worker handles</h3>
<table class="ztable">
<tr><th>Feature</th><th>How</th></tr>
<tr><td>All HTTP methods</td><td><code>$_SERVER['REQUEST_METHOD']</code> passed via context; request body piped to stdin (<code>php://input</code>)</td></tr>
<tr><td><code>header()</code> / <code>header_remove()</code></td><td>Captured via <code>uopz_set_return</code> — sent back as JSON metadata</td></tr>
<tr><td><code>setcookie()</code> / <code>setrawcookie()</code></td><td>Captured — applied to response by parent worker</td></tr>
<tr><td><code>http_response_code()</code> / <code>headers_list()</code></td><td>Captured — status and headers returned in metadata</td></tr>
<tr><td>File return value</td><td>Serialised over stderr metadata; threaded through the <a href="/responses#return-contract">universal return contract</a></td></tr>
<tr><td><code>exit()</code> / <code>die()</code></td><td><code>register_shutdown_function</code> flushes output and metadata</td></tr>
<tr><td>SSE streaming</td><td>Detects <code>text/event-stream</code>; streams via <code>flush()</code> like Apache</td></tr>
<tr><td>Static files</td><td>Served directly by OpenSwoole — never reaches PHP</td></tr>
<tr><td>File uploads / Sessions</td><td><code>$_FILES</code> via context; PHP native sessions work in CGI process</td></tr>
</table>

<h2 class="legacy-mt-xl">CLI Management</h2>

<?php App::render('/components/_code', [
    'label' => 'CLI commands',
    'code'  => <<<'BASH'
php app.php                     # Start with defaults
php app.php start -p 9501       # Start on port 9501
php app.php start -p 9501 -d    # Start daemonized
php app.php stop                # Stop the server (reads PID file)
php app.php status              # Check if server is running
php app.php start -w 8          # Start with 8 workers
php app.php --help              # Show all options
BASH, 'lang' => 'bash']); ?>

<h2 class="legacy-mt-xl">Performance &amp; Hybrid Mode</h2>
<div class="callout warn">
<p><strong>Performance:</strong> In superglobals mode, each <code>App::include()</code> spawns a CGI subprocess. Static files bypass this (served by OpenSwoole). For high-traffic production, convert hot paths to native ZealPHP routes that run in coroutine mode.</p>
<p><strong>Streaming:</strong> SSE works in CGI mode via <code>flush()</code>. WebSocket requires native ZealPHP routes (<code>App::ws()</code>).</p>
<p><strong>Hybrid approach:</strong> Mix native routes (coroutine mode, high performance) with legacy PHP file serving (CGI mode) in the same app. Explicit <code>$app-&gt;route()</code> handlers run directly in the worker.</p>
</div>

<h2 id="cgi-backends" class="legacy-mt-xl">CGI backends — host any language</h2>
<p>ZealPHP can serve files written in <strong>any language</strong> that speaks CGI/1.1 — Perl, Python, Ruby, shell scripts, or compiled binaries — side-by-side with your PHP app. Register per-extension backends with <code>App::registerCgiBackend()</code> before <code>$app-&gt;run()</code>.</p>
<div class="callout legacy-mt-prose-mb">
  <p><strong>Works in every lifecycle mode.</strong> CGI dispatch is no longer gated on process-isolation. A registered non-<code>.php</code> extension is dispatched through its backend in <strong>coroutine mode too</strong> — the <code>proc</code> path uses a coroutine-aware <code>proc_open</code> / <code>Coroutine\System::exec()</code> that yields to the scheduler instead of blocking the worker, supports a POST body on the interpreter's stdin, and can stream. The interpreter's RFC 3875 CGI response (headers + blank line + body, with a <code>Status:</code> pseudo-header) is read off stdout via <code>cgiInterpreterResponse()</code> — Apache <code>mod_cgi</code> parity. The <code>.php</code> fast path is unchanged.</p>
</div>

<h3 class="legacy-mt-sm">Apache / nginx parity table</h3>
<table class="ztable">
<tr><th>Mode</th><th>Apache equivalent</th><th>nginx equivalent</th><th>ZealPHP</th></tr>
<tr>
  <td><code>'proc'</code></td>
  <td><code>AddHandler cgi-script .pl</code> + <code>Options +ExecCGI</code></td>
  <td>—</td>
  <td><code>App::registerCgiBackend('.pl', ['mode'=&gt;'proc', 'interpreter'=&gt;'/usr/bin/perl'])</code></td>
</tr>
<tr>
  <td><code>'proc'</code> (shebang)</td>
  <td><code>AddHandler cgi-script .cgi</code> — relies on <code>#!</code> line</td>
  <td>—</td>
  <td><code>App::registerCgiBackend('.cgi', ['mode'=&gt;'proc'])</code></td>
</tr>
<tr>
  <td><code>'fcgi'</code></td>
  <td><code>ProxyPassMatch ^/(.+\.py)$ fcgi://127.0.0.1:9001/…</code></td>
  <td><code>location ~ \.py$ { fastcgi_pass 127.0.0.1:9001; }</code></td>
  <td><code>App::registerCgiBackend('.py', ['mode'=&gt;'fcgi', 'address'=&gt;'127.0.0.1:9001'])</code></td>
</tr>
<tr>
  <td><code>'pool'</code></td>
  <td>— (no direct equivalent; built-in warm subprocess pool is ZealPHP-specific)</td>
  <td>—</td>
  <td><code>App::cgiMode('pool')</code> — default; <strong>.php only</strong></td>
</tr>
</table>

<h3 class="legacy-mt-sm">Worked examples</h3>

<?php App::render('/components/_code', [
    'label' => '.php — default (no registration needed)',
    'code'  => <<<'PHP'
// .php falls through to App::$cgi_mode (default 'proc').
// No registration needed — this is the existing behaviour.
App::processIsolation(true); // enables CGI mode
$app->setFallback(fn() => App::include('/index.php'));
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => '.pl — Perl via proc with interpreter',
    'code'  => <<<'PHP'
App::registerCgiBackend('.pl', [
    'mode'        => 'proc',
    'interpreter' => '/usr/bin/perl',
]);
// public/info.pl is now executed via /usr/bin/perl
// with the same RFC 3875 CGI env ZealPHP builds for PHP scripts.
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => '.py — Python FastCGI (warm pool, language-agnostic)',
    'code'  => <<<'PHP'
App::registerCgiBackend('.py', [
    'mode'        => 'fcgi',
    'address'     => '127.0.0.1:9001',       // or 'unix:/run/python-fpm.sock'
    'fcgi_params' => ['APP_ENV' => 'prod'],   // merged into CGI env (nginx fastcgi_param parity)
]);
// Requests to /hello.py are proxied to the FastCGI server — no process spawn per request.
// Same machinery as the framework-wide App::cgiMode('fcgi') — see #cgi-mode-fcgi below.
PHP]); ?>

<?php App::render('/components/_code', [
    'label' => '.cgi — direct shebang execution, scoped to /cgi-bin',
    'code'  => <<<'PHP'
App::registerCgiBackend('.cgi', [
    'mode'       => 'proc',
    'exec_paths' => ['/cgi-bin'],   // ExecCGI scope — only execute under /cgi-bin/*
]);
// ZealPHP calls proc_open(['path/to/script.cgi']) — the OS reads the #! line.
// Script must output CGI/1.1 headers: Content-Type + blank line + body.
// A .cgi requested OUTSIDE /cgi-bin (e.g. an uploaded /uploads/x.cgi) → 403,
// never executed and never served as source.
PHP]); ?>

<h3 id="exec-cgi-scope" class="legacy-mt-sm"><code>exec_paths</code> — the ExecCGI scope (default-off)</h3>
<p>By default a registered extension does <strong>not</strong> execute via an implicit URL. <code>exec_paths</code> opts specific URL path prefixes into execution — ZealPHP's parity for Apache's <code>Options +ExecCGI</code> being off by default. A request whose extension is registered but whose URL falls <strong>outside</strong> every <code>exec_paths</code> prefix is treated as a stray/uploaded script: it is <strong>neither executed nor served as source</strong>, returning <strong>403 Forbidden</strong>. This closes the classic "upload a <code>.py</code> into the docroot and have it execute" hole. Files outside the scope remain reachable via <code>App::include()</code> (which applies its own docroot-containment check).</p>

<h3 id="implicit-url-parity" class="legacy-mt-sm">Implicit URL parity</h3>
<p>Implicit routes are registered <strong>per registered extension</strong>, so <code>GET /cgi-bin/report.py</code> runs <code>public/cgi-bin/report.py</code> through the <code>.py</code> backend with no explicit <code>$app-&gt;route()</code> — the same shape as Apache serving a script out of a <code>cgi-bin</code> directory.</p>

<h3 id="script-alias" class="legacy-mt-sm"><code>cgiScriptAlias()</code> — Apache <code>ScriptAlias</code> parity</h3>
<?php App::render('/components/_code', [
    'label' => 'Mark a whole URL prefix executable, any extension',
    'code'  => <<<'PHP'
App::cgiScriptAlias('/cgi-bin', ['mode' => 'proc', 'interpreter' => '/usr/bin/python3']);
// Any file served under /cgi-bin is executable regardless of its extension.
// Takes the same mode / interpreter / address / fcgi_params config as registerCgiBackend().
PHP]); ?>
<div class="callout warn legacy-mt-prose-mb">
  <p><strong>Known limitation.</strong> <code>cgiScriptAlias()</code> registers the resolution + ExecCGI scope, but URL-level implicit routing is wired <strong>per-extension only</strong>. A ScriptAlias-only setup (no matching <code>registerCgiBackend()</code>) is reachable via <code>App::include()</code> but does not yet get an automatic <code>/{file}.&lt;ext&gt;</code> route. Pair it with a per-extension backend whose <code>exec_paths</code> covers the same prefix for auto-routed implicit URLs, or add an explicit route. (Follow-up.)</p>
</div>

<h3 class="legacy-mt-sm">pool mode — PHP only constraint</h3>
<div class="callout warn legacy-mt-prose-mb">
  <p><strong>Why pool is PHP-only.</strong> <code>'pool'</code> pre-spawns PHP subprocesses that inherit the ZealPHP boot environment and reset global scope between requests. This warm-subprocess mechanism is specific to the PHP runtime. For other languages, use <code>'fcgi'</code> (warm pool managed by the language runtime) or <code>'proc'</code> (spawn on demand).</p>
  <p>Attempting <code>App::registerCgiBackend('.py', ['mode' =&gt; 'pool'])</code> throws <code>\InvalidArgumentException</code> with the message: <em>"pool mode requires a PHP target; use 'fcgi' (warm pool, language-agnostic) or 'proc' for .py"</em>.</p>
</div>

<h3 class="legacy-mt-sm">Reader: App::resolveCgiBackend()</h3>
<p><code>App::resolveCgiBackend(string $absPath, string $urlPath = ''): array</code> resolves the backend config <strong>and</strong> the ExecCGI permission for a path. It returns <code>['backend' =&gt; [...], 'mayExecute' =&gt; bool]</code>. Resolution order: <code>cgiScriptAlias()</code> prefixes first (always executable), then the per-extension registry gated by <code>exec_paths</code>, then an unregistered fallback (<code>['mode' =&gt; App::$cgi_mode]</code>, <code>mayExecute = false</code>). When <code>mayExecute</code> is <code>false</code> the dispatcher returns 403 rather than executing or leaking source.</p>

<?php App::render('/components/_code', [
    'label' => 'Inspect backend resolution + ExecCGI gate',
    'code'  => <<<'PHP'
App::registerCgiBackend('.py', [
    'mode'       => 'fcgi',
    'address'    => '127.0.0.1:9001',
    'exec_paths' => ['/cgi-bin'],
]);

$r = App::resolveCgiBackend('/var/www/app/public/cgi-bin/hello.py', '/cgi-bin/hello.py');
// ['backend' => ['mode' => 'fcgi', 'address' => '127.0.0.1:9001', ...], 'mayExecute' => true]

$r = App::resolveCgiBackend('/var/www/app/public/uploads/hello.py', '/uploads/hello.py');
// ['backend' => [...], 'mayExecute' => false]  ← outside ExecCGI scope → 403

$r = App::resolveCgiBackend('/var/www/app/public/index.php', '/index.php');
// ['backend' => ['mode' => 'proc'], 'mayExecute' => false]  ← unregistered, App::$cgi_mode
PHP]); ?>

<p>See <code>examples/multi-lang-cgi/</code> for a runnable demo registering <code>.pl</code> (proc/Perl) alongside the default PHP backend.</p>

<h2 id="cgi-mode-fcgi" class="legacy-mt-xl">Framework-wide <code>cgiMode('fcgi')</code> — front an upstream FPM pool</h2>

<p>The per-extension <code>'fcgi'</code> backend above is for mixing languages. The framework-wide setter <code>App::cgiMode('fcgi')</code> applies the same FastCGI-forwarding behaviour to <strong>every</strong> <code>public/*.php</code> file — turning ZealPHP into a thin HTTP layer in front of an existing php-fpm pool. Same wire protocol as Apache's <code>mod_proxy_fcgi</code> and nginx's <code>fastcgi_pass</code>, so the FastCGI listener you point at can be php-fpm, HHVM, RoadRunner, or any other FCGI 1.0 backend.</p>

<?php App::render('/components/_code', [
    'label' => 'Boot — all .php forwarded to 127.0.0.1:9000 (php-fpm default)',
    'code'  => <<<'PHP'
use ZealPHP\App;

App::superglobals(true);          // CGI dispatch path (proc / fork / fcgi)
App::processIsolation(true);
App::cgiMode('fcgi');             // 'pool' (default) | 'proc' | 'fcgi'
App::fcgiAddress('127.0.0.1:9000');  // or 'unix:/run/php/php-fpm.sock'

App::init('0.0.0.0', 8080);
$app = new App();
$app->run();
PHP]); ?>

<p>When to reach for this:</p>
<ul class="legacy-list-loose">
  <li>You already operate a tuned php-fpm pool (sized for your workload, hooked into your observability stack) and don't want to retire it — ZealPHP adds OpenSwoole's HTTP / WebSocket / coroutine layer on top.</li>
  <li>You want the v0.3.0 "warm pool" semantics today by letting php-fpm be that pool — the FPM master keeps interpreters warm across requests, so per-request cost is closer to FPM's ~1–3 ms than the bridge's ~30–50 ms.</li>
  <li>You're migrating from an <code>nginx → fastcgi_pass</code> deployment and want a drop-in shape change rather than a code rewrite. The <code>fcgi_params</code> array on <code>App::registerCgiBackend()</code> mirrors nginx's <code>fastcgi_param</code> directive.</li>
</ul>

<p><strong>Under the hood:</strong> dispatch lives in <code>App::cgiFcgi(string $path, ?string $address = null, array $extraParams = [])</code>, which builds the CGI/1.1 environment via <code>buildCgiEnv()</code>, forwards via <code>ZealPHP\CGI\FastCgiClient::request($params, $stdinBody)</code>, and applies the upstream's status code + headers to <code>$g-&gt;zealphp_response</code>. A failed connection or <code>FastCgiException</code> from the upstream surfaces as a clean <strong>502 Bad Gateway</strong> — same shape Apache and nginx emit when their FCGI upstream is down.</p>

<p><strong>Performance:</strong> we don't run PHP at all in this mode — throughput equals whatever your FPM pool delivers minus one local socket hop. We deliberately don't quote a number here: it depends on your FPM <code>pm.max_children</code>, the file under load, and whether you're on Unix sockets vs TCP. The bridge-cost table at <a href="/vs-fpm#measured-four-ways">/vs-fpm</a> compares the in-process modes (<code>'pool'</code> / <code>'proc'</code> / Mixed-mode) and intentionally omits <code>'fcgi'</code> because the answer is "ask your FPM pool."</p>

<h2 id="httpoxy-hardening" class="legacy-mt-xl">What the CGI bridge does for you (security)</h2>

<p>All three dispatch modes (<code>'pool'</code> / <code>'proc'</code> / <code>'fcgi'</code>) build the CGI/1.1 environment through the same <code>App::buildCgiEnv()</code> path, so the hardening below applies uniformly. Each item ships a corresponding Apache parity rationale rather than being ZealPHP-specific behaviour.</p>

<ul class="legacy-list-loose">
  <li><strong>httpoxy CVE-2016-5385 mitigation</strong> — incoming <code>Proxy:</code> request headers are NOT forwarded as <code>HTTP_PROXY</code> in the CGI env (<code>src/App.php:2830-2836</code>). Apache <code>util_script.c:224-227</code> parity. Prevents the well-known PHP/Go/Python CGI library family that reads <code>HTTP_PROXY</code> to choose an outbound proxy from being hijacked by a hostile client.</li>
  <li><strong>CGI script timeout — <code>App::$cgi_timeout</code> default 60 s</strong> (<code>src/App.php:273</code>). When a CGI subprocess exceeds the budget the worker escalates <code>proc_terminate(SIGTERM)</code> → <code>SIGKILL</code> and returns control to the request handler. Apache <code>CGIScriptTimeout</code> parity. Override by setting the public static property: <code>App::$cgi_timeout = 120;</code> before <code>App::init()</code>.</li>
  <li><strong>CGI <code>Status:</code> header parsed from stdout</strong> (<code>src/cgi_worker.php:101-113</code>) — a legacy script that emits <code>Status: 404 Not Found\r\n</code> sets the response status to 404 and the <code>Status:</code> header itself is NOT forwarded to the client. Range-clamped 100–599; non-numeric or out-of-range values fall back to 200. mod_cgi parity.</li>
  <li><strong>stderr drained to <code>elog</code></strong> — anything the subprocess writes to fd 2 (PHP warnings, custom debug, uncaught notices) is routed to <code>/tmp/zealphp/debug.log</code> via the <code>cgi_worker</code> log channel, never leaked into the response body. Prevents the classic "PHP warning rendered into the HTML page" disclosure path.</li>
</ul>

<p>None of these are opt-in — they're always active on the CGI dispatch path, in every lifecycle mode (the path is no longer gated on <code>processIsolation(true)</code>). There is no flag to disable the <code>HTTP_PROXY</code> strip, the timeout has a floor of 1 s rather than an unbounded option, and stderr always lands in <code>elog</code>.</p>

<h2 id="coroutine-safe-exec" class="legacy-mt-xl">Coroutine-safe <code>exec</code></h2>
<p>Shelling out (<code>git</code>, <code>ffmpeg</code>, <code>convert</code>, …) with <code>exec()</code> / <code>shell_exec()</code> / <code>system()</code> / <code>passthru()</code> or a backtick blocks the OpenSwoole worker — one slow command stalls every coroutine sharing it. ZealPHP ships a coroutine-aware wrapper plus a transparent override so legacy code gets the safe behaviour for free.</p>
<table class="ztable">
<tr><th>API</th><th>What it does</th></tr>
<tr><td><code>App::exec(string $cmd, ?float $timeout = null): array</code></td><td>Coroutine-safe execution. Inside a coroutine, yields via <code>OpenSwoole\Coroutine\System::exec()</code>; outside one (boot / CLI) falls back to blocking <code>App::rawExec()</code>. Returns <code>['output' =&gt; string, 'code' =&gt; int, 'signal' =&gt; int]</code> either way.</td></tr>
<tr><td><code>App::rawExec(string $cmd): ?string</code></td><td>Explicit blocking escape hatch — returns captured stdout (or <code>null</code> if the process failed to start). Built on <code>proc_open</code> deliberately (NOT <code>shell_exec</code>/<code>exec</code>/<code>system</code>/<code>passthru</code>/<code>popen</code>), so it stays recursion-safe even with the override on.</td></tr>
<tr><td><code>App::hookExec(?bool)</code> / <code>App::$hook_exec</code></td><td>Toggles the transparent override. <code>null</code> (default) resolves to <strong>on in coroutine mode</strong> (<code>superglobals === false</code>); a non-null value forces it on/off. When on, <code>shell_exec</code>, <code>exec</code>, <code>system</code>, <code>passthru</code>, and the backtick operator all route through <code>App::exec()</code> via <code>uopz</code> — same override family as <code>header()</code> and <code>session_*()</code>. <code>proc_open</code> / <code>popen</code> are intentionally NOT overridden.</td></tr>
</table>
<p>New ZealPHP-native code should still prefer explicit <code>App::exec()</code>, but the override means unmodified legacy code that shells out stops blocking the worker automatically.</p>

<h2 class="legacy-mt-xl">Performance &amp; Hybrid Mode (continued)</h2>
<p>For the full performance picture including CGI backends, see the <a href="/performance">performance page</a>.</p>

</div>
</section>

<?php use ZealPHP\App; ?>

<section class="section" style="background:var(--bg-dark);color:var(--code-text)">
  <div class="container" style="max-width:860px">
    <p style="font-size:.85rem;color:#a8a29e;text-transform:uppercase;letter-spacing:.1em;margin-bottom:.5rem;font-weight:600">Case study · Production migration</p>
    <h1 class="section-title" style="font-size:2.1rem;margin-bottom:.5rem;color:#fff;letter-spacing:-.01em">From Apache to ZealPHP: How We Made the Same PHP Codebase Run on Two Servers Simultaneously</h1>
    <p class="section-desc" style="font-size:1.05rem;max-width:780px;color:var(--text-light);font-style:italic">
      41 commits. 806 files changed. One custom Rust extension. Zero downtime.
    </p>
  </div>
</section>

<section class="section">
<div class="container" style="max-width:860px;line-height:1.75;font-size:1rem">

<p>We just finished migrating the Selfmade Ninja Labs dashboard — a large PHP/MongoDB/jQuery web application — from traditional Apache+mod_php to ZealPHP. But we didn't replace Apache. We made both run <strong>side by side, in the same container, serving the same files</strong>. Here's the full story of what it took, what broke, what we built, and what we learned.</p>

<h2 style="margin:2rem 0 .75rem">What we started with</h2>
<p>The Selfmade Ninja Labs platform (<code>labs.selfmade.ninja</code>) is a Docker-based educational infrastructure. The web dashboard is a PHP application served by Apache with mod_php — the most traditional PHP stack there is. It handles user auth (OAuth), lab management, a code arena, quizzes, clan systems, roadmaps, discussion forums, and an internal economy system called Zeal/Jolt.</p>
<p>The codebase is large: hundreds of PHP files, MongoDB models via a custom ORM (MongoGetterSetter), a REST API layer, Server-Sent Events endpoints for real-time streaming, and a Grunt-based frontend build. It was never designed to run on anything other than Apache.</p>

<h2 style="margin:2rem 0 .75rem">What ZealPHP is (and why we wanted it)</h2>
<p><a href="https://github.com/sibidharan/zealphp">ZealPHP</a> is an async PHP web framework built on OpenSwoole. Instead of Apache spawning a new PHP process for every request, ZealPHP keeps a long-running PHP process with an event loop — like Node.js, but for PHP.</p>
<p>The benefits are significant:</p>
<ul style="margin:.5rem 0 1rem;padding-left:1.4rem">
  <li><strong>Persistent connections</strong> — MongoDB, Redis, and RabbitMQ connections stay open across requests instead of being recreated every time</li>
  <li><strong>Coroutines</strong> — concurrent I/O without callback hell (PHP Fibers + OpenSwoole)</li>
  <li><strong>No CGI overhead</strong> — no forking, no process boot cost per request</li>
  <li><strong>Built-in SSE/streaming</strong> — native support instead of Apache's flush-and-pray approach</li>
  <li><strong>Event loop cron</strong> — schedule recurring tasks inside the server process via <code>App::tick()</code>, no external cron daemon needed</li>
</ul>
<p>But migration from a superglobal-based, process-per-request PHP codebase to a shared-process async runtime is not trivial. It is, in fact, one of the hardest migrations in the PHP world.</p>

<h2 style="margin:2rem 0 .75rem">The architecture: one container, two servers, same volume</h2>
<p>Here's what we ended up with:</p>

<?php App::render('/components/_code', [
  'label' => 'Architecture diagram',
  'lang'  => 'text',
  'code'  => <<<'TXT'
                 Internet
                    │
                ┌───┴───┐
                │Traefik│  :443 (HTTPS + TLS)
                └───┬───┘
                    │
    ┌───────────────┼───────────────┐
    │                               │
labsdev.selfmade.ninja          zealphp.selfmade.ninja
labs.selfmade.ninja              php.zeal.ninja
    │                               │
    ▼                               ▼
Apache :80                     ZealPHP :8080
(mod_php, CGI model)          (OpenSwoole 26, async)
    │                               │
    └───────── same volume ─────────┘
         /var/www/labs-dashboard-web
TXT,
]); ?>

<p>Both servers live inside the same Docker container (<code>labs</code>). Apache listens on port 80, ZealPHP on port 8080. Traefik routes based on hostname. They mount the same code volume. Change a PHP file and both servers see it instantly (Apache because it re-reads on every request, ZealPHP because it was configured for development reload).</p>
<p>The entire infrastructure change in <code>labs-devops</code> was remarkably small — <strong>5 files, 56 insertions</strong>:</p>
<ol style="margin:.5rem 0 1rem;padding-left:1.4rem">
  <li><strong>Dockerfile</strong> — Ubuntu 24.04 → 25.04 (PHP 8.4), added OpenSwoole 26 + uopz, Rust toolchain for building zealphp-mongodb</li>
  <li><strong>entry.sh</strong> — 8 lines: "if <code>server.php</code> exists, start ZealPHP as a daemon on port 8080"</li>
  <li><strong>php-override.ini</strong> — Swapped <code>extension=mongodb.so</code> → <code>extension=zealphp_mongodb.so</code></li>
  <li><strong>docker-compose.override.prod.yml</strong> — Added Traefik router for <code>zealphp.selfmade.ninja</code> → port 8080</li>
  <li><strong>docker-compose.yml</strong> — Exposed port 8080</li>
</ol>
<p>That's the devops side. The application side is where the real work happened.</p>

<h2 id="phase-1-routes" style="margin:2rem 0 .75rem">Phase 1: Route translation (the easy part)</h2>
<p>The first commit was <code>c39e6429a feat(zealphp): add ZealPHP server with full .htaccess route translation</code>.</p>
<p>Apache uses <code>.htaccess</code> with <code>mod_rewrite</code> for clean URLs:</p>
<?php App::render('/components/_code', [
  'label' => '.htaccess — Apache rewrites',
  'lang'  => 'apache',
  'code'  => <<<'APACHE'
RewriteRule ^quiz/(.*)$ quiz.php?path=$1 [QSA,L]
RewriteRule ^labs/(.*)$ labs.php?path=$1 [QSA,L]
RewriteRule ^profile/([^/]+)/?$ profile.php?username=$1 [QSA,L]
APACHE,
]); ?>
<p>ZealPHP uses explicit route registration in <code>server.php</code>:</p>
<?php App::render('/components/_code', [
  'label' => 'server.php — equivalent ZealPHP routes',
  'code'  => <<<'PHP'
$app->route('/quiz/{path:.*}',     fn() => page('quiz.php',     ['path']));
$app->route('/labs/{path:.*}',     fn() => page('labs.php',     ['path']));
$app->route('/profile/{username}', fn() => page('profile.php',  ['username']));
PHP,
]); ?>
<p>Every rewrite rule was translated. This was mechanical work — tedious but straightforward. The <code>page()</code> helper function loads the PHP file in the ZealPHP context, passing route parameters as if they were query string arguments.</p>

<h2 id="phase-2-superglobals" style="margin:2rem 0 .75rem">Phase 2: The superglobals problem (the hard part)</h2>
<p>This is where the migration gets interesting.</p>
<p>In Apache's process-per-request model, PHP superglobals (<code>$_SESSION</code>, <code>$_SERVER</code>, <code>$_GET</code>, <code>$_POST</code>) are naturally isolated. Each request gets its own process with its own copy of these variables. When the request ends, the process dies and everything is garbage collected.</p>
<p>In ZealPHP's shared-process model, <strong>all requests share the same PHP process</strong>. If request A writes to <code>$_SESSION['username'] = 'alice'</code> and request B reads <code>$_SESSION['username']</code>, they'd get each other's data. This is a catastrophic data leak.</p>
<p>The solution: the <code>$g</code> context bridge.</p>

<h3 style="margin:1.5rem 0 .5rem">The <code>$g</code> bridge pattern</h3>
<p>In <code>load.php</code> — the file included by every page and API endpoint — we added one critical line:</p>
<?php App::render('/components/_code', [
  'label' => 'load.php — one line that makes Apache parity work',
  'code'  => <<<'PHP'
$GLOBALS['g'] = $g = class_exists('\ZealPHP\RequestContext', false)
    ? \ZealPHP\RequestContext::instance()
    : (object)[
        "get"     => &$_GET,
        "post"    => &$_POST,
        "server"  => &$_SERVER,
        "files"   => &$_FILES,
        "request" => &$_REQUEST,
        "cookie"  => &$_COOKIE,
        "session" => &$_SESSION
      ];
PHP,
]); ?>
<p>This single line is the foundation of Apache parity. When running under Apache, <code>$g->get</code> is literally a reference to <code>$_GET</code> — zero overhead, identical behavior. When running under ZealPHP, <code>$g->get</code> is a per-coroutine property from <code>RequestContext::instance()</code> — isolated, safe, and scoped to the current request.</p>
<p>Application code that previously did <code>$_GET['id']</code> now does <code>$g->get['id']</code>. It works identically in both environments.</p>
<p>Similarly, <code>$_SESSION</code> access was wrapped in a <code>Session</code> class:</p>
<?php App::render('/components/_code', [
  'label' => 'Session class — same API, two runtime backings',
  'code'  => <<<'PHP'
Session::get('username')      // maps to $GLOBALS['g']->session['username']
Session::set('username', $v)  // maps to $GLOBALS['g']->session['username'] = $v
Session::isset('username')    // maps to isset($GLOBALS['g']->session['username'])
PHP,
]); ?>
<p><strong>Is <code>$g</code> an anti-pattern?</strong> No. It's a pragmatic bridge pattern. The alternatives were:</p>
<ol style="margin:.5rem 0 1rem;padding-left:1.4rem">
  <li><strong>Rewrite every file to use <code>$request</code>/<code>$response</code> objects</strong> — thousands of changes, all at once, no incremental path</li>
  <li><strong>Use ZealPHP's superglobals mode</strong> — emulates <code>$_GET</code>/<code>$_POST</code> but breaks down under concurrent requests</li>
  <li><strong>The <code>$g</code> bridge</strong> — one change per access site, works in both modes, incrementally adoptable</li>
</ol>
<p>The <code>$g</code> pattern let us migrate file by file over 35 commits instead of needing a single big-bang rewrite.</p>

<h2 id="phase-3-die" style="margin:2rem 0 .75rem">Phase 3: Killing <code>die()</code> and <code>exit()</code></h2>
<p>This one surprised us. The codebase had <code>die()</code> and <code>exit()</code> calls scattered across error paths, validation failures, and early returns:</p>
<?php App::render('/components/_code', [
  'label' => 'A typical Apache-era guard',
  'code'  => <<<'PHP'
if (!$user) {
    http_response_code(403);
    die(json_encode(['error' => 'unauthorized']));
}
PHP,
]); ?>
<p>Under Apache, <code>die()</code> kills the current PHP process. A new process spawns for the next request. No problem.</p>
<p>Under ZealPHP, <code>die()</code> kills the <strong>entire server</strong>. All 12 worker processes. Every connected user. Gone.</p>
<p>Two commits tackled this:</p>
<?php App::render('/components/_code', [
  'label' => '',
  'lang'  => 'text',
  'code'  => <<<'TXT'
7c2dfe09d  fix: eliminate all die()/exit() calls on HTTP hot paths
ecc4c88ff  fix: replace die()/exit() with HaltException/return for ZealPHP coroutine safety
TXT,
]); ?>
<p>Every <code>die()</code> was replaced with either a <code>return</code> (in functions) or a <code>throw new HaltException()</code> (in deeply nested code where returning wasn't possible). ZealPHP catches <code>HaltException</code> at the request boundary and cleanly ends just that one request.</p>

<h2 id="phase-4-mongo" style="margin:2rem 0 .75rem">Phase 4: The MongoDB wall (and the Rust extension)</h2>
<p>We hit the biggest blocker when we tried to go fully async: the PECL MongoDB driver (<code>mongodb.so</code>) is a synchronous C extension. When it makes a network call to MongoDB, it blocks the entire event loop. In a coroutine-based server, one slow MongoDB query freezes <strong>all</strong> concurrent requests.</p>
<p>We had two choices:</p>
<ol style="margin:.5rem 0 1rem;padding-left:1.4rem">
  <li>Accept blocking I/O and lose most of ZealPHP's performance benefit</li>
  <li>Build an async MongoDB driver</li>
</ol>
<p>We chose option 2.</p>
<p><strong>zealphp-mongodb</strong> is a PHP extension written in Rust that wraps the official MongoDB Rust driver (which is fully async) and bridges it with OpenSwoole's coroutine system. When a coroutine makes a MongoDB query, it yields control to the event loop while waiting for the response. Other requests continue processing.</p>
<p>The implementation:</p>
<ul style="margin:.5rem 0 1rem;padding-left:1.4rem">
  <li><strong>Rust</strong> with <code>ext-php-rs</code> for PHP FFI</li>
  <li><strong>Official MongoDB Rust driver</strong> for async I/O</li>
  <li><strong>Drop-in API compatibility</strong> with <code>mongodb/mongodb</code> — same class names, same methods (<code>Collection</code>, <code>Database</code>, <code>Client</code>, <code>ObjectId</code>, <code>UTCDateTime</code>, etc.)</li>
  <li>Built during Docker image build (<code>cargo build --release</code> in the Dockerfile)</li>
</ul>
<?php App::render('/components/_code', [
  'label' => 'php-override.ini',
  'lang'  => 'ini',
  'code'  => <<<'INI'
; extension=mongodb.so   ; replaced by zealphp-mongodb Rust driver
extension=zealphp_mongodb.so
INI,
]); ?>
<p>This was 6 commits of fighting toolchains:</p>
<?php App::render('/components/_code', [
  'label' => '',
  'lang'  => 'text',
  'code'  => <<<'TXT'
739b16b  feat: Dockerfile builds zealphp-mongodb Rust extension, replaces PECL mongodb
d4f6571  fix: bump Rust toolchain 1.83 → 1.87 (edition2024 support required)
95f56e1  fix: use rust:latest for Dockerfile (deps need Rust 1.88+)
0ff9a20  fix: install libclang-dev for bindgen in Rust build
875ba8a  fix: Dockerfile uses signed-by for MongoDB 8.0 repo (apt-key removed in 25.04)
681e346  fix: clone zealphp-mongodb to /home/labs/ (not /tmp/) so PHP library persists
TXT,
]); ?>

<h2 id="phase-5-sse" style="margin:2rem 0 .75rem">Phase 5: SSE streaming — why SSEStream exists (and why not <code>$response->sse()</code>)</h2>
<p>Seven endpoints in the application use Server-Sent Events for real-time streaming: deploy logs, AI chat responses, code arena execution output, roadmap generation. Under Apache, these used the classic PHP streaming pattern:</p>
<?php App::render('/components/_code', [
  'label' => 'Apache-era SSE',
  'code'  => <<<'PHP'
header('Content-Type: text/event-stream');
while (ob_get_level()) ob_end_flush();
while (!connection_aborted()) {
    echo "data: " . json_encode($chunk) . "\n\n";
    flush();
}
PHP,
]); ?>
<p>ZealPHP has a clean native SSE API:</p>
<?php App::render('/components/_code', [
  'label' => 'ZealPHP-native SSE',
  'code'  => <<<'PHP'
$app->route('/events', function($response) {
    $response->sse(function($emit) {
        $emit(json_encode(['tick' => 1]), 'update', '1');
    });
});
PHP,
]); ?>
<p>So why didn't we just use <code>$response->sse()</code>?</p>
<p><strong>Because the SSE endpoints need to work on both Apache and ZealPHP.</strong></p>
<p>The <code>/api/instance/deploylog.php</code> endpoint, for example, is accessed from browser JavaScript via <code>new EventSource('/api/instance/deploylog.php?...')</code>. When the request arrives via Apache (port 80), there's no <code>$response</code> object — it's classic PHP with <code>echo</code> and <code>flush()</code>. When it arrives via ZealPHP (port 8080), we need <code>$response->write()</code> on the OpenSwoole response.</p>
<p>We could have branched every SSE endpoint, but instead we built <code>SSEStream</code> — a 94-line class that auto-detects the runtime and routes I/O to the right backend:</p>
<?php App::render('/components/_code', [
  'label' => 'SSEStream.php — runtime-detecting SSE writer',
  'code'  => <<<'PHP'
class SSEStream
{
    private bool $isZealPHP = false;
    private $response = null;

    public function __construct()
    {
        if (class_exists('\ZealPHP\RequestContext')) {
            $g = \ZealPHP\RequestContext::instance();
            if (isset($g->openswoole_response) && $g->openswoole_response) {
                $this->response = $g->openswoole_response;
                $this->isZealPHP = true;
            }
        }
    }

    public function write(string $raw): void
    {
        if ($this->isZealPHP) {
            if ($this->response->isWritable()) {
                $this->response->write($raw);   // async, non-blocking
            }
        } else {
            echo $raw;
            @flush();   // Apache buffered output
        }
    }

    public function isConnected(): bool
    {
        if ($this->isZealPHP) {
            return $this->response && $this->response->isWritable();
        }
        return !connection_aborted();
    }
}
PHP,
]); ?>
<p>Every SSE endpoint now does:</p>
<?php App::render('/components/_code', [
  'label' => 'Endpoint shape — same code, both servers',
  'code'  => <<<'PHP'
$sse = new SSEStream();
$sse->start();
$sse->emit('status', ['step' => 'generating']);
// ... work ...
$sse->emit('done', ['result' => $output]);
$sse->end();
PHP,
]); ?>

<h3 style="margin:1.5rem 0 .5rem">Why not just use <code>$response->sse()</code> even in the ZealPHP path?</h3>
<p>Two reasons:</p>
<ol style="margin:.5rem 0 1rem;padding-left:1.4rem">
  <li><strong>The SSE endpoints are PHP files loaded by <code>include</code></strong> — they're not route closures that receive <code>$response</code> as a parameter. They're legacy files included into ZealPHP's request context via the <code>page()</code> helper. They don't have direct access to the route-level <code>$response</code> object.</li>
  <li><strong><code>$response->sse()</code> uses a callback pattern</strong> — you pass a closure and the framework manages the lifecycle. Our SSE endpoints are imperative: they open a process, read its stdout line by line in a loop, stream each line to the client, and check for disconnection. The callback pattern doesn't fit without restructuring the entire endpoint.</li>
</ol>
<p>SSEStream gives us <code>$response->sse()</code>'s non-blocking I/O underneath, but with the imperative API our existing code expects. It's the same bridge philosophy as <code>$g</code> — make the old code work in the new world without rewriting it.</p>

<h3 style="margin:1.5rem 0 .5rem">Is using <code>$g->openswoole_response</code> in SSEStream an anti-pattern?</h3>
<p>Reaching into <code>$g->openswoole_response</code> directly is definitely reaching past the framework's abstraction layer. In a greenfield ZealPHP application, you'd use <code>$response->sse()</code> and never touch the raw OpenSwoole response.</p>
<p>But this isn't greenfield — it's a migration. SSEStream exists in the narrow space between "the old code pattern" and "the new runtime." It accesses <code>$g->openswoole_response</code> because:</p>
<ul style="margin:.5rem 0 1rem;padding-left:1.4rem">
  <li>The included PHP files don't receive <code>$response</code> as a parameter</li>
  <li>ZealPHP's <code>RequestContext</code> exposes <code>openswoole_response</code> specifically for this use case — it's a documented escape hatch, not a private internal</li>
  <li>The alternative is restructuring 7 SSE endpoints into closure-based routes, which breaks Apache parity</li>
</ul>
<p>Once Apache is eventually retired, SSEStream becomes unnecessary. Each endpoint can be rewritten as a clean ZealPHP route with <code>$response->sse()</code>. But that's a future migration — today, SSEStream is the bridge that makes both worlds work.</p>

<h2 id="phase-6-edge-cases" style="margin:2rem 0 .75rem">Phase 6: OAuth, sessions, and the edge cases</h2>
<p>With the core migration done, the real testing began. OAuth login was the first thing that broke:</p>
<?php App::render('/components/_code', [
  'label' => '',
  'lang'  => 'text',
  'code'  => <<<'TXT'
65f60af6b  fix: OAuth login — $_SERVER → $g->server in home.php, get_config uses $GLOBALS
66a36ff6b  fix: safe token access in OAuth flow — handle array/object session data
e0fe116dc  feat: OAuth login working on ZealPHP — full authenticated flow
TXT,
]); ?>
<p>OAuth callbacks read <code>$_SERVER['HTTP_HOST']</code> to construct redirect URIs. In ZealPHP coroutine mode, <code>$_SERVER</code> is empty — you need <code>$g->server['HTTP_HOST']</code>. One missed reference and the entire login flow breaks silently.</p>
<p>Session handling had its own subtlety:</p>
<?php App::render('/components/_code', [
  'label' => '',
  'lang'  => 'text',
  'code'  => <<<'TXT'
8842919e8  fix: re-bind $g->session reference after session_start()
TXT,
]); ?>
<p>In Apache mode, <code>$g->session</code> is a reference to <code>$_SESSION</code>. But PHP's <code>session_start()</code> <strong>replaces</strong> the <code>$_SESSION</code> variable entirely — the old reference points to the pre-start empty array, not the hydrated session data. We had to re-bind the reference after every <code>session_start()</code>:</p>
<?php App::render('/components/_code', [
  'label' => 'Apache-mode session re-bind',
  'code'  => <<<'PHP'
if (!($g instanceof \ZealPHP\RequestContext)) {
    $g->session = &$_SESSION;   // re-bind after session_start() replaces $_SESSION
}
PHP,
]); ?>

<h2 id="phase-7-revert" style="margin:2rem 0 .75rem">Phase 7: The revert and recovery</h2>
<p>The commit history tells an honest story. We didn't get it right on the first try:</p>
<?php App::render('/components/_code', [
  'label' => '',
  'lang'  => 'text',
  'code'  => <<<'TXT'
42a94a314  feat: enable superglobals(false) — full coroutine mode with HOOK_ALL
65fc77565  fix: revert to superglobals(true) CGI mode (stable)
7f62b9460  chore: revert to stable CGI mode + document coroutine bridge blocker
TXT,
]); ?>
<p>We enabled full coroutine mode, discovered the PECL MongoDB driver was blocking the event loop (making the entire server feel slower than Apache), and <strong>reverted to CGI mode</strong> while we designed and built zealphp-mongodb.</p>
<p>Three commits later:</p>
<?php App::render('/components/_code', [
  'label' => '',
  'lang'  => 'text',
  'code'  => <<<'TXT'
f68e8f0e5  docs: zealphp-mongodb design spec
afb410338  docs: zealphp-mongodb full spec — complete mongodb/mongodb API coverage
95e09f506  docs: zealphp-mongodb T0 implementation plan
TXT,
]); ?>
<p>And after building the Rust extension, we went back to full coroutine mode and this time it stuck:</p>
<?php App::render('/components/_code', [
  'label' => '',
  'lang'  => 'text',
  'code'  => <<<'TXT'
d81e68577  feat: full authenticated dashboard working on ZealPHP coroutine mode
TXT,
]); ?>

<h2 style="margin:2rem 0 .75rem">What we built along the way</h2>
<p>The migration wasn't just about making old code work. It also unlocked new capabilities:</p>

<h3 style="margin:1.5rem 0 .5rem">Native cron via event loop</h3>
<?php App::render('/components/_code', [
  'label' => 'server.php — no external cron daemon needed',
  'code'  => <<<'PHP'
App::tick(60000, function() {
    // runs every 60 seconds inside the ZealPHP event loop
    // only on worker #0 to prevent duplicate execution
});
PHP,
]); ?>
<p>Apache needs a system cron daemon to run scheduled tasks. ZealPHP's event loop can schedule them internally with <code>App::tick()</code>, with the added benefit that cron jobs share the same connection pool and memory space as the web server.</p>

<h3 style="margin:1.5rem 0 .5rem">Non-blocking <code>labsctl</code> via <code>Coroutine::exec()</code></h3>
<p>The dashboard frequently shells out to <code>labsctl</code> (the Python control plane CLI) for container operations. Under Apache, this is a blocking <code>exec()</code> call — the PHP process sits idle waiting for <code>labsctl</code> to finish.</p>
<p>Under ZealPHP:</p>
<?php App::render('/components/_code', [
  'label' => 'Non-blocking shell-out',
  'code'  => <<<'PHP'
// Yields to event loop while waiting for labsctl
$result = \OpenSwoole\Coroutine::exec("labsctl deploy ...");
PHP,
]); ?>
<p>The coroutine yields while <code>labsctl</code> runs. Other requests continue processing on the same worker.</p>

<h3 style="margin:1.5rem 0 .5rem">zealphp-mongodb: async MongoDB for PHP</h3>
<p>The custom Rust extension we built is arguably the most significant artifact of this migration. It provides:</p>
<ul style="margin:.5rem 0 1rem;padding-left:1.4rem">
  <li>Full API parity with <code>mongodb/mongodb</code> (Collection, Database, Client)</li>
  <li>25 Collection methods, 15 Database methods, 12 Client methods</li>
  <li>Complete BSON type system (ObjectId, UTCDateTime, Regex, Binary, Decimal128, Int64)</li>
  <li>True async I/O — MongoDB queries yield to the OpenSwoole event loop</li>
  <li>Drop-in replacement — existing code works without changes</li>
</ul>

<h2 style="margin:2rem 0 .75rem">The numbers</h2>
<table class="ztable" style="margin-bottom:1.5rem">
  <tr><th>Metric</th><th>Value</th></tr>
  <tr><td>labs-dashboard-web commits</td><td>41 (since origin/master)</td></tr>
  <tr><td>labs-devops commits</td><td>9</td></tr>
  <tr><td>Files changed (dashboard)</td><td>806</td></tr>
  <tr><td>Lines added</td><td>84,112</td></tr>
  <tr><td>Lines removed</td><td>1,299</td></tr>
  <tr><td>SSE endpoints migrated</td><td>7</td></tr>
  <tr><td>Reverts during migration</td><td>3</td></tr>
  <tr><td>Custom extensions built</td><td>1 (zealphp-mongodb, Rust)</td></tr>
  <tr><td><code>die()</code>/<code>exit()</code> calls eliminated</td><td>All on HTTP hot paths</td></tr>
  <tr><td><code>$_SESSION</code> references replaced</td><td>All</td></tr>
</table>

<h2 style="margin:2rem 0 .75rem">Where it runs today</h2>
<p>Both servers are live right now on the same machine (labsdev — 172.30.0.3):</p>
<table class="ztable" style="margin-bottom:1rem">
  <tr><th>URL</th><th>Server</th><th>Header</th></tr>
  <tr><td><a href="https://labsdev.selfmade.ninja">labsdev.selfmade.ninja</a></td><td>Apache :80</td><td><code>Server: Apache/2.4.63</code></td></tr>
  <tr><td><a href="https://zealphp.selfmade.ninja">zealphp.selfmade.ninja</a></td><td>ZealPHP :8080</td><td><code>X-Powered-By: ZealPHP + OpenSwoole</code></td></tr>
</table>
<p>And in production (106.51.76.75), the same migration has been deployed:</p>
<table class="ztable" style="margin-bottom:1.25rem">
  <tr><th>URL</th><th>Server</th><th>Header</th></tr>
  <tr><td><a href="https://labs.selfmade.ninja">labs.selfmade.ninja</a></td><td>Apache :80</td><td><code>Server: Apache/2.4.58</code></td></tr>
  <tr><td><a href="https://php.zeal.ninja">php.zeal.ninja</a></td><td>ZealPHP :8080</td><td><code>X-Powered-By: ZealPHP + OpenSwoole</code></td></tr>
</table>
<p>The same PHP files. The same MongoDB. The same Redis sessions. Two completely different PHP execution models. Running in the same container — on both dev and production.</p>

<h2 style="margin:2rem 0 .75rem">Key takeaways</h2>
<ol style="margin:.5rem 0 1.25rem;padding-left:1.4rem;line-height:1.85">
  <li><strong>Bridge patterns beat big-bang rewrites.</strong> The <code>$g</code> context object and <code>SSEStream</code> class each added a thin abstraction layer that let us migrate incrementally. Neither is the "final" architecture — they're scaffolding that can be removed once Apache is retired.</li>
  <li><strong><code>die()</code> is a time bomb in async PHP.</strong> Any codebase migrating from Apache to an async runtime needs to audit and eliminate every <code>die()</code> and <code>exit()</code> call. There's no safe way to use them in a shared-process server.</li>
  <li><strong>The MongoDB driver was the real blocker, not the application code.</strong> We could work around superglobals, <code>die()</code>, and sessions with application-level patterns. But a synchronous database driver in an async runtime is a fundamental architectural mismatch that required a new extension.</li>
  <li><strong>Revert early, revert honestly.</strong> When coroutine mode was slower than Apache because of the blocking MongoDB driver, we didn't push through — we reverted to stable CGI mode and took the time to design a proper solution. The commit history shows the reverts. That's fine. That's engineering.</li>
  <li><strong>Same files, two servers is a superpower during migration.</strong> Having Apache as a fallback meant we could test ZealPHP endpoint by endpoint. If something broke, users were still served by Apache. Zero downtime, zero risk.</li>
</ol>

<h2 style="margin:2rem 0 .75rem">What's next</h2>
<p>The <code>$g</code> bridge and <code>SSEStream</code> will eventually be retired. Each SSE endpoint can become a clean ZealPHP route with native <code>$response->sse()</code>. Each page handler can take <code>$request</code> and <code>$response</code> as parameters instead of reaching into <code>$g</code>.</p>
<p>But that's optimization, not migration. The migration is done. The old code works. The new server works. Both serve the same files, and both are live in production.</p>
<p style="font-style:italic;color:var(--text-muted);margin-top:1rem">Sometimes the best migration is the one where you don't have to choose.</p>

<hr style="margin:2.5rem 0 1.25rem;border:0;border-top:1px solid var(--border)">
<p style="color:var(--text-muted);font-size:.9rem">
  Built at <a href="https://labs.selfmade.ninja">Selfmade Ninja Labs</a> — an educational platform where students learn by doing. The ZealPHP framework is open source at <a href="https://github.com/sibidharan/zealphp">github.com/sibidharan/zealphp</a>.
</p>

</div>
</section>

<?php use ZealPHP\App; ?>

<section class="section sna-hero-section">
  <div class="container sna-hero-container">
    <p class="sna-kicker">Case study · Production migration</p>
    <h1 class="section-title sna-title">From Apache to ZealPHP: How We Made the Same PHP Codebase Run on Two Servers Simultaneously</h1>
    <p class="section-desc sna-subtitle">
      41 commits. ~2,300 lines of app code. One custom Rust extension. Zero downtime.
    </p>
  </div>
</section>

<section class="section">
<div class="container sna-body-container">

<p>We just finished migrating the Selfmade Ninja Labs dashboard — a large PHP/MongoDB/jQuery web application — from traditional Apache+mod_php to ZealPHP. But we didn't replace Apache. We made both run <strong>side by side, in the same container, serving the same files</strong>. Here's the full story of what it took, what broke, what we built, and what we learned.</p>

<h2 class="sna-h2">What we started with</h2>
<p>The Selfmade Ninja Labs platform (<code>labs.selfmade.ninja</code>) is a Docker-based educational infrastructure. The web dashboard is a PHP application served by Apache with mod_php — the most traditional PHP stack there is. It handles user auth (OAuth), lab management, a code arena, quizzes, clan systems, roadmaps, discussion forums, and an internal economy system called Zeal/Jolt.</p>
<p>The codebase is large: hundreds of PHP files, MongoDB models via a custom ORM (MongoGetterSetter), a REST API layer, Server-Sent Events endpoints for real-time streaming, and a Grunt-based frontend build. It was never designed to run on anything other than Apache.</p>

<h2 class="sna-h2">What ZealPHP is (and why we wanted it)</h2>
<p><a href="https://github.com/sibidharan/zealphp">ZealPHP</a> is an async PHP web framework built on OpenSwoole. Instead of Apache spawning a new PHP process for every request, ZealPHP keeps a long-running PHP process with an event loop — like Node.js, but for PHP.</p>
<p>The benefits are significant:</p>
<ul class="sna-list">
  <li><strong>Persistent connections</strong> — MongoDB, Redis, and RabbitMQ connections stay open across requests instead of being recreated every time</li>
  <li><strong>Coroutines</strong> — concurrent I/O without callback hell (PHP Fibers + OpenSwoole)</li>
  <li><strong>No CGI overhead</strong> — no forking, no process boot cost per request</li>
  <li><strong>Built-in SSE/streaming</strong> — native support instead of Apache's flush-and-pray approach</li>
  <li><strong>Event loop cron</strong> — schedule recurring tasks inside the server process via <code>App::tick()</code>, no external cron daemon needed</li>
</ul>
<p>But migration from a superglobal-based, process-per-request PHP codebase to a shared-process async runtime is not trivial. It is, in fact, one of the hardest migrations in the PHP world.</p>

<h2 class="sna-h2">The architecture: one container, two servers, same volume</h2>
<p>Here's what we ended up with <strong>in development</strong> (not yet cut over to production):</p>

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
<ol class="sna-list">
  <li><strong>Dockerfile</strong> — Ubuntu 24.04 → 25.04 (PHP 8.4), added OpenSwoole 26 + uopz, Rust toolchain for building zealphp-mongodb</li>
  <li><strong>entry.sh</strong> — 8 lines: "if <code>server.php</code> exists, start ZealPHP as a daemon on port 8080"</li>
  <li><strong>php-override.ini</strong> — Swapped <code>extension=mongodb.so</code> → <code>extension=zealphp_mongodb.so</code></li>
  <li><strong>docker-compose.override.prod.yml</strong> — Added Traefik router for <code>zealphp.selfmade.ninja</code> → port 8080</li>
  <li><strong>docker-compose.yml</strong> — Exposed port 8080</li>
</ol>
<p>That's the devops side. The application side is where the real work happened.</p>

<h2 id="phase-1-routes" class="sna-h2">Phase 1: Route translation (the easy part)</h2>
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

<h2 id="phase-2-superglobals" class="sna-h2">Phase 2: The superglobals problem (the hard part)</h2>
<p>This is where the migration gets interesting.</p>
<p>In Apache's process-per-request model, PHP superglobals (<code>$_SESSION</code>, <code>$_SERVER</code>, <code>$_GET</code>, <code>$_POST</code>) are naturally isolated. Each request gets its own process with its own copy of these variables. When the request ends, the process dies and everything is garbage collected.</p>
<p>In ZealPHP's shared-process model, <strong>all requests share the same PHP process</strong>. If request A writes to <code>$_SESSION['username'] = 'alice'</code> and request B reads <code>$_SESSION['username']</code>, they'd get each other's data. This is a catastrophic data leak.</p>
<p>The solution was already built into ZealPHP: <code>$g</code> — the <code>RequestContext</code>.</p>

<h3 class="sna-h3">The <code>$g</code> context — ZealPHP's RequestContext</h3>
<p>ZealPHP's <code>RequestContext</code> (aliased as <code>$g</code>) provides per-coroutine isolated properties that mirror PHP's superglobals:</p>
<?php App::render('/components/_code', [
  'label' => 'ZealPHP RequestContext — declared properties',
  'code'  => <<<'PHP'
public array $server  = [];
public array $get     = [];
public array $post    = [];
public array $request = [];
public array $cookie  = [];
public array $files   = [];
public array $session = [];
PHP,
]); ?>
<p>In coroutine mode, <code>RequestContext::instance()</code> returns a per-coroutine instance from <code>Coroutine::getContext()</code> — each request gets its own <code>$g</code> with isolated state, automatically freed when the coroutine ends.</p>
<p>A natural question: <strong>doesn't <code>$g->get</code> already refer to <code>$_GET</code> automatically?</strong> No. These are <em>declared</em> public properties — plain arrays. ZealPHP populates them from the OpenSwoole request object on every incoming request:</p>
<?php App::render('/components/_code', [
  'label' => 'Inside ZealPHP App.php on("request") handler',
  'code'  => <<<'PHP'
$g->get    = $request->get    ?? [];
$g->post   = $request->post   ?? [];
$g->cookie = $request->cookie ?? [];
$g->server = /* built from $request->server + $request->header */;
PHP,
]); ?>
<p><code>$_GET</code> is never involved. The <code>RequestContext</code> class does have a <code>__get</code> magic method that maps to <code>$GLOBALS['_GET']</code> in superglobals mode — but that only fires for <em>undeclared</em> properties. Since <code>get</code>, <code>post</code>, <code>server</code> etc. are declared properties, PHP accesses them directly and the magic method never runs.</p>
<p>So under ZealPHP, <code>$g->get</code> works natively — the framework handles everything. But <strong>under Apache, ZealPHP isn't loaded at all.</strong> There's no <code>RequestContext</code> class, no OpenSwoole, no coroutines. We needed a shim:</p>
<?php App::render('/components/_code', [
  'label' => 'load.php — the dual-runtime shim (Apache fallback is the only "bridge" part)',
  'code'  => <<<'PHP'
$GLOBALS['g'] = $g = class_exists('\ZealPHP\RequestContext', false)
    ? \ZealPHP\RequestContext::instance()    // ZealPHP: populated by framework
    : (object)[                               // Apache: references to superglobals
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
<p>The Apache fallback creates a plain PHP object where <code>$g->get</code> is a <em>reference</em> to <code>$_GET</code> — so reads and writes pass through to the superglobal. Under ZealPHP, <code>$g->get</code> is a per-coroutine array populated from the async request. Same API, completely different mechanisms underneath.</p>
<p>The bulk of the migration work was replacing every <code>$_GET</code>, <code>$_POST</code>, <code>$_SERVER</code>, and <code>$_SESSION</code> reference with <code>$g->get</code>, <code>$g->post</code>, <code>$g->server</code>, and <code>$g->session</code>. That's 35 commits of mechanical changes — the code moved <em>toward</em> ZealPHP's native pattern, and the 5-line Apache shim kept backward compatibility.</p>
<p>Similarly, <code>$_SESSION</code> access was wrapped in a <code>Session</code> class:</p>
<?php App::render('/components/_code', [
  'label' => 'Session class — same API, two runtime backings',
  'code'  => <<<'PHP'
Session::get('username')      // maps to $GLOBALS['g']->session['username']
Session::set('username', $v)  // maps to $GLOBALS['g']->session['username'] = $v
Session::isset('username')    // maps to isset($GLOBALS['g']->session['username'])
PHP,
]); ?>
<p><strong>Is <code>$g</code> an anti-pattern?</strong> No — <code>$g</code> is ZealPHP's native request context. The application code was already moving <em>toward</em> the framework's design, not away from it. The only "bridge" part is the 5-line Apache fallback object, which exists solely so the same code runs when ZealPHP isn't loaded.</p>
<p>ZealPHP also offers a superglobals mode (<code>App::superglobals(true)</code>) that auto-populates <code>$_GET</code>/<code>$_POST</code> etc. per request — but that breaks under concurrent coroutines <em>unless</em> you run <a href="/coroutines#lifecycle-modes"><code>App::mode(App::MODE_COROUTINE_LEGACY)</code></a> with ext-zealphp, which snapshots and restores the superglobals per coroutine so concurrent requests don't race. We chose the <code>$g</code> pattern for Apache parity instead (the same code runs without ZealPHP loaded). Using <code>$g->get</code> directly is the recommended pattern for the dual-runtime case. The migration happened file by file over 35 commits — no big-bang rewrite needed.</p>

<div class="sna-callout">
  <strong class="sna-callout-strong">The Apache fallback is now a first-class ZealPHP artifact.</strong>
  <p class="sna-callout-p">What started as our <code>load.php</code> shim is now shipped and documented in the framework as the canonical <strong>dual-runtime Apache-parity bridge</strong> — a standalone, dependency-free <code>compat/g.php</code> (with a drift-guard test) that any project running on both Apache and ZealPHP can drop in. It's not a workaround for a limitation; it's the only design that <em>can</em> work across the "with ZealPHP / without ZealPHP" boundary, because on Apache the framework simply isn't loaded. See <a href="/legacy-apps#dual-runtime">the dual-runtime guide</a> for the full pattern and why it can't be a runtime feature. (Note: this is the <strong>coroutine-mode</strong> story — distinct from v0.2.27's <a href="/vs-fpm">superglobals(true) drop-in LAMP mode</a>, where ZealPHP-only apps can read <code>$_GET</code>/<code>$_SESSION</code> directly and skip the shim.)</p>
</div>

<h2 id="phase-3-die" class="sna-h2">Phase 3: Killing <code>die()</code> and <code>exit()</code></h2>
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

<h2 id="phase-4-mongo" class="sna-h2">Phase 4: The MongoDB wall (and the Rust extension)</h2>
<p>We hit the biggest blocker when we tried to go fully async: the PECL MongoDB driver (<code>mongodb.so</code>) is a synchronous C extension. When it makes a network call to MongoDB, it blocks the entire event loop. In a coroutine-based server, one slow MongoDB query freezes <strong>all</strong> concurrent requests.</p>
<p>We had two choices:</p>
<ol class="sna-list">
  <li>Accept blocking I/O and lose most of ZealPHP's performance benefit</li>
  <li>Build an async MongoDB driver</li>
</ol>
<p>We chose option 2.</p>
<p><strong>zealphp-mongodb</strong> is a PHP extension written in Rust that wraps the official MongoDB Rust driver (which is fully async) and bridges it with OpenSwoole's coroutine system. When a coroutine makes a MongoDB query, it yields control to the event loop while waiting for the response. Other requests continue processing.</p>
<p>The implementation:</p>
<ul class="sna-list">
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

<h2 id="phase-5-sse" class="sna-h2">Phase 5: SSE streaming — why SSEStream exists (and why not <code>$response->sse()</code>)</h2>
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

<h3 class="sna-h3">Why not just use <code>$response->sse()</code> even in the ZealPHP path?</h3>
<p>Two reasons:</p>
<ol class="sna-list">
  <li><strong>The SSE endpoints are PHP files loaded by <code>include</code></strong> — they're not route closures that receive <code>$response</code> as a parameter. They're legacy files included into ZealPHP's request context via the <code>page()</code> helper. They don't have direct access to the route-level <code>$response</code> object.</li>
  <li><strong><code>$response->sse()</code> uses a callback pattern</strong> — you pass a closure and the framework manages the lifecycle. Our SSE endpoints are imperative: they open a process, read its stdout line by line in a loop, stream each line to the client, and check for disconnection. The callback pattern doesn't fit without restructuring the entire endpoint.</li>
</ol>
<p>SSEStream gives us <code>$response->sse()</code>'s non-blocking I/O underneath, but with the imperative API our existing code expects. It's the same bridge philosophy as <code>$g</code> — make the old code work in the new world without rewriting it.</p>

<h3 class="sna-h3">Is using <code>$g->openswoole_response</code> in SSEStream an anti-pattern?</h3>
<p>Reaching into <code>$g->openswoole_response</code> directly is definitely reaching past the framework's abstraction layer. In a greenfield ZealPHP application, you'd use <code>$response->sse()</code> and never touch the raw OpenSwoole response.</p>
<p>But this isn't greenfield — it's a migration. SSEStream exists in the narrow space between "the old code pattern" and "the new runtime." It accesses <code>$g->openswoole_response</code> because:</p>
<ul class="sna-list">
  <li>The included PHP files don't receive <code>$response</code> as a parameter</li>
  <li>ZealPHP's <code>RequestContext</code> exposes <code>openswoole_response</code> specifically for this use case — it's a documented escape hatch, not a private internal</li>
  <li>The alternative is restructuring 7 SSE endpoints into closure-based routes, which breaks Apache parity</li>
</ul>
<p>Once Apache is eventually retired, SSEStream becomes unnecessary. Each endpoint can be rewritten as a clean ZealPHP route with <code>$response->sse()</code>. But that's a future migration — today, SSEStream is the bridge that makes both worlds work.</p>

<h2 id="phase-6-edge-cases" class="sna-h2">Phase 6: OAuth, sessions, and the edge cases</h2>
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

<h2 id="phase-7-revert" class="sna-h2">Phase 7: The revert and recovery</h2>
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

<h2 class="sna-h2">What we built along the way</h2>
<p>The migration wasn't just about making old code work. It also unlocked new capabilities:</p>

<h3 class="sna-h3">Native cron via event loop</h3>
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

<h3 class="sna-h3">Non-blocking <code>labsctl</code> via <code>Coroutine::exec()</code></h3>
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

<h3 class="sna-h3">zealphp-mongodb: async MongoDB for PHP</h3>
<p>The custom Rust extension we built is arguably the most significant artifact of this migration. It provides:</p>
<ul class="sna-list">
  <li>Full API parity with <code>mongodb/mongodb</code> (Collection, Database, Client)</li>
  <li>25 Collection methods, 15 Database methods, 12 Client methods</li>
  <li>Complete BSON type system (ObjectId, UTCDateTime, Regex, Binary, Decimal128, Int64)</li>
  <li>True async I/O — MongoDB queries yield to the OpenSwoole event loop</li>
  <li>Drop-in replacement — existing code works without changes</li>
</ul>

<h2 class="sna-h2">The numbers</h2>
<table class="ztable sna-table-mb">
  <tr><th>Metric</th><th>Value</th></tr>
  <tr><td>labs-dashboard-web commits</td><td>41 (since origin/master)</td></tr>
  <tr><td>labs-devops commits</td><td>9</td></tr>
  <tr><td><strong>App code changed</strong></td><td><strong>237 files, +2,298 / −1,189 lines</strong></td></tr>
  <tr><td>Vendor dependencies added</td><td>563 files, +77,764 lines (ZealPHP framework, openswoole stubs, composer.lock)</td></tr>
  <tr><td>Docs &amp; migration plans</td><td>6 files, +4,050 lines</td></tr>
  <tr><td>Total (all categories)</td><td>806 files, +84,112 / −1,299 lines</td></tr>
  <tr><td>SSE endpoints migrated</td><td>7</td></tr>
  <tr><td>Reverts during migration</td><td>3</td></tr>
  <tr><td>Custom extensions built</td><td>1 (zealphp-mongodb, Rust)</td></tr>
  <tr><td><code>die()</code>/<code>exit()</code> calls eliminated</td><td>All on HTTP hot paths</td></tr>
  <tr><td><code>$_SESSION</code> references replaced</td><td>All</td></tr>
</table>
<p class="sna-note">The headline number (84K lines) is 92% vendor — the ZealPHP framework and its dependencies committed into the repo. The actual migration work touched <strong>237 files with ~2,300 lines of app code changes</strong>.</p>

<h2 class="sna-h2">Where it runs today</h2>
<p>Both servers are live right now on the same machine (labsdev — 172.30.0.3):</p>
<table class="ztable sna-table-mb">
  <tr><th>URL</th><th>Server</th><th>Header</th></tr>
  <tr><td><a href="https://labsdev.selfmade.ninja">labsdev.selfmade.ninja</a></td><td>Apache :80</td><td><code>Server: Apache/2.4.63</code></td></tr>
  <tr><td><a href="https://zealphp.selfmade.ninja">zealphp.selfmade.ninja</a></td><td>ZealPHP :8080</td><td><code>X-Powered-By: ZealPHP + OpenSwoole</code></td></tr>
</table>
<p>Production (<code>labs.selfmade.ninja</code> at 106.51.76.75) still runs <strong>Apache only</strong> — ZealPHP hasn't been deployed there yet. That's the next step:</p>
<table class="ztable sna-table-mb-lg">
  <tr><th>URL</th><th>Server</th><th>Header</th></tr>
  <tr><td><a href="https://labs.selfmade.ninja">labs.selfmade.ninja</a></td><td>Apache :80</td><td><code>Server: Apache/2.4.58</code></td></tr>
</table>
<p class="sna-note-sm"><strong>Note:</strong> <a href="https://php.zeal.ninja">php.zeal.ninja</a> is a <em>separate</em> project — the ZealPHP portfolio/demo site, not the Labs dashboard. It was born from the same framework but is its own application.</p>
<p>The same PHP files. The same MongoDB. The same Redis sessions. Two completely different PHP execution models. Running in the same container <strong>on dev</strong>, with production deployment coming next.</p>

<h2 class="sna-h2">Key takeaways</h2>
<ol class="sna-takeaways">
  <li><strong>Bridge patterns beat big-bang rewrites.</strong> The <code>$g</code> context object and <code>SSEStream</code> class each added a thin abstraction layer that let us migrate incrementally. Neither is the "final" architecture — they're scaffolding that can be removed once Apache is retired.</li>
  <li><strong><code>die()</code> is a time bomb in plain async PHP.</strong> Any codebase migrating from Apache to an async runtime needs to audit and eliminate every <code>die()</code> and <code>exit()</code> call on HTTP hot paths. On PHP 8.4+, <a href="/coroutines#lifecycle-modes"><code>coroutine-legacy</code></a> mode catches <code>exit()</code>/<code>die()</code> per-coroutine (via OpenSwoole's <code>ExitException</code>) so the worker survives — but explicit <code>HaltException</code>/<code>return</code> is still the clean, portable pattern that works across PHP versions and avoids the dependency on that behaviour.</li>
  <li><strong>The MongoDB driver was the real blocker, not the application code.</strong> We could work around superglobals, <code>die()</code>, and sessions with application-level patterns. But a synchronous database driver in an async runtime is a fundamental architectural mismatch that required a new extension.</li>
  <li><strong>Revert early, revert honestly.</strong> When coroutine mode was slower than Apache because of the blocking MongoDB driver, we didn't push through — we reverted to stable CGI mode and took the time to design a proper solution. The commit history shows the reverts. That's fine. That's engineering.</li>
  <li><strong>Same files, two servers is a superpower during migration.</strong> Having Apache as a fallback meant we could test ZealPHP endpoint by endpoint. If something broke, users were still served by Apache. Zero downtime, zero risk.</li>
</ol>

<h2 class="sna-h2">What's next</h2>
<p>The <code>$g</code> bridge and <code>SSEStream</code> will eventually be retired. Each SSE endpoint can become a clean ZealPHP route with native <code>$response->sse()</code>. Each page handler can take <code>$request</code> and <code>$response</code> as parameters instead of reaching into <code>$g</code>.</p>
<p>There is also a framework-native path worth exploring for the longer term: <a href="/coroutines#lifecycle-modes"><code>App::mode(App::MODE_COROUTINE_LEGACY)</code></a>. This preset (requires <strong>ext-zealphp</strong>) is designed exactly for the problem this migration solved — running legacy superglobal request-style PHP concurrently. It isolates the seven superglobals, <code>$GLOBALS</code>, function-local <code>static</code> variables, and <code>require_once</code> re-execution per coroutine at the scheduler level, so code that was written for Apache's process-per-request model runs safely under OpenSwoole concurrency without a bespoke <code>$g</code> shim. The bespoke shim we built is the right solution for the dual-runtime (Apache + ZealPHP simultaneously) case — <code>coroutine-legacy</code> is the right solution once Apache is retired.</p>
<p>But that's optimization, not migration. The migration is done <strong>on dev</strong> — the old code works, the new server works, both serve the same files. Production deployment is the next milestone.</p>
<p class="sna-closing">Sometimes the best migration is the one where you don't have to choose.</p>

<hr class="sna-hr">
<p class="sna-note-sm">
  Built at <a href="https://labs.selfmade.ninja">Selfmade Ninja Labs</a> — an educational platform where students learn by doing. The ZealPHP framework is open source at <a href="https://github.com/sibidharan/zealphp">github.com/sibidharan/zealphp</a>.
</p>

</div>
</section>

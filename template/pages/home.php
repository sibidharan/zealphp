<?php
use ZealPHP\App;
use function ZealPHP\site_url;

$siteUrl = site_url();
?>

<!-- Hero -->
<section class="hero">
  <div class="container">
    <div class="zeal-mark">
      <span class="zeal-bolt" aria-hidden="true">⚡</span>
      <h1 data-text="ZealPHP">Zeal<span>PHP</span></h1>
    </div>
    <p class="home-hero-tagline">
      The PHP Runtime for AI Web Apps</p>
    <p>PHP <em>is</em> the HTTP server now &mdash; not a CGI worker behind one.<br>
       WebSocket, SSE, streaming, coroutines, shared memory, task workers &mdash;
       first&#8209;class because the server never shuts down between requests.</p>
    <p class="home-hero-sub">Bring your existing PHP code. New features go async without a separate Node or Go service.</p>
    <p class="home-hero-stamp">Alpha &middot; v0.4.0 &middot; built on <a href="https://openswoole.com/" target="_blank" rel="noopener">OpenSwoole</a></p>
    <div class="cta">
      <a href="/getting-started" class="btn btn-primary">
        <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg>
        Get Started →</a>
      <a href="/docs/" class="btn btn-outline">
        <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg>
        Browse Docs →</a>
      <a href="/why-zealphp" class="btn btn-outline">
        <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/></svg>
        Why ZealPHP? →</a>
      <a href="https://deepwiki.com/sibidharan/zealphp" class="btn btn-outline" target="_blank" rel="noopener">
        <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .962 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.962 0z"/><path d="M20 3v4"/><path d="M22 5h-4"/><path d="M4 17v2"/><path d="M5 18H3"/></svg>
        Ask DeepWiki ↗</a>
    </div>
    <p class="home-hero-why">
      <a href="/why-zealphp">Why?</a> covers the problem PHP-FPM can't solve, where ZealPHP fits vs Laravel Octane / FrankenPHP / RoadRunner, and when it's the wrong choice.
    </p>
    <!-- Streaming code demo -->
    <div class="hero-demo">
      <div class="hero-demo-code">
        <span class="code-label">app.php — stream AI tokens</span>
<pre class="home-hero-code-pre"><code class="language-php">$app-&gt;route('/ai/chat', function($response) {
    $response-&gt;sse(function($emit) {
        $tokens = call_ai_api($prompt);
        foreach ($tokens as $token) {
            $emit($token, 'token');
        }
    });
});</code></pre>
      </div>
      <div class="hero-demo-output">
        <span class="code-label">Browser output</span>
        <div id="hero-stream-output"></div>
      </div>
    </div>

    <p class="home-bench-intro">
      With 4 HTTP workers, full PSR-15 stack &mdash; <strong>117k req/s text &middot; 106k JSON &middot; 50k templated</strong>, 0 failures across 150k requests.
      Reproduce in 60s: <code class="home-bench-method-code">scripts/bench_vs_express.sh</code>.
      Full concurrency sweep, latency percentiles, methodology, and caveats &mdash;
      <a href="/performance">/performance</a>.
    </p>
  </div>
</section>

<!-- The Problem -->
<section class="section section-problem">
  <div class="container">
    <h2 class="section-title">The PHP we love. The execution model we needed.</h2>
    <p class="section-desc">
      For 25 years, the HTTP server was C. PHP was the worker that died.
      Apache + mod_php, nginx + PHP-FPM &mdash; the HTTP server is always a C binary that bridges to
      a PHP process via FastCGI. PHP runs the request, then exits the request context. PHP is the
      <em>language</em>, never the <em>server</em>. That model gave us shared-nothing isolation,
      cheap workers, and ~71% of the web (per <a href="https://w3techs.com/technologies/details/pl-php" target="_blank" rel="noopener">W3Techs</a>). It also gave us "PHP can't do WebSockets" and a separate
      Node service for every streaming feature.
    </p>
    <p class="section-desc section-problem-payoff">
      <strong>ZealPHP is what happens when PHP <em>becomes</em> the HTTP server.</strong>
      Always-on, coroutine-native, owns the event loop, holds the connections. WebSocket, SSE,
      timers, and shared memory are first-class because the server never shuts down. The on-ramp
      is real too &mdash; <code>session_start()</code>, <code>header()</code>, <code>$_GET</code>,
      <code>echo</code> all route through <a href="https://github.com/sibidharan/zealphp/tree/master/ext/zealphp" target="_blank" rel="noopener">ext-zealphp</a>
      (our own C extension) into per-request state, so existing PHP code runs unchanged.
    </p>
  </div>
</section>

<!-- Live AI Chat Demo -->
<section class="section section-darkbg">
  <div class="container">
    <h2 class="section-title">Try it — live AI chat, streaming on this server</h2>
    <p class="section-desc">Wired for the <strong>OpenAI Agents SDK</strong> &mdash; an agent with tool use, streamed token-by-token over ZealPHP SSE. The live demo runs in <em>demo-fallback</em> mode when no API key is configured (check <a href="/api/chat/status">/api/chat/status</a>); the production deploy flips <code>OPENAI_API_KEY</code> and the same code path streams real model tokens.</p>
    <div class="chat-widget">
      <div class="chat-header">
        <span>ZealPHP AI Chat Demo</span>
        <span class="chat-status" id="chat-status">Checking...</span>
      </div>
      <div class="chat-messages" id="chat-messages">
        <div class="chat-msg assistant">
          <div class="chat-msg-bubble">Hi! I'm running on ZealPHP's SSE streaming. Ask me anything — watch the tokens stream in real-time.</div>
        </div>
      </div>
      <div class="chat-input-row">
        <input type="text" class="chat-input" id="chat-input" placeholder="Type a message..." autocomplete="off">
        <button class="chat-send" id="chat-send" onclick="chatSend()">Send</button>
      </div>
      <div class="chat-source-toggle">
        <a onclick="document.getElementById('chat-source').classList.toggle('open')">View source code →</a>
        <span class="home-chat-source-hint">The full backend powering this chat</span>
      </div>
      <div class="chat-source" id="chat-source">
        <div class="chat-source-tabs">
          <button class="chat-source-tab active" onclick="chatSourceTab(this, 'chat-src-python')">Python — Agent</button>
          <button class="chat-source-tab" onclick="chatSourceTab(this, 'chat-src-php')">PHP — SSE Proxy</button>
        </div>
<pre class="chat-src-panel" id="chat-src-python"><code># examples/agents/chat_agent.py
from agents import Agent, Runner, function_tool, SQLiteSession

@function_tool
def get_zealphp_reference(query: str) -> str:
    """Look up ZealPHP docs — routing, streaming, store, etc."""
    return match_sections(reference, query)

agent = Agent(
    name="ZealPHP Assistant",
    model="gpt-4.1-mini",
    instructions="You are a ZealPHP expert. Output raw HTML.",
    tools=[get_zealphp_reference],
)

# Persistent conversation threads via SQLiteSession
session = SQLiteSession(db_path=DB_PATH, session_id=thread_id)

# Stream tokens as SSE events to stdout
result = Runner.run_streamed(agent, input=message, session=session)
async for event in result.stream_events():
    if event.data.type == "response.output_text.delta":
        print(f"data: {json.dumps({'token': event.data.delta})}")</code></pre>
<pre class="chat-src-panel home-chat-src-hidden" id="chat-src-php"><code>// route/chat.php
$app->route('/api/chat', ['methods' => ['POST']],
  function($request, $response) {
    $g = G::instance();
    $input = json_decode(
        $g->zealphp_request->parent->getContent(), true
    );

    // SSE stream — proxy the Python agent's output.
    // App::exec() shells out coroutine-safely: it yields
    // the reactor while the agent runs, so the worker keeps
    // serving other requests instead of blocking.
    $response->sse(function($emit) use ($input) {
        $cmd = 'uv run chat_agent.py '
             . base64_encode(json_encode($input));
        $result = App::exec($cmd);

        foreach (explode("\n", $result['output']) as $line) {
            if (str_starts_with($line, 'data: '))
                $emit(substr($line, 6), 'token');
        }
    });
});</code></pre>
      </div>
    </div>
  </div>
</section>

<!-- What being-the-HTTP-server actually buys you -->
<section class="section home-section-altbg">
  <div class="container">
    <h2 class="section-title">What being the HTTP server actually buys you</h2>
    <p class="section-desc">SSE, WebSocket, shared memory, timers &mdash; not bolt-ons. First-class because the server is alive between requests.</p>

    <div class="bold-claim">
      <h3>SSE / token streaming as a first-class response primitive</h3>
      <p>The server holds the connection. <code>$response-&gt;sse()</code> is the framework primitive &mdash; no framing, no transports library, no separate sidecar.</p>
      <div class="code-compare">
        <div class="code-compare-panel">
          <div class="compare-label">app.php</div>
<pre><code>$app->route('/ai/stream', function($response) {
    $response->sse(function($emit) {
        foreach (stream_from_upstream() as $token) {
            $emit($token, 'token');
        }
    });
});</code></pre>
        </div>
        <div class="code-compare-panel">
          <div class="compare-label">FPM equivalent</div>
<pre><code>// In an FPM world, the same endpoint pins a worker for
// the lifetime of the stream — the request-per-process
// model has no concept of "yield while waiting on I/O."
// Most teams solve this by running a separate Node or
// Go service just for streaming endpoints.
// ZealPHP does it in the same process as the rest of
// your app, in a coroutine instead of a pinned worker.</code></pre>
        </div>
      </div>
    </div>

    <div class="bold-claim">
      <h3>Routing &amp; auto-serialization with the LAMP idiom intact</h3>
      <p>Reflection-based parameter injection. Return whatever shape fits &mdash; int becomes a status, array becomes JSON, generator becomes a stream. No DI container, no <code>$request</code>-first convention. The <a href="/responses#return-contract">universal return contract</a> is one table.</p>
      <div class="code-compare">
        <div class="code-compare-panel">
          <div class="compare-label">Return anything &mdash; framework picks the right wire shape</div>
<pre><code>$app->route('/users/{id}', function($id) {
    return ['user' => User::find($id)];  // auto JSON, 200
});

$app->route('/missing', fn() => 404);    // int → status

$app->route('/page', fn() => (function() {
    yield '&lt;html&gt;&lt;body&gt;';                 // generator → SSR stream
    yield '&lt;div&gt;...&lt;/div&gt;';
    yield '&lt;/body&gt;&lt;/html&gt;';
})());</code></pre>
        </div>
        <div class="code-compare-panel">
          <div class="compare-label">Same return contract for legacy public/*.php files</div>
<pre><code>// public/users.php — Apache-style file routing,
// same return contract as $app->route() handlers.
&lt;?php
require_once __DIR__ . '/../vendor/autoload.php';
return ['users' => User::all()];  // → JSON, 200

// public/old-handler.php — buffered echo still works
// for unmodified legacy code.
&lt;?php session_start();
echo "&lt;h1&gt;Hello, {$_SESSION['user']}&lt;/h1&gt;";</code></pre>
        </div>
      </div>
    </div>

    <div class="bold-claim">
      <h3>Workers + coroutines + shared state in one PHP application server</h3>
      <p>OpenSwoole's master/manager/worker model: <code>N</code> worker processes, each running thousands of coroutines on a reactor loop. <a href="https://openswoole.com/" target="_blank" rel="noopener">OpenSwoole</a> is the runtime; ZealPHP is the framework layer on top. Cross-worker state ships built-in for one machine; cross-node uses the same API with a Redis backend.</p>
      <div class="code-compare">
        <div class="code-compare-panel">
          <div class="compare-label">Cross-worker state on a single node &mdash; built in</div>
<pre><code>// N workers × thousands of coroutines per worker.
// Coroutines yield on I/O automatically (HOOK_ALL).
ZEALPHP_WORKERS=16 php app.php

// Store: cross-worker shared state, in-process.
// No Redis needed for one node — OpenSwoole\Table.
Store::set('cache', $key, $data);
$data = Store::get('cache', $key);</code></pre>
        </div>
        <div class="code-compare-panel">
          <div class="compare-label">Cross-node &mdash; same API, Redis/Valkey backend</div>
<pre><code>// Multi-node deploy? Same code, one-line backend flip.
// Federated WebSocket rooms + pub/sub need Redis;
// in-memory Table can't reach across machines.
Store::defaultBackend(Store::BACKEND_REDIS);

Store::publish('chat:room', $payload);
App::subscribe('chat:room', $handler);

// Or run TIERED: L1 = local Table, L2 = Redis,
// HMAC-signed cross-node L1 invalidations.
Store::defaultBackend(Store::BACKEND_TIERED);</code></pre>
        </div>
      </div>
    </div>

  </div>
</section>

<!-- Quick start -->
<section class="section home-section-darkbg">
  <div class="container">

    <!-- TL;DR install — fresh-box one-liner before the scaffold/clone/wordpress tabs.
         Surfaces the curl|bash path for visitors who haven't installed PHP/OpenSwoole
         at all yet; the tabbed scaffold below assumes those deps already exist. -->
    <div class="home-qs-fresh">
      <div class="home-qs-fresh-head">
        <span class="home-qs-fresh-bolt">⚡</span>
        <strong>Fresh machine? One line installs PHP 8.3 + OpenSwoole + ext-zealphp + composer:</strong>
      </div>
      <div class="qs-block home-qs-fresh-block">
        <div class="qs-line home-qs-fresh-line">
          <span class="home-qs-fresh-prompt">$</span>
          <span class="home-qs-fresh-cmd">curl -fsSL https://php.zeal.ninja/install.sh | sudo bash</span>
          <button class="qs-copy home-qs-fresh-copy" data-copy="curl -fsSL https://php.zeal.ninja/install.sh | sudo bash">copy</button>
        </div>
      </div>
      <p class="home-qs-fresh-note">
        Ubuntu/Debian/WSL2 · macOS · auto-detects unsupported distros and prints manual steps · <a href="/getting-started#install">inspect first</a>
      </p>
    </div>

    <div class="home-qs-header">
      <div>
        <h2>Quick Start</h2>
        <p>PHP installed? From zero to running server in 60 seconds.</p>
      </div>
      <div class="qs-tabs">
        <button class="qs-tab active" data-tab="starter" onclick="qsTab('starter')">Starter Project</button>
        <button class="qs-tab" data-tab="framework" onclick="qsTab('framework')">Framework Repo</button>
        <button class="qs-tab" data-tab="wordpress" onclick="qsTab('wordpress')">WordPress</button>
      </div>
    </div>

    <div class="qs-panel active" data-panel="starter">
      <div class="qs-block">
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> composer create-project sibidharan/zealphp-project:^0.4.0 my-app</span><button class="qs-copy" data-copy="composer create-project sibidharan/zealphp-project:^0.4.0 my-app">copy</button></div>
        <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd my-app && php app.php</span><button class="qs-copy" data-copy="cd my-app && php app.php">copy</button></div>
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">Server running at <code class="qs-out-code">http://localhost:8080</code></span></div>
      </div>
      <div class="qs-note">Includes CLAUDE.md for AI-assisted development. Restart with <code>php app.php</code> after editing routes.</div>
    </div>

    <div class="qs-panel" data-panel="framework">
      <div class="qs-block">
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> git clone https://github.com/sibidharan/zealphp.git</span><button class="qs-copy" data-copy="git clone https://github.com/sibidharan/zealphp.git">copy</button></div>
        <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd zealphp && composer install && php app.php</span><button class="qs-copy" data-copy="cd zealphp && composer install && php app.php">copy</button></div>
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">This very site, running locally at <code class="qs-out-code">http://localhost:8080</code></span></div>
      </div>
      <div class="qs-note">The framework repo IS the OSS website — every page is a live, working example of a feature.</div>
    </div>

    <div class="qs-panel" data-panel="wordpress">
      <div class="qs-block">
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> git clone https://github.com/sibidharan/zealphp-wordpress.git</span><button class="qs-copy" data-copy="git clone https://github.com/sibidharan/zealphp-wordpress.git">copy</button></div>
        <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd zealphp-wordpress && composer install</span><button class="qs-copy" data-copy="cd zealphp-wordpress && composer install">copy</button></div>
        <div class="qs-line"><span class="qs-num">3</span><span class="qs-cmd"><span class="qs-prompt">$</span> php app.php</span><button class="qs-copy" data-copy="php app.php">copy</button></div>
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">WordPress at <code class="qs-out-code">http://localhost:9501</code> — front page, login, REST API working — admin dashboard has known limits</span></div>
      </div>
      <div class="qs-note">Zero WordPress modifications. CGI worker provides Apache mod_php compatibility. See <a href="/legacy-apps">Legacy Apps</a>.</div>
    </div>

    <div class="qs-prereq">
      <span class="qs-prereq-label">Requires</span>
      <code>PHP 8.3+</code>
      <code>OpenSwoole 22.1+</code>
      <code>ext-zealphp</code>
      <code>composer</code>
      <a href="/getting-started" class="qs-prereq-link">Install help →</a>
    </div>
  </div>
</section>


<!-- Engine vs harness — pre-empts the "just use Swoole" attack -->
<section class="section home-section-engine">
  <div class="container">
    <h2 class="section-title">OpenSwoole is the engine. ZealPHP is the harness.</h2>
    <p class="section-desc home-engine-lead">
      <a href="https://openswoole.com/" target="_blank" rel="noopener">OpenSwoole</a> gives PHP a real long-lived HTTP/WebSocket server with native coroutines, Atomic, and Table. It's the C-extension that makes everything else possible. But raw OpenSwoole leaves the framework problem unsolved &mdash; routing, middleware, sessions, legacy-PHP compatibility, return-shape resolution, CLI tooling. Every team that builds on raw OpenSwoole re-invents the same harness.
    </p>
    <div class="home-engine-grid">
      <div class="home-engine-col home-engine-col-engine">
        <h3 class="home-engine-col-title">OpenSwoole gives you</h3>
        <ul class="home-engine-list">
          <li>HTTP server + WebSocket server primitives</li>
          <li>Coroutines + Channel + WaitGroup</li>
          <li><code>Atomic</code> + <code>Table</code> (shared memory)</li>
          <li>Coroutine\Http\Client + DNS + sleep hooks</li>
          <li><code>HOOK_ALL</code> &mdash; PHP I/O yields the reactor</li>
          <li>Process\Pool + master/manager/worker lifecycle</li>
        </ul>
      </div>
      <div class="home-engine-col home-engine-col-harness">
        <h3 class="home-engine-col-title">ZealPHP adds on top</h3>
        <ul class="home-engine-list">
          <li>Routing (<code>route()</code> + <code>nsRoute</code> + <code>patternRoute</code>) with reflection-based parameter injection</li>
          <li>PSR-15 middleware stack &mdash; 28 built-ins covering common Apache/nginx behaviors</li>
          <li><code>ext-zealphp</code> overrides so <code>session_start()</code>, <code>header()</code>, <code>setcookie()</code>, <code>$_GET</code>/<code>$_POST</code>/<code>$_SESSION</code>, <code>echo</code> all just work</li>
          <li>Coroutine-safe superglobals &mdash; <code>$_GET</code>, <code>$_POST</code>, <code>$_SESSION</code> are per-coroutine with ext-zealphp. Legacy code + coroutine concurrency in one process (per-coroutine request-state isolation; classic pure-<code>require_once</code> apps with no autoloader run on <code>legacy-cgi</code>).</li>
          <li>Templating (<code>App::render</code> / <code>renderStream</code> / <code>fragment</code>) with streaming-Generator output</li>
          <li>Universal return contract (int = status, array = JSON, Generator = SSE/SSR stream)</li>
          <li>ZealAPI &mdash; file-based REST (drop <code>api/device/list.php</code> &rarr; <code>/api/device/list</code> auto-route)</li>
          <li>CGI worker bridge for unmodified WordPress / Drupal compatibility</li>
          <li>Pluggable <code>Store</code> + <code>Counter</code> backends (Table &rarr; Redis/Valkey &rarr; Tiered with HMAC-signed cross-node L1 invalidation)</li>
          <li>Cross-host pub/sub (<code>App::subscribe</code>) + Streams (<code>App::subscribeReliable</code>) + WSRouter for cross-server WebSocket routing + first-class WS rooms</li>
          <li>Stream wrapper for <code>php://input</code> so legacy <code>file_get_contents('php://input')</code> just works in long-running workers</li>
          <li>CLI tooling: <code>php app.php start/stop/restart/status/logs</code> + daemonization + per-port PID files</li>
        </ul>
      </div>
    </div>
    <p class="home-engine-when-raw">
      <strong>When raw OpenSwoole is the right choice:</strong> you're building a custom binary-protocol server (your own ASR / database / message broker), you can't install custom extensions (locked-down host), you're building your own framework. For everything else &mdash; HTTP, WebSocket, SSE, REST, web apps with sessions &mdash; the harness saves you weeks per project.
    </p>
  </div>
</section>

<!-- AI-friendly by being boring -->
<section class="section home-section-ai-friendly">
  <div class="container">
    <h2 class="section-title">AI-friendly by being boring</h2>
    <p class="section-desc">
      ZealPHP doesn't require a heavy frontend stack to build interactive AI apps.
      Routes can return HTML. SSE can stream tokens. WebSockets can push live events.
      Task workers can run long jobs. Templates stay close to backend state.
      That smaller surface area &mdash; HTML as the interface contract, fewer files between
      handler and DOM &mdash; makes the app easier to understand, test, and modify, for humans
      and for AI coding assistants.
    </p>
    <p class="section-desc home-ai-friendly-note">
      It's an architectural note, not a product claim: less architecture is often more accuracy.
      Build small explicit server flows; let the browser stay browser-shaped.
      Worked example: the live AI chat above is ~40 lines of PHP + a Python agent,
      no SPA framework involved.
    </p>
  </div>
</section>

<!-- Feature grid -->
<section class="section">
  <div class="container">
    <h2 class="section-title">Everything you need</h2>
    <p class="section-desc">Every feature is a live running example — click any card to explore.</p>
    <div class="feature-grid">
      <?php
      $features = [
        ['⚡', 'Routing',      'Flask-style routes with reflection-based injection. Zero config, zero boilerplate.',                  '/routing',    'route()'],
        ['📦', 'Responses',    'Return int → status, array → JSON, Generator → stream. Framework does the right thing.',             '/responses',  'auto-serialize'],
        ['🔀', 'Coroutines',   'Fan out to multiple AI models in parallel. Merge responses. go() + Channel, zero callback hell.',     '/coroutines', 'go() + Channel'],
        ['📡', 'Streaming',    'Stream AI tokens as they generate. yield is your streaming primitive. SSR, SSE, stream() built-in.', '/streaming',  'yield · SSE'],
        ['🔌', 'WebSocket',    'Real-time agent-to-user comms. Multi-user AI sessions, live collaboration, binary frames.',           '/ws',         'App::ws()'],
        ['🛡️', 'Middleware',  'CORS, ETag/304, gzip. PSR-15 compatible — drop in any middleware package.',                            '/middleware', 'PSR-15'],
        ['🗄️', 'Sessions',   'Coroutine-safe sessions. Your existing session_start() code just works via ext-zealphp.',                     '/sessions',   'drop-in'],
        ['🗃️', 'Store',      'Cross-worker shared state on one node via OpenSwoole\\Table; flip to Redis/Valkey for cross-node + persistence. One API.',  '/store',      'pluggable backend'],
        ['⏱️', 'Timers',     'Schedule recurring AI tasks. Polling, cleanup, model warmup, health checks.',                           '/timers',     'tick() · after()'],
        ['🌐', 'HTTP',        'Full HTTP/1.1 compliance. HEAD, OPTIONS, Range, redirects, CORS, ETag, gzip — all built-in.',           '/http',       'HTTP/1.1'],
        ['📝', 'Components',  'SSR streaming components. Compose views with yield from. renderStream() for progressive HTML.',         '/templates',  'renderStream()'],
        ['🔗', 'REST API',    'Drop a PHP file in api/. It becomes a route. File-based REST — the simplest API pattern.',             '/api',        'file-based'],
        ['🏗️', 'Legacy Apps','50-app compatibility sweep: WordPress, Joomla, Kanboard, Roundcube, OpenCart, Adminer + 44 more. 5 apps pass ALL 4 modes unmodified.', '/legacy-apps#50-app-sweep','50-app sweep'],
      ];
      foreach ($features as [$icon, $title, $body, $href, $badge]) {
        App::render('/components/_card', compact('icon', 'title', 'body', 'href', 'badge'));
      }
      ?>
    </div>
  </div>
</section>

<!-- Bring your PHP code along -->
<section class="section home-section-migrate">
  <div class="container home-migrate-wrap">
    <h2 class="section-title home-migrate-title">Bring your PHP code along</h2>
    <p class="section-desc home-migrate-desc">
      Many traditional PHP patterns run unchanged in compatibility mode. <code>session_start()</code>,
      <code>header()</code>, <code>$_GET</code>, <code>$_POST</code>, <code>echo</code>, <code>setcookie()</code>
      &mdash; all routed through <code>ext-zealphp</code> overrides into per-request state, so existing files
      can sit beside coroutine-native routes in the same app. Compatibility is a migration on-ramp,
      not a guarantee that every PHP application is safe to drop in without an audit.
    </p>

    <div class="home-migrate-grid">
      <div class="home-migrate-card home-migrate-card-today">
        <h3>Today: nginx + PHP-FPM + Redis + Socket.io + cron + &hellip;</h3>
        <p>Each tier is mature in isolation, but the per-feature wiring (sessions, real-time, background jobs) lives across several services and config files.</p>
      </div>
      <div class="home-migrate-card home-migrate-card-accent">
        <h3>On ZealPHP: <code>php app.php</code></h3>
        <p>HTTP, WebSocket, SSE, sessions, task workers, shared memory, timers &mdash; one PHP application server. Front it with nginx / Caddy / Traefik in production for TLS + load-balancing across instances, exactly as you would an FPM pool.</p>
      </div>
    </div>

    <p class="home-migrate-ladder">
      The <a href="/migration">migration ladder</a> has 5 rungs (0&nbsp;&rarr;&nbsp;4). Rung 0 is &ldquo;drop your existing app in a fallback handler.&rdquo; Rung 4 is full coroutine mode (<a href="/performance">measured throughput on /performance</a>). Most teams stay on rungs 1&ndash;3 indefinitely; the upgrade path is opt-in, not forced. Real-world fit depends on extension coverage, blocking-I/O usage, and how much of the app reaches for global state &mdash; <a href="/why-zealphp#when-to-use-zealphp">honest fit guide</a>.
    </p>

    <div class="home-migrate-cta">
      <a href="/migration" class="btn btn-primary">See the full migration path →</a>
      <a href="/legacy-apps" class="btn btn-outline home-migrate-cta-wp">WordPress compatibility showcase →</a>
    </div>
  </div>
</section>

<!-- Return conventions -->
<section class="section home-section-return">
  <div class="container">
    <h2 class="section-title">Return anything, get the right response</h2>
    <p class="section-desc">ZealPHP inspects your return type and does the right thing — no boilerplate. One contract for every entry point (route handler, public file, API closure, fallback, error handler, <code>App::render() / renderToString() / renderStream() / include()</code>).</p>
    <table class="ztable home-return-table">
      <tr><th class="home-return-col-return">Return</th><th class="home-return-col-result">Result</th><th>Example</th></tr>
      <tr><td><code>int</code></td><td>HTTP status code</td><td><code>return 404;</code> <code>return 201;</code></td></tr>
      <tr><td><code>array</code> / <code>object</code></td><td>Auto-serialized as JSON</td><td><code>return ['users' => $list];</code></td></tr>
      <tr><td><code>string</code></td><td>HTML body</td><td><code>return '&lt;h1&gt;Hello&lt;/h1&gt;';</code></td></tr>
      <tr><td><code>Generator</code></td><td>SSR streaming (each yield sent immediately)</td><td><code>yield '&lt;head&gt;'; yield $body;</code></td></tr>
      <tr><td><code>void</code> + <code>echo</code></td><td>Buffered output via <code>ob_get_clean()</code></td><td><code>echo "Hello"; echo " World";</code></td></tr>
      <tr><td><code>ResponseInterface</code></td><td>PSR-7 response used directly</td><td><code>return new Response(...);</code></td></tr>
    </table>
    <p class="home-return-foot"><a href="/responses#return-contract">Full contract reference →</a></p>
  </div>
</section>


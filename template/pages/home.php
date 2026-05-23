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
    <p>Stream AI responses in 5 lines. WebSocket, SSE, shared memory, task workers —<br>
       one server, one process. Coroutine-native concurrency with PHP's developer experience.</p>
    <p class="home-hero-sub">Upgrade your existing PHP codebase to async — start without rewriting, migrate at your own pace.</p>
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
      And it's fast — here's the throughput on 4 workers, full middleware stack:
    </p>

    <div class="bench-method">
      <strong>Method</strong> &nbsp;|&nbsp;
      4 workers, full middleware stack, <code class="home-bench-method-code">ab -n 50000 -c 200 -k</code>, same machine, no DB
      &nbsp;|&nbsp;
      <a href="https://github.com/sibidharan/zealphp/blob/master/PERF.md" target="_blank" rel="noopener">PERF.md</a>
      &nbsp;|&nbsp;
      <a href="https://github.com/sibidharan/zealphp/blob/master/scripts/bench_vs_express.sh" target="_blank" rel="noopener">reproduce locally</a>
    </div>
    <div class="bench">
      <div class="bench-stat"><div class="num">117k</div><div class="label">req/s text</div><div class="sub">avg 1.7 ms</div></div>
      <div class="bench-stat"><div class="num">106k</div><div class="label">req/s JSON</div><div class="sub">avg 1.9 ms</div></div>
      <div class="bench-stat"><div class="num">50k</div><div class="label">req/s template</div><div class="sub">avg 4.0 ms</div></div>
      <div class="bench-stat"><div class="num">0</div><div class="label">failures</div><div class="sub">/ 150k reqs</div></div>
    </div>
    <div class="home-bench-table-wrap">
      <table class="home-bench-table">
        <tr class="home-bench-head">
          <th class="home-bench-th-left">Framework</th>
          <th>Raw text</th>
          <th>JSON API</th>
          <th>Template</th>
        </tr>
        <tr class="home-bench-group">
          <td colspan="4" class="home-bench-group-label">Runtime (no framework, no middleware)</td>
        </tr>
        <tr>
          <td>OpenSwoole raw</td>
          <td class="home-bench-num">142k</td>
          <td class="home-bench-num">138k</td>
          <td class="home-bench-num home-bench-dash">—</td>
        </tr>
        <tr>
          <td>Node.js raw http</td>
          <td class="home-bench-num">129k</td>
          <td class="home-bench-num">132k</td>
          <td class="home-bench-num home-bench-dash">—</td>
        </tr>
        <tr class="home-bench-group">
          <td colspan="4" class="home-bench-group-label">Full framework (CORS + ETag + sessions + routing + templates)</td>
        </tr>
        <tr class="home-bench-row-zeal">
          <td class="home-bench-zeal">ZealPHP <span class="home-bench-tag">built-in</span></td>
          <td class="home-bench-num home-bench-zeal">117k</td>
          <td class="home-bench-num home-bench-zeal">106k</td>
          <td class="home-bench-num home-bench-zeal">50k</td>
        </tr>
        <tr class="home-bench-row-express">
          <td class="home-bench-express">Express.js <span class="home-bench-tag">+5 npm pkgs</span></td>
          <td class="home-bench-num home-bench-express">20k</td>
          <td class="home-bench-num home-bench-express">22k</td>
          <td class="home-bench-num home-bench-express">12k</td>
        </tr>
        <tr class="home-bench-group">
          <td colspan="4" class="home-bench-group-label">Other PHP frameworks <span class="home-bench-group-note">(community benchmarks)</span></td>
        </tr>
        <tr class="home-bench-row-other">
          <td class="home-bench-other-name">Slim 4</td>
          <td colspan="3" class="home-bench-other-num">~4k req/s</td>
        </tr>
        <tr class="home-bench-row-other">
          <td class="home-bench-other-name">Symfony 7</td>
          <td colspan="3" class="home-bench-other-num">~2k req/s</td>
        </tr>
        <tr class="home-bench-row-other">
          <td class="home-bench-other-name">Laravel 11</td>
          <td colspan="3" class="home-bench-other-num">~500 req/s</td>
        </tr>
      </table>
      <div class="home-bench-callout">
        <p>
          <strong>The runtime is already faster.</strong>
          OpenSwoole's bare HTTP server hits <strong>142k req/s text · 138k JSON</strong>
          — versus Node's <strong class="home-bench-callout-alt">129k · 132k</strong>.
          <strong>+10% on text, +5% on JSON</strong>, before any framework loads.
        </p>
        <p class="home-bench-callout-last">
          <strong>ZealPHP keeps 82%</strong> of that with full PSR-15 middleware on top.
          <strong class="home-bench-callout-alt">Express keeps 15%</strong> of raw Node's.
          End result — ZealPHP with full middleware reaches
          <strong>91% of bare Node http's throughput</strong>.
        </p>
      </div>
      <p class="home-bench-perflink">
        <a href="/performance">Concurrency sweep, latency percentiles, methodology, reproduction recipes &amp; caveats →</a>
      </p>
    </div>
  </div>
</section>

<!-- The Problem -->
<section class="section section-problem">
  <div class="container">
    <h2 class="section-title">The PHP we love. The execution model we needed.</h2>
    <p class="section-desc">
      LAMP shipped the web &mdash; Apache and mod_php, now nginx and PHP-FPM. Twenty-five years of
      request isolation: a pool of warm workers, each handling one request at a time and reset
      to a clean slate before the next. Shared-nothing by design.
    </p>
    <p class="section-desc">
      It still works. But it can't stream AI tokens. It can't push WebSocket events.
      It can't share state between requests without bolting on Redis. Every
      &ldquo;real-time&rdquo; feature your customers ask for needs another service.
    </p>
    <p class="section-desc section-problem-payoff">
      <strong>ZealPHP keeps the PHP. Swaps the execution model.</strong>
      One process, coroutines, persistent state &mdash; and your existing PHP codebase still runs.
    </p>
  </div>
</section>

<!-- Live AI Chat Demo -->
<section class="section section-darkbg">
  <div class="container">
    <h2 class="section-title">Try it — live AI chat, streaming on this server</h2>
    <p class="section-desc">Powered by the <strong>OpenAI Agents SDK</strong> + ZealPHP SSE streaming. Multi-agent with tool use, streamed token-by-token.</p>
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

    // SSE stream — proxy Python agent's stdout
    $response->sse(function($emit) use ($input) {
        $cmd = 'uv run chat_agent.py '
             . base64_encode(json_encode($input));
        $process = proc_open($cmd, [
            0 => ['pipe','r'],
            1 => ['pipe','w'],
            2 => ['pipe','w'],
        ], $pipes);

        while (!feof($pipes[1])) {
            $line = fgets($pipes[1]);
            if (str_starts_with($line, 'data: '))
                $emit(substr($line, 6), 'token');
        }
        proc_close($process);
    });
});</code></pre>
      </div>
    </div>
  </div>
</section>

<!-- Why Not Just Use [X]? -->
<section class="section home-section-altbg">
  <div class="container">
    <h2 class="section-title">Why not just use...?</h2>
    <p class="section-desc">Bold claims. Real code. You decide.</p>

    <div class="bold-claim">
      <h3>Node.js needs 30 lines for what ZealPHP does in 5</h3>
      <p>AI token streaming — the core feature of every LLM app. Compare the implementations.</p>
      <div class="code-compare">
        <div class="code-compare-panel">
          <div class="compare-label">ZealPHP — 7 lines</div>
<pre><code>$app->route('/ai/stream', function($response) {
    $response->sse(function($emit) {
        $ch = curl_init($apiUrl);
        // ... setup curl streaming
        curl_exec($ch);
    });
});</code></pre>
        </div>
        <div class="code-compare-panel">
          <div class="compare-label">Node.js — 25+ lines</div>
<pre><code>app.get('/ai/stream', (req, res) => {
  res.setHeader('Content-Type', 'text/event-stream');
  res.setHeader('Cache-Control', 'no-cache');
  res.setHeader('Connection', 'keep-alive');
  res.flushHeaders();

  const response = await fetch(apiUrl, {
    method: 'POST', body: JSON.stringify({...}),
  });

  const reader = response.body.getReader();
  const decoder = new TextDecoder();

  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    const chunk = decoder.decode(value);
    // parse SSE lines, extract tokens...
    res.write(`data: ${token}\n\n`);
  }
  res.end();
});</code></pre>
        </div>
      </div>
    </div>

    <div class="bold-claim">
      <h3>Expressive PHP with coroutine-grade concurrency</h3>
      <p>~106k req/s in our 4-worker JSON benchmark, with reflection-based injection, auto-serialization, and no boilerplate. Numbers vary by workload — see methodology below.</p>
      <div class="code-compare">
        <div class="code-compare-panel">
          <div class="compare-label">ZealPHP — return anything</div>
<pre><code>$app->route('/users/{id}', function($id) {
    return ['user' => User::find($id)];
    // auto JSON. auto 200. done.
});</code></pre>
        </div>
        <div class="code-compare-panel">
          <div class="compare-label">Go — manual everything</div>
<pre><code>func getUser(w http.ResponseWriter, r *http.Request) {
    id := chi.URLParam(r, "id")
    user, err := FindUser(id)
    if err != nil {
        http.Error(w, err.Error(), 500)
        return
    }
    w.Header().Set("Content-Type", "application/json")
    json.NewEncoder(w).Encode(map[string]any{
        "user": user,
    })
}</code></pre>
        </div>
      </div>
    </div>

    <div class="bold-claim">
      <h3>Multi-process workers, coroutines per worker</h3>
      <p>ZealPHP inherits OpenSwoole's architecture: <code>N</code> worker processes, each running thousands of coroutines on a single reactor loop. <a href="https://openswoole.com/" target="_blank" rel="noopener">OpenSwoole</a> is the runtime; ZealPHP is the framework layer. Real connection counts depend on workload, OS limits, and tuning — measure for your case.</p>
      <div class="code-compare">
        <div class="code-compare-panel">
          <div class="compare-label">ZealPHP — true parallelism</div>
<pre><code>// 16 workers × thousands of coroutines
// Shared memory across workers (no Redis)
// Each coroutine yields on I/O automatically
ZEALPHP_WORKERS=16 php app.php

// Store: cross-worker shared state
Store::set('cache', $key, $data);
$data = Store::get('cache', $key);</code></pre>
        </div>
        <div class="code-compare-panel">
          <div class="compare-label">FastAPI — single process limits</div>
<pre><code># Single process, async but not parallel
# Need Gunicorn + multiple workers
# Need Redis for any shared state
# Need Celery for background tasks
gunicorn app:app -w 4 -k uvicorn.workers.UvicornWorker

# Shared state? Add Redis.
redis_client = redis.Redis()
redis_client.set(key, json.dumps(data))</code></pre>
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
        <strong>Fresh machine? One line installs PHP 8.3 + OpenSwoole + uopz + composer:</strong>
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
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> composer create-project sibidharan/zealphp-project:^0.2.39 my-app</span><button class="qs-copy" data-copy="composer create-project sibidharan/zealphp-project:^0.2.39 my-app">copy</button></div>
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
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">WordPress at <code class="qs-out-code">http://localhost:9501</code> — admin, login, REST API all working</span></div>
      </div>
      <div class="qs-note">Zero WordPress modifications. CGI worker provides Apache mod_php compatibility. See <a href="/legacy-apps">Legacy Apps</a>.</div>
    </div>

    <div class="qs-prereq">
      <span class="qs-prereq-label">Requires</span>
      <code>PHP 8.3+</code>
      <code>OpenSwoole 22.1+</code>
      <code>uopz</code>
      <code>composer</code>
      <a href="/getting-started" class="qs-prereq-link">Install help →</a>
    </div>
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
        ['🗄️', 'Sessions',   'Coroutine-safe sessions. Your existing session_start() code just works via uopz.',                     '/sessions',   'drop-in'],
        ['🗃️', 'Store',      'Share AI conversation state across workers. Cross-worker shared memory — no Redis needed.',             '/store',      'OpenSwoole\\Table'],
        ['⏱️', 'Timers',     'Schedule recurring AI tasks. Polling, cleanup, model warmup, health checks.',                           '/timers',     'tick() · after()'],
        ['🌐', 'HTTP',        'Full HTTP/1.1 compliance. HEAD, OPTIONS, Range, redirects, CORS, ETag, gzip — all built-in.',           '/http',       'HTTP/1.1'],
        ['📝', 'Components',  'SSR streaming components. Compose views with yield from. renderStream() for progressive HTML.',         '/templates',  'renderStream()'],
        ['🔗', 'REST API',    'Drop a PHP file in api/. It becomes a route. File-based REST — the simplest API pattern.',             '/api',        'file-based'],
        ['🏗️', 'Legacy Apps','Run WordPress unmodified. CGI worker provides true global scope. Apache mod_php compatibility.',        '/legacy-apps','WordPress'],
      ];
      foreach ($features as [$icon, $title, $body, $href, $badge]) {
        App::render('/components/_card', compact('icon', 'title', 'body', 'href', 'badge'));
      }
      ?>
    </div>
  </div>
</section>

<!-- Migrate Your PHP Codebase -->
<section class="section home-section-migrate">
  <div class="container home-migrate-wrap">
    <h2 class="section-title home-migrate-title">Bring your PHP codebase along</h2>
    <p class="section-desc home-migrate-desc">
      <code>session_start()</code>, <code>header()</code>, <code>$_GET</code>, <code>echo</code> —
      overridden via uopz so existing code runs unchanged inside the coroutine runtime.
      Move at your own pace: drop your whole app in, or rewrite endpoint-by-endpoint.
    </p>

    <div class="home-migrate-grid">
      <div class="home-migrate-card home-migrate-card-today">
        <h3>Today: Nginx + PHP-FPM + Redis + Socket.io + cron + …</h3>
        <p>6 services, 6 failure points, 6 sets of config.</p>
      </div>
      <div class="home-migrate-card home-migrate-card-accent">
        <h3>On ZealPHP: <code>php app.php</code></h3>
        <p>HTTP + WebSocket + SSE + sessions + shared memory + task workers — one process.</p>
      </div>
    </div>

    <p class="home-migrate-ladder">
      The migration ladder has 5 rungs (0 → 4). Rung 0 is "drop in your existing app, run <code>php app.php</code>." Rung 4 is full coroutine mode — <a href="/performance">117k req/s on 4 workers</a>. Most teams stay on rungs 1–3 indefinitely; the upgrade path is opt-in, not forced.
    </p>

    <div class="home-migrate-cta">
      <a href="/migration" class="btn btn-primary">See the full migration path →</a>
      <a href="/legacy-apps" class="btn btn-outline home-migrate-cta-wp">WordPress on ZealPHP →</a>
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


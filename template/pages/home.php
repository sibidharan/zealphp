<?php
use ZealPHP\App;
use function ZealPHP\site_url;

$siteUrl = site_url();
?>

<!-- Hero -->
<section class="hero">
  <div class="container">
    <h1>Zeal<span>PHP</span></h1>
    <p style="font-size:1.4rem;color:#e0e7ff;font-weight:600;margin:.5rem auto .75rem;position:relative">
      The PHP Runtime for AI Web Apps</p>
    <p>Stream AI responses in 5 lines. WebSocket, SSE, shared memory, task workers —<br>
       one server, one process. Go-level performance, PHP simplicity.</p>
    <div class="cta">
      <a href="/getting-started" class="btn btn-primary">Get Started →</a>
      <a href="https://github.com/sibidharan/zealphp" class="btn btn-outline" target="_blank">GitHub ↗</a>
    </div>
    <div class="oss-badges" aria-label="Project badges">
      <a href="https://deepwiki.com/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://deepwiki.com/badge.svg" alt="Ask DeepWiki">
      </a>
      <a href="https://packagist.org/packages/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/packagist/v/sibidharan/zealphp?style=flat-square" alt="Packagist latest version">
      </a>
      <a href="https://packagist.org/packages/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/packagist/dt/sibidharan/zealphp?style=flat-square" alt="Packagist downloads">
      </a>
      <a href="https://packagist.org/packages/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/packagist/l/sibidharan/zealphp?style=flat-square" alt="MIT license">
      </a>
      <a href="https://github.com/sibidharan/zealphp/stargazers" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/github/stars/sibidharan/zealphp?style=flat-square&logo=github&logoColor=white" alt="GitHub stars">
      </a>
      <a href="https://www.php.net/" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/badge/PHP-8.3.x-777bb4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3.x">
      </a>
      <a href="https://github.com/sibidharan/zealphp/actions/workflows/tests.yml" target="_blank" rel="noopener noreferrer">
        <img src="https://github.com/sibidharan/zealphp/actions/workflows/tests.yml/badge.svg" alt="GitHub Actions test status">
      </a>
      <a href="https://codecov.io/gh/sibidharan/zealphp" target="_blank" rel="noopener noreferrer">
        <img src="https://codecov.io/gh/sibidharan/zealphp/branch/master/graph/badge.svg" alt="Coverage">
      </a>
    </div>

    <!-- Streaming code demo -->
    <div class="hero-demo">
      <div class="hero-demo-code">
        <span class="code-label">app.php — stream AI tokens</span>
<pre style="margin:0"><code class="language-php" style="background:transparent;padding:0;font-size:.82rem">$app-&gt;route('/ai/chat', function($response) {
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

    <div class="bench-note">With full middleware (CORS + ETag + sessions). 4 workers. <code>ab -n 50000 -c 200 -k</code></div>
    <div class="bench">
      <div class="bench-stat"><div class="num">66k</div><div class="label">req/s</div></div>
      <div class="bench-stat"><div class="num">3ms</div><div class="label">avg latency</div></div>
      <div class="bench-stat"><div class="num">4</div><div class="label">workers</div></div>
      <div class="bench-stat"><div class="num">0</div><div class="label">failures</div></div>
    </div>
    <div style="margin-top:1.5rem;position:relative">
      <table style="margin:0 auto;border-collapse:collapse;font-size:.82rem;max-width:660px;width:100%">
        <tr style="border-bottom:1px solid rgba(255,255,255,.1)">
          <th style="text-align:left;padding:.4rem .8rem;color:#94a3b8;font-weight:600">Framework</th>
          <th style="text-align:right;padding:.4rem .8rem;color:#94a3b8;font-weight:600">req/s</th>
          <th style="text-align:right;padding:.4rem .8rem;color:#94a3b8;font-weight:600">vs ZealPHP</th>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.4rem .8rem;color:var(--accent);font-weight:700">ZealPHP <span style="color:#94a3b8;font-weight:400;font-size:.72rem">CORS + ETag + sessions</span></td>
          <td style="padding:.4rem .8rem;text-align:right;color:var(--accent);font-weight:700">66,000</td>
          <td style="padding:.4rem .8rem;text-align:right;color:var(--accent);font-weight:700">1x</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.4rem .8rem;color:#e2e8f0">OpenSwoole raw <span style="color:#64748b;font-size:.72rem">no framework</span></td>
          <td style="padding:.4rem .8rem;text-align:right;color:#e2e8f0">250,000</td>
          <td style="padding:.4rem .8rem;text-align:right;color:#64748b">3.8x</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.4rem .8rem;color:#e2e8f0">Express.js <span style="color:#64748b;font-size:.72rem">cors + etag + session</span></td>
          <td style="padding:.4rem .8rem;text-align:right;color:#e2e8f0">112,000</td>
          <td style="padding:.4rem .8rem;text-align:right;color:#64748b">1.7x</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02)">
          <td colspan="3" style="padding:.5rem .8rem;color:#64748b;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Other PHP frameworks <span style="font-weight:400;text-transform:none;letter-spacing:0">(community benchmarks, similar hardware)</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.35rem .8rem;color:#94a3b8">Slim 4 <span style="color:#64748b;font-size:.72rem">micro-framework</span></td>
          <td style="padding:.35rem .8rem;text-align:right;color:#94a3b8">~4,000</td>
          <td style="padding:.35rem .8rem;text-align:right;color:#10b981;font-weight:600">16x slower</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.35rem .8rem;color:#94a3b8">Symfony 7 <span style="color:#64748b;font-size:.72rem">full stack</span></td>
          <td style="padding:.35rem .8rem;text-align:right;color:#94a3b8">~2,000</td>
          <td style="padding:.35rem .8rem;text-align:right;color:#10b981;font-weight:600">33x slower</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.35rem .8rem;color:#94a3b8">Laravel 11 <span style="color:#64748b;font-size:.72rem">full stack</span></td>
          <td style="padding:.35rem .8rem;text-align:right;color:#94a3b8">~500</td>
          <td style="padding:.35rem .8rem;text-align:right;color:#10b981;font-weight:600">130x slower</td>
        </tr>
      </table>
      <p style="text-align:center;color:#64748b;font-size:.72rem;margin-top:.75rem">
        ZealPHP + Express benchmarked on same machine, 4 workers, <code style="background:rgba(255,255,255,.05);padding:.1rem .3rem;border-radius:3px;color:#94a3b8">ab -n 50000 -c 200 -k</code>.<br>
        PHP framework numbers from community benchmarks on comparable hardware.<br>
        OpenSwoole is ZealPHP's runtime — the gap is ZealPHP's framework overhead (routing, middleware, PSR-7).
      </p>
    </div>
  </div>
</section>

<script>
(function() {
  const output = document.getElementById('hero-stream-output');
  const words = 'ZealPHP streams AI responses token-by-token using PHP generators. No WebSocket library needed. No third-party SSE proxy. Just yield and go.'.split(' ');
  let i = 0;
  function streamWord() {
    if (i >= words.length) { setTimeout(() => { output.innerHTML = ''; i = 0; streamWord(); }, 2000); return; }
    const span = document.createElement('span');
    span.className = 'stream-line';
    span.textContent = words[i] + ' ';
    span.style.animationDelay = '0s';
    output.appendChild(span);
    i++;
    setTimeout(streamWord, 90 + Math.random() * 60);
  }
  setTimeout(streamWord, 800);
})();
</script>

<!-- Live AI Chat Demo -->
<section class="section">
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
        <span style="margin-left:.5rem;color:var(--text-muted)">The full backend powering this chat</span>
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
<pre class="chat-src-panel" id="chat-src-php" style="display:none"><code>// route/chat.php
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

<script>
function chatSourceTab(btn, id) {
  btn.parentElement.querySelectorAll('.chat-source-tab').forEach(function(t) { t.classList.remove('active'); });
  btn.classList.add('active');
  btn.closest('.chat-source').querySelectorAll('.chat-src-panel').forEach(function(p) { p.style.display = 'none'; });
  document.getElementById(id).style.display = '';
}
</script>

<script>
(function() {
  let threadId = localStorage.getItem('zealphp_chat_thread');

  // Check status
  fetch('/api/chat/status').then(function(r) { return r.json(); }).then(function(s) {
    const el = document.getElementById('chat-status');
    el.textContent = s.ai_enabled ? 'Agents SDK' : 'Demo mode';
    el.style.color = s.ai_enabled ? '#10b981' : '#f59e0b';
  }).catch(function() {
    document.getElementById('chat-status').textContent = 'Offline';
  });

  // Enter to send
  document.getElementById('chat-input').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chatSend(); }
  });

  window.chatSend = function() {
    const input = document.getElementById('chat-input');
    const messages = document.getElementById('chat-messages');
    const btn = document.getElementById('chat-send');
    const text = input.value.trim();
    if (!text) return;

    // Add user message
    messages.innerHTML += '<div class="chat-msg user"><div class="chat-msg-bubble">' + escapeHtml(text) + '</div></div>';
    input.value = '';
    btn.disabled = true;

    // Add assistant placeholder
    const assistantDiv = document.createElement('div');
    assistantDiv.className = 'chat-msg assistant';
    assistantDiv.innerHTML = '<div class="chat-msg-bubble"><span class="chat-typing"></span></div>';
    messages.appendChild(assistantDiv);
    messages.scrollTop = messages.scrollHeight;

    const bubble = assistantDiv.querySelector('.chat-msg-bubble');
    bubble.innerHTML = '';

    // SSE via fetch — accumulate HTML and render via innerHTML
    let rawHtml = '';
    fetch('/api/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, thread_id: threadId })
    }).then(function(response) {
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      function read() {
        reader.read().then(function(result) {
          if (result.done) { btn.disabled = false; return; }
          buffer += decoder.decode(result.value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop();

          for (const line of lines) {
            if (line.startsWith('data: ')) {
              try {
                const data = JSON.parse(line.slice(6));
                if (data.thread_id) { threadId = data.thread_id; localStorage.setItem('zealphp_chat_thread', threadId); }
                if (data.token) {
                  rawHtml += data.token;
                  bubble.innerHTML = rawHtml;
                  messages.scrollTop = messages.scrollHeight;
                }
              } catch(e) {}
            }
          }
          read();
        });
      }
      read();
    }).catch(function(e) {
      bubble.textContent = 'Error: ' + e.message;
      btn.disabled = false;
    });
  };

  function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
  }
})();
</script>

<!-- One Server. Everything. -->
<section class="section">
  <div class="container">
    <h2 class="section-title">One server. Everything.</h2>
    <p class="section-desc">Your entire AI backend is one command: <code>php app.php</code></p>
    <div class="arch-compare">
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
  </div>
</section>

<!-- Why Not Just Use [X]? -->
<section class="section" style="background:var(--bg-alt)">
  <div class="container">
    <h2 class="section-title">Why not just use...?</h2>
    <p class="section-desc">Bold claims. Real code. You decide.</p>

    <!-- vs Node.js -->
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

    <!-- vs Go -->
    <div class="bold-claim">
      <h3>Go is fast. ZealPHP is fast AND expressive.</h3>
      <p>66k req/s on 4 workers. But you also get reflection-based injection, auto-serialization, and zero boilerplate.</p>
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

    <!-- vs Python FastAPI -->
    <div class="bold-claim">
      <h3>FastAPI can't hold 10k concurrent connections</h3>
      <p>ZealPHP's multi-process workers + coroutines handle C1000K. FastAPI's single-process async struggles past a few thousand.</p>
      <div class="code-compare">
        <div class="code-compare-panel">
          <div class="compare-label">ZealPHP — true parallelism</div>
<pre><code>// 16 workers × thousands of coroutines
// Shared memory across workers (no Redis)
// Each coroutine yields on I/O automatically
ZEALPHPY_WORKERS=16 php app.php

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
<section class="section" style="background:var(--bg-dark);color:#e2e8f0;padding-top:3rem;padding-bottom:3rem">
  <div class="container">
    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
      <div>
        <h2 style="color:#fff;margin-bottom:.25rem">Quick Start</h2>
        <p style="color:#94a3b8;margin:0">From zero to running server in 60 seconds.</p>
      </div>
      <div style="display:flex;gap:.5rem;font-size:.78rem" class="qs-tabs">
        <button class="qs-tab active" data-tab="starter" onclick="qsTab('starter')">Starter Project</button>
        <button class="qs-tab" data-tab="framework" onclick="qsTab('framework')">Framework Repo</button>
        <button class="qs-tab" data-tab="wordpress" onclick="qsTab('wordpress')">WordPress</button>
      </div>
    </div>

    <div class="qs-panel active" data-panel="starter">
      <div class="qs-block">
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> composer create-project sibidharan/zealphp-project:^0.1.1 my-app</span><button class="qs-copy" data-copy="composer create-project sibidharan/zealphp-project:^0.1.1 my-app">copy</button></div>
        <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd my-app && php app.php</span><button class="qs-copy" data-copy="cd my-app && php app.php">copy</button></div>
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">Server running at <code style="color:#818cf8">http://localhost:8080</code></span></div>
      </div>
      <div class="qs-note">Includes CLAUDE.md for AI-assisted development. Restart with <code>php app.php</code> after editing routes.</div>
    </div>

    <div class="qs-panel" data-panel="framework">
      <div class="qs-block">
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> git clone https://github.com/sibidharan/zealphp.git</span><button class="qs-copy" data-copy="git clone https://github.com/sibidharan/zealphp.git">copy</button></div>
        <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd zealphp && composer install && php app.php</span><button class="qs-copy" data-copy="cd zealphp && composer install && php app.php">copy</button></div>
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">This very site, running locally at <code style="color:#818cf8">http://localhost:8080</code></span></div>
      </div>
      <div class="qs-note">The framework repo IS the OSS website — every page is a live, working example of a feature.</div>
    </div>

    <div class="qs-panel" data-panel="wordpress">
      <div class="qs-block">
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> git clone https://github.com/sibidharan/zealphp-wordpress.git</span><button class="qs-copy" data-copy="git clone https://github.com/sibidharan/zealphp-wordpress.git">copy</button></div>
        <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd zealphp-wordpress && composer install</span><button class="qs-copy" data-copy="cd zealphp-wordpress && composer install">copy</button></div>
        <div class="qs-line"><span class="qs-num">3</span><span class="qs-cmd"><span class="qs-prompt">$</span> php app.php</span><button class="qs-copy" data-copy="php app.php">copy</button></div>
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">WordPress at <code style="color:#818cf8">http://localhost:9501</code> — admin, login, REST API all working</span></div>
      </div>
      <div class="qs-note">Zero WordPress modifications. CGI worker provides Apache mod_php compatibility. See <a href="/legacy-apps">Legacy Apps</a>.</div>
    </div>

    <div class="qs-prereq">
      <span class="qs-prereq-label">Requires</span>
      <code>PHP 8.3.x</code>
      <code>OpenSwoole 25+</code>
      <code>uopz</code>
      <code>composer</code>
      <a href="/getting-started" class="qs-prereq-link">Install help →</a>
    </div>
  </div>
</section>

<style>
.qs-tabs button {
  background: transparent; color: #94a3b8; border: 1px solid rgba(255,255,255,.1);
  padding: .45rem .85rem; border-radius: 6px; cursor: pointer; font-weight: 500;
  font-size: .78rem; transition: all .15s; font-family: var(--font);
}
.qs-tabs button:hover { color: #e2e8f0; border-color: rgba(255,255,255,.2); }
.qs-tabs button.active {
  background: var(--accent); border-color: var(--accent); color: #fff;
}
.qs-panel { display: none; }
.qs-panel.active { display: block; }
.qs-block {
  background: #0a0f1e; border: 1px solid rgba(255,255,255,.06);
  border-radius: 10px; padding: 1.25rem 1.5rem; margin-bottom: 1rem;
  font-family: var(--font-mono); font-size: .87rem;
}
.qs-line {
  display: flex; align-items: center; gap: .85rem;
  padding: .35rem 0; line-height: 1.6;
}
.qs-num {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 50%;
  background: rgba(99,102,241,.15); color: var(--accent);
  font-size: .72rem; font-weight: 700; flex-shrink: 0;
  font-family: var(--font);
}
.qs-arrow {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; color: #10b981; font-size: 1rem; flex-shrink: 0;
}
.qs-prompt { color: #64748b; margin-right: .4rem; user-select: none; }
.qs-cmd { color: #e2e8f0; flex: 1; word-break: break-all; }
.qs-out { color: #94a3b8; font-style: italic; flex: 1; }
.qs-out a { color: #818cf8; }
.qs-copy {
  background: transparent; color: #64748b; border: 1px solid rgba(255,255,255,.08);
  padding: .15rem .55rem; border-radius: 4px; cursor: pointer; font-size: .68rem;
  font-family: var(--font); transition: all .15s;
}
.qs-copy:hover { color: #e2e8f0; border-color: rgba(255,255,255,.2); background: rgba(255,255,255,.03); }
.qs-copy.copied { color: #10b981; border-color: #10b981; }
.qs-note {
  color: #64748b; font-size: .82rem; padding: .25rem .5rem;
}
.qs-note code { background: rgba(255,255,255,.05); padding: .1rem .35rem; border-radius: 3px; color: #cbd5e1; }
.qs-note a { color: #818cf8; }
.qs-prereq {
  margin-top: 1.5rem; padding-top: 1.5rem;
  border-top: 1px solid rgba(255,255,255,.05);
  display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
  font-size: .78rem;
}
.qs-prereq-label { color: #64748b; text-transform: uppercase; letter-spacing: .05em; font-size: .68rem; font-weight: 700; margin-right: .25rem; }
.qs-prereq code {
  background: rgba(255,255,255,.04); color: #cbd5e1;
  padding: .2rem .55rem; border-radius: 4px; font-size: .76rem;
  border: 1px solid rgba(255,255,255,.06);
}
.qs-prereq-link { color: #818cf8; margin-left: auto; font-weight: 500; }
@media (max-width: 768px) {
  .qs-tabs { width: 100%; flex-wrap: wrap; }
  .qs-tabs button { flex: 1; min-width: 0; padding: .4rem .5rem; font-size: .72rem; }
  .qs-prereq-link { margin-left: 0; width: 100%; margin-top: .5rem; }
  .qs-cmd { font-size: .78rem; }
}
</style>

<script>
function qsTab(name) {
  document.querySelectorAll('.qs-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('.qs-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === name));
}
document.addEventListener('click', function(e) {
  if (e.target.classList && e.target.classList.contains('qs-copy')) {
    navigator.clipboard.writeText(e.target.dataset.copy).then(() => {
      const orig = e.target.textContent;
      e.target.textContent = 'copied!';
      e.target.classList.add('copied');
      setTimeout(() => {
        e.target.textContent = orig;
        e.target.classList.remove('copied');
      }, 1200);
    });
  }
});
</script>

<!-- Deploy / CLI -->
<section class="section" style="background:var(--bg-dark);color:#e2e8f0;padding-top:3rem;padding-bottom:3rem;border-top:1px solid rgba(255,255,255,.05)">
  <div class="container">
    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
      <div>
        <h2 style="color:#fff;margin-bottom:.25rem">Deploy to production</h2>
        <p style="color:#94a3b8;margin:0">Built-in CLI for daemonization. systemd service template included.</p>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
      <div class="qs-block">
        <div style="color:#64748b;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;margin-bottom:.75rem;font-family:var(--font)">Manual CLI (without systemd)</div>
        <div class="qs-line"><span class="qs-cmd"><span class="qs-prompt">$</span> php app.php start -p 8080 -d</span><button class="qs-copy" data-copy="php app.php start -p 8080 -d">copy</button></div>
        <div class="qs-line"><span class="qs-cmd"><span class="qs-prompt">$</span> php app.php status</span><button class="qs-copy" data-copy="php app.php status">copy</button></div>
        <div class="qs-line"><span class="qs-cmd"><span class="qs-prompt">$</span> php app.php stop</span><button class="qs-copy" data-copy="php app.php stop">copy</button></div>
        <div class="qs-line"><span class="qs-cmd"><span class="qs-prompt">$</span> php app.php --help</span><button class="qs-copy" data-copy="php app.php --help">copy</button></div>
        <div style="color:#64748b;font-size:.78rem;margin-top:.85rem;font-family:var(--font);line-height:1.6">
          Flags: <code style="background:rgba(255,255,255,.05);padding:.1rem .35rem;border-radius:3px">-p PORT</code> <code style="background:rgba(255,255,255,.05);padding:.1rem .35rem;border-radius:3px">-d daemonize</code> <code style="background:rgba(255,255,255,.05);padding:.1rem .35rem;border-radius:3px">-w WORKERS</code> <code style="background:rgba(255,255,255,.05);padding:.1rem .35rem;border-radius:3px">-H HOST</code> <code style="background:rgba(255,255,255,.05);padding:.1rem .35rem;border-radius:3px">--pid-file</code>
        </div>
      </div>

      <div class="qs-block">
        <div style="color:#64748b;font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700;margin-bottom:.75rem;font-family:var(--font)">Run as a service (systemd)</div>
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> sudo cp deploy/zealphp.service /etc/systemd/system/</span><button class="qs-copy" data-copy="sudo cp deploy/zealphp.service /etc/systemd/system/">copy</button></div>
        <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> sudo systemctl daemon-reload</span><button class="qs-copy" data-copy="sudo systemctl daemon-reload">copy</button></div>
        <div class="qs-line"><span class="qs-num">3</span><span class="qs-cmd"><span class="qs-prompt">$</span> sudo systemctl enable --now zealphp</span><button class="qs-copy" data-copy="sudo systemctl enable --now zealphp">copy</button></div>
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">Auto-starts on boot, restarts on crash, logs to journalctl</span></div>
        <div style="color:#64748b;font-size:.78rem;margin-top:.85rem;font-family:var(--font);line-height:1.6">
          Template: <a href="https://github.com/sibidharan/zealphp/blob/master/deploy/zealphp.service" target="_blank" style="color:#818cf8">deploy/zealphp.service</a> · Logs: <code style="background:rgba(255,255,255,.05);padding:.1rem .35rem;border-radius:3px">journalctl -u zealphp -f</code>
        </div>
      </div>
    </div>
  </div>
</section>

<style>
@media (max-width: 768px) {
  section .container > div[style*="grid-template-columns:1fr 1fr"] { grid-template-columns: 1fr !important; }
}
</style>

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
        ['🌐', 'HTTP',        'Full HTTP/1.1 compliance. HEAD, OPTIONS, redirects, CORS, ETag, gzip — all built-in.',                 '/http',       'HTTP/1.1'],
        ['📝', 'Templates',   'SSR streaming templates. Compose views with yield from. renderStream() for progressive HTML.',         '/templates',  'renderStream()'],
        ['🔗', 'ZealAPI',     'Drop a PHP file in api/. It becomes a route. File-based REST — the simplest API pattern.',             '/api',        'file-based'],
        ['🏗️', 'Legacy Apps','Run WordPress unmodified. CGI worker provides true global scope. Apache mod_php compatibility.',        '/legacy-apps','WordPress'],
      ];
      foreach ($features as [$icon, $title, $body, $href, $badge]) {
        App::render('/components/_card', compact('icon', 'title', 'body', 'href', 'badge'));
      }
      ?>
    </div>
  </div>
</section>

<!-- Return conventions -->
<section class="section" style="background:var(--bg-alt)">
  <div class="container">
    <h2 class="section-title">Return anything, get the right response</h2>
    <p class="section-desc">ZealPHP inspects your return type and does the right thing — no boilerplate.</p>
    <table class="ztable" style="margin-top:1.5rem">
      <tr><th style="width:30%">Return</th><th style="width:35%">Result</th><th>Example</th></tr>
      <tr>
        <td><code>int</code></td>
        <td>HTTP status code</td>
        <td><code>return 404;</code> <code>return 201;</code></td>
      </tr>
      <tr>
        <td><code>array</code> / <code>object</code></td>
        <td>Auto-serialized as JSON</td>
        <td><code>return ['users' => $list];</code></td>
      </tr>
      <tr>
        <td><code>string</code></td>
        <td>HTML body</td>
        <td><code>return '&lt;h1&gt;Hello&lt;/h1&gt;';</code></td>
      </tr>
      <tr>
        <td><code>Generator</code></td>
        <td>SSR streaming (each yield sent immediately)</td>
        <td><code>yield '&lt;head&gt;'; yield $body;</code></td>
      </tr>
      <tr>
        <td><code>void</code> + <code>echo</code></td>
        <td>Buffered output via <code>ob_get_clean()</code></td>
        <td><code>echo "Hello"; echo " World";</code></td>
      </tr>
      <tr>
        <td><code>ResponseInterface</code></td>
        <td>PSR-7 response used directly</td>
        <td><code>return new Response(...);</code></td>
      </tr>
    </table>
  </div>
</section>

<!-- Live converter -->
<section class="section">
  <div class="container">
    <h2 class="section-title">Try it — convert your config to ZealPHP</h2>
    <p class="section-desc">Paste Apache <code>.htaccess</code> or nginx config. AI converts it to <code>app.php</code> in real-time.</p>
    <div class="converter-split" style="display:grid; grid-template-columns:1fr 1fr; gap:0; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; margin-top:1.5rem;">
      <div style="border-right:1px solid var(--border); display:flex; flex-direction:column;">
        <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.78rem; font-weight:600; color:var(--text-muted); display:flex; justify-content:space-between; align-items:center;">
          <span>Input</span>
          <select id="hp-preset" style="font-size:.75rem; padding:.2rem .4rem; border-radius:4px; border:1px solid var(--border); background:var(--bg);">
            <option value="wordpress">WordPress .htaccess</option>
            <option value="nginx-cms">nginx CMS</option>
            <option value="redirects">Redirect rules</option>
            <option value="">— paste your own —</option>
          </select>
        </div>
        <textarea id="hp-input" style="flex:1; min-height:220px; border:none; padding:.75rem; font-family:var(--font-mono); font-size:.8rem; background:var(--code-bg); color:var(--code-text); resize:none; outline:none;"></textarea>
        <div style="padding:.4rem .75rem; background:var(--bg-alt); display:flex; align-items:center; gap:.5rem;">
          <button id="hp-btn" onclick="hpConvert()" style="padding:.35rem 1rem; background:var(--accent); color:#fff; border:none; border-radius:5px; cursor:pointer; font-size:.8rem; font-weight:600;">Convert →</button>
          <span id="hp-status" style="font-size:.73rem; color:var(--text-muted);"></span>
        </div>
      </div>
      <div style="display:flex; flex-direction:column;">
        <div style="padding:.5rem .75rem; background:var(--bg-alt); font-size:.78rem; font-weight:600; color:var(--text-muted);">ZealPHP app.php</div>
        <pre id="hp-output" style="flex:1; min-height:220px; max-height:320px; overflow:auto; padding:.75rem; margin:0; font-family:var(--font-mono); font-size:.8rem; background:var(--code-bg); color:var(--code-text); white-space:pre-wrap;"><span style="color:var(--text-muted);">// Click Convert to generate...</span></pre>
        <div style="padding:.4rem .75rem; background:var(--bg-alt); font-size:.7rem; color:var(--text-muted);">Powered by gpt-4.1-mini · Cached for 1hr · <a href="/legacy-apps">Full docs →</a></div>
      </div>
    </div>
  </div>
</section>

<script>
(function(){
  const HP = {
    wordpress: "# BEGIN WordPress\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteBase /\nRewriteRule ^index\\.php$ - [L]\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteCond %{REQUEST_FILENAME} !-d\nRewriteRule . /index.php [L]\n</IfModule>\n# END WordPress",
    'nginx-cms': "server {\n    listen 80;\n    server_name example.com;\n    root /var/www/html;\n\n    location / {\n        try_files $uri $uri/ /index.php?$args;\n    }\n    location ~ \\.php$ {\n        fastcgi_pass unix:/run/php/php-fpm.sock;\n    }\n    location ~* \\.(css|js|png)$ {\n        expires 30d;\n    }\n}",
    redirects: "RewriteEngine On\nRewriteRule ^old-page$ /new-page [R=301,L]\nRewriteRule ^blog/(.*)$ /articles/$1 [R=302,L]\nRewriteRule ^docs$ https://docs.example.com [R=301,L]"
  };

  const presetEl = document.getElementById('hp-preset');
  const inputEl = document.getElementById('hp-input');
  presetEl.addEventListener('change', function() {
    if (this.value && HP[this.value]) inputEl.value = HP[this.value];
    else inputEl.value = '';
  });
  if (presetEl.value && HP[presetEl.value]) inputEl.value = HP[presetEl.value];

  window.hpConvert = function() {
    const input = inputEl.value.trim();
    const output = document.getElementById('hp-output');
    const status = document.getElementById('hp-status');
    const btn = document.getElementById('hp-btn');
    if (!input) { status.textContent = 'Paste a config first'; return; }
    btn.disabled = true; btn.textContent = 'Converting...';
    status.textContent = 'Streaming...'; output.textContent = '';
    fetch('/api/convert', {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({config: input})
    }).then(function(r) {
      const reader = r.body.getReader(), dec = new TextDecoder();
      let buf = '';
      function read() {
        reader.read().then(function(result) {
          if (result.done) { btn.disabled = false; btn.textContent = 'Convert →'; status.textContent = 'Done'; return; }
          buf += dec.decode(result.value, {stream: true});
          const lines = buf.split('\n'); buf = lines.pop();
          for (const l of lines) {
            if (l.startsWith('data: ') && !l.includes('[DONE]')) output.textContent += l.slice(6) + '\n';
          }
          output.scrollTop = output.scrollHeight;
          read();
        });
      }
      read();
    }).catch(function(e) {
      output.textContent = '// Error: ' + e.message;
      btn.disabled = false; btn.textContent = 'Convert →'; status.textContent = 'Failed';
    });
  };
})();
</script>

<!-- Built for what's next -->
<section class="section" style="background:var(--bg-alt)">
  <div class="container">
    <h2 class="section-title">Built for what's next</h2>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1.5rem;margin-top:1.5rem">
      <?php
      $why = [
        ['🚀', 'Non-blocking everything',  'Every I/O call yields to the event loop. OpenSwoole HOOK_ALL makes existing PHP libraries async automatically. Zero rewrites.'],
        ['🌊', 'C1000K ready',             'Multi-process workers + coroutines. One server handles a million concurrent connections. No worker thread juggling.'],
        ['🧵', 'True coroutines',          'Not fake async with callbacks. Real coroutines with go() + Channel. Write synchronous-looking code that runs concurrently.'],
        ['🔧', 'PHP you already know',     '80% of developers know PHP. Sessions, headers, superglobals — all work via uopz overrides. Migrate existing apps without rewriting.'],
        ['📐', 'PSR standards',            'PSR-7 request/response, PSR-15 middleware. Drop in any standards-compliant package from the PHP ecosystem.'],
        ['📊', 'Benchmarked performance',  '66k req/s with full middleware, 3ms avg latency, 0 failures on 4 workers. Local quad-core benchmark sweep. Reproducible — run scripts/bench.sh yourself.'],
        ['🔓', 'MIT open source',          'Fully open source. No enterprise tier. No "contact sales." Community-maintained on OpenSwoole, PHP\'s battle-tested async runtime.'],
      ];
      foreach ($why as [$icon, $title, $body]):
      ?>
      <div class="card">
        <div class="card-icon"><?= $icon ?></div>
        <h3><?= $title ?></h3>
        <p><?= $body ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Build your first AI app -->
<section class="section" style="background:var(--bg-dark);color:#e2e8f0;padding:3rem 0">
  <div class="container" style="text-align:center">
    <h2 style="color:#fff;margin-bottom:.5rem">Build your first AI chat in 60 seconds</h2>
    <p style="color:#94a3b8;margin-bottom:1.5rem">Includes CLAUDE.md — your AI copilot understands ZealPHP out of the box.</p>
    <div class="qs-block" style="max-width:600px;margin:0 auto 1.5rem;text-align:left">
      <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> composer create-project sibidharan/zealphp-project my-ai-app</span></div>
      <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd my-ai-app && php app.php</span></div>
      <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">AI-ready server at <code style="color:#818cf8">http://localhost:8080</code></span></div>
    </div>
    <a href="/getting-started" class="btn btn-primary" style="font-size:1rem;padding:.75rem 2rem">Get started →</a>
  </div>
</section>

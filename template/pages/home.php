<?php
use ZealPHP\App;
use function ZealPHP\site_url;

$siteUrl = site_url();
?>

<!-- Hero -->
<section class="hero">
  <div class="container">
    <h1>Zeal<span>PHP</span></h1>
    <p style="font-size:1.4rem;color:#fef3c7;font-weight:600;margin:.5rem auto .75rem;position:relative">
      The PHP Runtime for AI Web Apps</p>
    <p>Stream AI responses in 5 lines. WebSocket, SSE, shared memory, task workers —<br>
       one server, one process. Go-level performance, PHP simplicity.</p>
    <p style="font-size:.95rem;color:#94a3b8;margin-top:.25rem">Upgrade your existing PHP codebase to async — start without rewriting, migrate at your own pace.</p>
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
      <a href="https://github.com/sibidharan/zealphp/blob/master/phpstan.neon" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/badge/PHPStan-level%201-brightgreen?style=flat-square&logo=php&logoColor=white" alt="PHPStan level 1">
      </a>
      <a href="https://github.com/sibidharan/zealphp/blob/master/CODE_OF_CONDUCT.md" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/badge/Contributor%20Covenant-2.1-4baaaa?style=flat-square" alt="Contributor Covenant 2.1">
      </a>
      <a href="https://github.com/sponsors/sibidharan" target="_blank" rel="noopener noreferrer">
        <img src="https://img.shields.io/github/sponsors/sibidharan?style=flat-square&logo=github&logoColor=white&label=Sponsor" alt="GitHub Sponsors">
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

    <div class="bench-note">With full middleware stack. 4 workers.</div>
    <div class="bench">
      <div class="bench-stat"><div class="num">95k</div><div class="label">req/s text</div></div>
      <div class="bench-stat"><div class="num">90k</div><div class="label">req/s JSON</div></div>
      <div class="bench-stat"><div class="num">65k</div><div class="label">req/s template</div></div>
      <div class="bench-stat"><div class="num">0</div><div class="label">failures</div></div>
    </div>
    <div style="margin-top:1.5rem;position:relative">
      <table style="margin:0 auto;border-collapse:collapse;font-size:.78rem;max-width:740px;width:100%">
        <tr style="border-bottom:1px solid rgba(255,255,255,.1)">
          <th style="text-align:left;padding:.4rem .6rem;color:#94a3b8;font-weight:600">Framework</th>
          <th style="text-align:right;padding:.4rem .6rem;color:#94a3b8;font-weight:600">Raw text</th>
          <th style="text-align:right;padding:.4rem .6rem;color:#94a3b8;font-weight:600">JSON API</th>
          <th style="text-align:right;padding:.4rem .6rem;color:#94a3b8;font-weight:600">Template</th>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02)">
          <td colspan="4" style="padding:.35rem .6rem;color:#64748b;font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Runtime (no framework, no middleware)</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.35rem .6rem;color:#94a3b8">OpenSwoole raw</td>
          <td style="padding:.35rem .6rem;text-align:right;color:#94a3b8">298k</td>
          <td style="padding:.35rem .6rem;text-align:right;color:#94a3b8">258k</td>
          <td style="padding:.35rem .6rem;text-align:right;color:#64748b">—</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.35rem .6rem;color:#94a3b8">Node.js raw http</td>
          <td style="padding:.35rem .6rem;text-align:right;color:#94a3b8">222k</td>
          <td style="padding:.35rem .6rem;text-align:right;color:#94a3b8">281k</td>
          <td style="padding:.35rem .6rem;text-align:right;color:#64748b">—</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02)">
          <td colspan="4" style="padding:.35rem .6rem;color:#64748b;font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Full framework (CORS + ETag + sessions + routing + templates)</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.4rem .6rem;color:var(--accent);font-weight:700">ZealPHP <span style="color:#64748b;font-weight:400;font-size:.68rem">built-in</span></td>
          <td style="padding:.4rem .6rem;text-align:right;color:var(--accent);font-weight:700">95k</td>
          <td style="padding:.4rem .6rem;text-align:right;color:var(--accent);font-weight:700">90k</td>
          <td style="padding:.4rem .6rem;text-align:right;color:var(--accent);font-weight:700">65k</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.4rem .6rem;color:#e2e8f0">Express.js <span style="color:#64748b;font-size:.68rem">+5 npm pkgs</span></td>
          <td style="padding:.4rem .6rem;text-align:right;color:#e2e8f0">87k</td>
          <td style="padding:.4rem .6rem;text-align:right;color:#e2e8f0">105k</td>
          <td style="padding:.4rem .6rem;text-align:right;color:#e2e8f0">36k</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06);background:rgba(255,255,255,.02)">
          <td colspan="4" style="padding:.35rem .6rem;color:#64748b;font-size:.65rem;text-transform:uppercase;letter-spacing:.05em;font-weight:700">Other PHP frameworks <span style="font-weight:400;text-transform:none;letter-spacing:0">(community benchmarks)</span></td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.3rem .6rem;color:#64748b">Slim 4</td>
          <td colspan="3" style="padding:.3rem .6rem;text-align:right;color:#10b981;font-weight:600;font-size:.75rem">~4k — 22x slower</td>
        </tr>
        <tr style="border-bottom:1px solid rgba(255,255,255,.06)">
          <td style="padding:.3rem .6rem;color:#64748b">Symfony 7</td>
          <td colspan="3" style="padding:.3rem .6rem;text-align:right;color:#10b981;font-weight:600;font-size:.75rem">~2k — 45x slower</td>
        </tr>
        <tr>
          <td style="padding:.3rem .6rem;color:#64748b">Laravel 11</td>
          <td colspan="3" style="padding:.3rem .6rem;text-align:right;color:#10b981;font-weight:600;font-size:.75rem">~500 — 180x slower</td>
        </tr>
      </table>
      <p style="text-align:center;color:#64748b;font-size:.7rem;margin-top:.75rem">
        Same machine, 4 workers, <code style="background:rgba(255,255,255,.05);padding:.1rem .3rem;border-radius:3px;color:#94a3b8">ab -n 50000 -c 200 -k</code>.
        ZealPHP beats Express on text and templates — Express wins on JSON (V8 JSON.stringify).<br>
        ZealPHP needs zero npm packages. Express needs cors + ejs + express-session + session-file-store + body-parser.
      </p>
      <div style="margin-top:1rem;text-align:center">
        <p style="color:#94a3b8;font-size:.75rem;margin-bottom:.5rem">Don't trust our numbers — run it yourself:</p>
        <div class="qs-block" style="max-width:520px;margin:0 auto;text-align:left;padding:.75rem 1rem">
          <div class="qs-line"><span class="qs-cmd"><span class="qs-prompt">$</span> scripts/bench_vs_express.sh</span><button class="qs-copy" data-copy="scripts/bench_vs_express.sh">copy</button></div>
        </div>
        <p style="color:#64748b;font-size:.68rem;margin-top:.4rem">Starts ZealPHP + Express + Node raw + OpenSwoole raw, benchmarks all 3 workloads, cleans up. <code style="background:rgba(255,255,255,.05);padding:.1rem .3rem;border-radius:3px;color:#94a3b8">WORKERS=8 CONCURRENCY=500</code> to customize.</p>
      </div>
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
    assistantDiv.innerHTML = '<div class="chat-msg-bubble"><span class="chat-typing"><span></span><span></span><span></span></span></div>';
    messages.appendChild(assistantDiv);
    messages.scrollTop = messages.scrollHeight;

    const bubble = assistantDiv.querySelector('.chat-msg-bubble');

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
                  if (!rawHtml) bubble.querySelector('.chat-typing')?.remove();
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

<!-- Why Not Just Use [X]? -->
<section class="section" style="background:var(--bg-alt)">
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
      <h3>Go is fast. ZealPHP is fast AND expressive.</h3>
      <p>90k req/s on 4 workers. But you also get reflection-based injection, auto-serialization, and zero boilerplate.</p>
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
      <h3>FastAPI can't hold 10k concurrent connections</h3>
      <p>ZealPHP's multi-process workers + coroutines handle C1000K. FastAPI's single-process async struggles past a few thousand.</p>
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

<!-- Migrate Your PHP Codebase -->
<section class="section" style="background:var(--bg-dark);color:var(--code-text)">
  <div class="container">
    <h2 class="section-title" style="color:#fff">Migrate your PHP codebase to async</h2>
    <p class="section-desc">Your existing code works unchanged. <code>session_start()</code>, <code>header()</code>, <code>$_GET</code> — all overridden via uopz to work inside the coroutine runtime.</p>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-top:2rem">
      <div style="background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius);padding:1.5rem">
        <h3 style="color:var(--danger);font-size:1rem;margin-bottom:1rem">Before — 6 services</h3>
        <ul style="list-style:none;padding:0;margin:0;font-size:.85rem;color:var(--text-light)">
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">Nginx / Apache</li>
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">PHP-FPM (cold start every request)</li>
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">Redis (sessions, cache, pub/sub)</li>
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">Socket.io / Ratchet (WebSocket)</li>
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">Supervisor / cron (background tasks)</li>
          <li style="padding:.35rem 0">SSE proxy or polling</li>
        </ul>
      </div>
      <div style="background:var(--code-bg);border:1px solid var(--accent);border-radius:var(--radius);padding:1.5rem">
        <h3 style="color:var(--accent);font-size:1rem;margin-bottom:1rem">After — 1 process</h3>
        <div style="text-align:center;margin-bottom:1rem">
          <code style="font-size:1.1rem;color:var(--accent);background:rgba(245,158,11,.1);padding:.4rem .8rem;border-radius:6px">php app.php</code>
        </div>
        <ul style="list-style:none;padding:0;margin:0;font-size:.85rem;color:var(--code-text)">
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">HTTP + WebSocket + SSE server</li>
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">Coroutine-safe sessions (no Redis)</li>
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">Shared memory across workers</li>
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">Task workers (no cron/supervisor)</li>
          <li style="padding:.35rem 0;border-bottom:1px solid var(--border-dark)">Persistent connections, no cold starts</li>
          <li style="padding:.35rem 0">WordPress runs unmodified</li>
        </ul>
      </div>
    </div>

    <div style="margin-top:2.5rem">
      <h3 style="font-size:1.1rem;margin-bottom:1.25rem;color:#fff">The migration ladder — go at your own pace</h3>
      <div style="display:grid;gap:.75rem">
        <div style="display:grid;grid-template-columns:auto 1fr;gap:1rem;align-items:start;background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius);padding:1rem 1.25rem">
          <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(245,158,11,.15);color:var(--accent);font-size:.78rem;font-weight:700;flex-shrink:0">0</span>
          <div>
            <div style="font-weight:600;color:var(--code-text);font-size:.9rem;margin-bottom:.3rem">Drop in your entire app</div>
            <code style="font-size:.78rem;color:var(--text-light)">App::superglobals(true); $app->setFallback(fn() => App::includeFile('index.php'));</code>
            <div style="color:var(--text-muted);font-size:.78rem;margin-top:.25rem">WordPress, Drupal, any PHP app — runs unmodified on OpenSwoole.</div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:auto 1fr;gap:1rem;align-items:start;background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius);padding:1rem 1.25rem">
          <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(245,158,11,.15);color:var(--accent);font-size:.78rem;font-weight:700;flex-shrink:0">1</span>
          <div>
            <div style="font-weight:600;color:var(--code-text);font-size:.9rem;margin-bottom:.3rem">Write LAMP-style PHP in <code>public/</code></div>
            <code style="font-size:.78rem;color:var(--text-light)">public/about.php → /about &nbsp;·&nbsp; public/users/list.php → /users/list</code>
            <div style="color:var(--text-muted);font-size:.78rem;margin-top:.25rem">File-based routing. <code>$_GET</code>, <code>session_start()</code>, <code>echo</code> — everything you know works.</div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:auto 1fr;gap:1rem;align-items:start;background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius);padding:1rem 1.25rem">
          <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(245,158,11,.15);color:var(--accent);font-size:.78rem;font-weight:700;flex-shrink:0">2</span>
          <div>
            <div style="font-weight:600;color:var(--code-text);font-size:.9rem;margin-bottom:.3rem">Add REST APIs with <code>api/</code></div>
            <code style="font-size:.78rem;color:var(--text-light)">api/users/get.php → GET /api/users &nbsp;·&nbsp; api/users/post.php → POST /api/users</code>
            <div style="color:var(--text-muted);font-size:.78rem;margin-top:.25rem">Drop a PHP file, get a REST endpoint. ZealAPI auto-routes by filename. Zero config.</div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:auto 1fr;gap:1rem;align-items:start;background:var(--code-bg);border:1px solid var(--border-dark);border-radius:var(--radius);padding:1rem 1.25rem">
          <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(245,158,11,.15);color:var(--accent);font-size:.78rem;font-weight:700;flex-shrink:0">3</span>
          <div>
            <div style="font-weight:600;color:var(--code-text);font-size:.9rem;margin-bottom:.3rem">Use framework routes for new features</div>
            <code style="font-size:.78rem;color:var(--text-light)">$app->route('/ws/chat', ...); $response->sse(...); yield $html;</code>
            <div style="color:var(--text-muted);font-size:.78rem;margin-top:.25rem">WebSocket, SSE streaming, coroutines — available when you're ready, not forced upfront.</div>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:auto 1fr;gap:1rem;align-items:start;background:var(--code-bg);border:1px solid var(--accent);border-radius:var(--radius);padding:1rem 1.25rem">
          <span style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:50%;background:rgba(245,158,11,.25);color:var(--accent);font-size:.78rem;font-weight:700;flex-shrink:0">4</span>
          <div>
            <div style="font-weight:600;color:var(--accent);font-size:.9rem;margin-bottom:.3rem">Full coroutine mode</div>
            <code style="font-size:.78rem;color:var(--text-light)">App::superglobals(false); // thousands of concurrent requests per worker</code>
            <div style="color:var(--text-muted);font-size:.78rem;margin-top:.25rem">Replace superglobals with <code>G::instance()</code>. Per-coroutine isolation. Go-level concurrency.</div>
          </div>
        </div>
      </div>
    </div>

    <div style="text-align:center;margin-top:1.5rem">
      <a href="/why-zealphp" class="btn btn-outline" style="font-size:.85rem">Why ZealPHP? →</a>
      <a href="/legacy-apps" class="btn btn-outline" style="font-size:.85rem;margin-left:.5rem">WordPress on ZealPHP →</a>
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
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">Server running at <code style="color:#fbbf24">http://localhost:8080</code></span></div>
      </div>
      <div class="qs-note">Includes CLAUDE.md for AI-assisted development. Restart with <code>php app.php</code> after editing routes.</div>
    </div>

    <div class="qs-panel" data-panel="framework">
      <div class="qs-block">
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> git clone https://github.com/sibidharan/zealphp.git</span><button class="qs-copy" data-copy="git clone https://github.com/sibidharan/zealphp.git">copy</button></div>
        <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd zealphp && composer install && php app.php</span><button class="qs-copy" data-copy="cd zealphp && composer install && php app.php">copy</button></div>
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">This very site, running locally at <code style="color:#fbbf24">http://localhost:8080</code></span></div>
      </div>
      <div class="qs-note">The framework repo IS the OSS website — every page is a live, working example of a feature.</div>
    </div>

    <div class="qs-panel" data-panel="wordpress">
      <div class="qs-block">
        <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> git clone https://github.com/sibidharan/zealphp-wordpress.git</span><button class="qs-copy" data-copy="git clone https://github.com/sibidharan/zealphp-wordpress.git">copy</button></div>
        <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd zealphp-wordpress && composer install</span><button class="qs-copy" data-copy="cd zealphp-wordpress && composer install">copy</button></div>
        <div class="qs-line"><span class="qs-num">3</span><span class="qs-cmd"><span class="qs-prompt">$</span> php app.php</span><button class="qs-copy" data-copy="php app.php">copy</button></div>
        <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">WordPress at <code style="color:#fbbf24">http://localhost:9501</code> — admin, login, REST API all working</span></div>
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
      <tr><td><code>int</code></td><td>HTTP status code</td><td><code>return 404;</code> <code>return 201;</code></td></tr>
      <tr><td><code>array</code> / <code>object</code></td><td>Auto-serialized as JSON</td><td><code>return ['users' => $list];</code></td></tr>
      <tr><td><code>string</code></td><td>HTML body</td><td><code>return '&lt;h1&gt;Hello&lt;/h1&gt;';</code></td></tr>
      <tr><td><code>Generator</code></td><td>SSR streaming (each yield sent immediately)</td><td><code>yield '&lt;head&gt;'; yield $body;</code></td></tr>
      <tr><td><code>void</code> + <code>echo</code></td><td>Buffered output via <code>ob_get_clean()</code></td><td><code>echo "Hello"; echo " World";</code></td></tr>
      <tr><td><code>ResponseInterface</code></td><td>PSR-7 response used directly</td><td><code>return new Response(...);</code></td></tr>
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

<!-- Build your first AI app -->
<section class="section" style="background:var(--bg-dark);color:#e2e8f0;padding:3rem 0">
  <div class="container" style="text-align:center">
    <h2 style="color:#fff;margin-bottom:.5rem">From zero to running server in 60 seconds</h2>
    <p style="color:#94a3b8;margin-bottom:1.5rem">Includes CLAUDE.md — your AI copilot understands ZealPHP out of the box.</p>
    <div class="qs-block" style="max-width:600px;margin:0 auto 1.5rem;text-align:left">
      <div class="qs-line"><span class="qs-num">1</span><span class="qs-cmd"><span class="qs-prompt">$</span> composer create-project sibidharan/zealphp-project my-app</span></div>
      <div class="qs-line"><span class="qs-num">2</span><span class="qs-cmd"><span class="qs-prompt">$</span> cd my-app && php app.php</span></div>
      <div class="qs-line"><span class="qs-arrow">→</span><span class="qs-out">Server running at <code style="color:#fbbf24">http://localhost:8080</code></span></div>
    </div>
    <a href="/getting-started" class="btn btn-primary" style="font-size:1rem;padding:.75rem 2rem">Get started →</a>
  </div>
</section>

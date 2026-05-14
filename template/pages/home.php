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

    <div class="bench-note">Full middleware (CORS + ETag + sessions + PSR-7). 4 workers. <code>ab -n 50000 -c 200 -k</code>. Zero npm packages needed.</div>
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

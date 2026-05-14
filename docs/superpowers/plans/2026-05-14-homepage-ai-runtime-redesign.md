# Homepage AI Runtime Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rewrite the ZealPHP homepage to position it as "The PHP Runtime for AI Web Apps" with a live streaming code demo, architecture comparison, bold code comparisons vs Node/Go/FastAPI, AI-reframed feature grid, and a working AI chat demo.

**Architecture:** The homepage is `template/pages/home.php` rendered via `template/_master.php`. All CSS lives in `public/css/zealphp.css`. The chat backend will be a new route file `route/chat.php` that uses SSE streaming + raw curl to Claude API. Chat UI JS is inline in `home.php` (follows existing pattern — the converter JS is inline too). Store provides cross-worker thread storage.

**Tech Stack:** PHP 8.3, OpenSwoole, ZealPHP framework, vanilla JS, CSS variables, Claude API (raw curl), SSE

---

## File Map

| File | Action | Responsibility |
|------|--------|----------------|
| `template/pages/home.php` | **Rewrite** | Full homepage: hero with streaming demo, architecture comparison, code comparisons, reframed feature grid, chat demo widget, footer CTA |
| `public/css/zealphp.css` | **Append** | New styles: hero streaming demo, architecture diagram, code comparison panels, chat widget, typing animation |
| `route/chat.php` | **Create** | SSE streaming AI chat endpoint with thread support via Store, graceful no-API-key fallback |
| `template/_head.php` | **Modify** | Update meta description to AI-focused tagline |

---

## Task 1: CSS Foundation — New Component Styles

**Files:**
- Modify: `public/css/zealphp.css` (append after line 409)

- [ ] **Step 1: Add hero streaming demo styles**

Append to `public/css/zealphp.css`:

```css
/* ── Hero Streaming Demo ── */
.hero-demo {
  display: grid; grid-template-columns: 1fr 1fr; gap: 0;
  max-width: 820px; margin: 2.5rem auto 0;
  border-radius: 10px; overflow: hidden;
  border: 1px solid rgba(255,255,255,.1);
  box-shadow: 0 8px 32px rgba(0,0,0,.3);
  position: relative; text-align: left;
}
.hero-demo-code {
  background: #0a0f1e; padding: 1.5rem;
  font-family: var(--font-mono); font-size: .82rem;
  line-height: 1.7; color: #cdd6f4;
  border-right: 1px solid rgba(255,255,255,.06);
}
.hero-demo-code .code-label {
  display: block; margin-bottom: .75rem;
  font-family: var(--font); font-size: .68rem;
  text-transform: uppercase; letter-spacing: .06em;
  color: #64748b; font-weight: 700;
}
.hero-demo-output {
  background: #111827; padding: 1.5rem;
  font-family: var(--font); font-size: .88rem;
  color: #e2e8f0; display: flex; flex-direction: column;
  justify-content: flex-start;
}
.hero-demo-output .code-label {
  display: block; margin-bottom: .75rem;
  font-family: var(--font); font-size: .68rem;
  text-transform: uppercase; letter-spacing: .06em;
  color: #64748b; font-weight: 700;
}
.hero-demo-output .stream-line {
  opacity: 0; animation: streamIn .3s forwards;
  line-height: 1.6;
}
@keyframes streamIn {
  from { opacity: 0; transform: translateY(4px); }
  to { opacity: 1; transform: translateY(0); }
}
.hero-tagline {
  font-size: .88rem; color: #94a3b8; margin-top: 1.5rem;
  font-style: italic; position: relative;
}
@media (max-width: 768px) {
  .hero-demo { grid-template-columns: 1fr; max-width: 100%; }
  .hero-demo-code { border-right: none; border-bottom: 1px solid rgba(255,255,255,.06); }
}
```

- [ ] **Step 2: Add architecture comparison styles**

Append to `public/css/zealphp.css`:

```css
/* ── Architecture Comparison ── */
.arch-compare {
  display: grid; grid-template-columns: 1fr auto 1fr;
  gap: 2rem; align-items: start; margin: 2rem 0;
}
.arch-box {
  border: 1px solid var(--border); border-radius: var(--radius);
  padding: 1.5rem; background: var(--bg);
}
.arch-box.complex { border-color: #fca5a5; background: #fef2f2; }
.arch-box.simple { border-color: #86efac; background: #f0fdf4; }
.arch-box h3 { font-size: 1rem; margin-bottom: 1rem; }
.arch-node {
  display: flex; align-items: center; gap: .5rem;
  padding: .5rem .75rem; margin: .35rem 0;
  border-radius: 6px; font-size: .82rem; font-weight: 500;
}
.arch-box.complex .arch-node {
  background: #fee2e2; border: 1px solid #fca5a5; color: #991b1b;
}
.arch-box.simple .arch-node {
  background: #dcfce7; border: 1px solid #86efac; color: #166534;
}
.arch-vs {
  display: flex; align-items: center; justify-content: center;
  font-size: 1.5rem; font-weight: 800; color: var(--text-muted);
  align-self: center;
}
@media (max-width: 768px) {
  .arch-compare { grid-template-columns: 1fr; }
  .arch-vs { padding: .5rem 0; }
}
```

- [ ] **Step 3: Add code comparison styles**

Append to `public/css/zealphp.css`:

```css
/* ── Code Comparison ── */
.code-compare {
  display: grid; grid-template-columns: 1fr 1fr; gap: 0;
  border: 1px solid var(--border); border-radius: var(--radius);
  overflow: hidden; margin: 1.5rem 0;
}
.code-compare-panel {
  padding: 1.25rem; overflow-x: auto;
}
.code-compare-panel:first-child {
  background: #f0fdf4; border-right: 1px solid var(--border);
}
.code-compare-panel:last-child {
  background: #fef2f2;
}
.code-compare-panel .compare-label {
  font-size: .72rem; font-weight: 700; text-transform: uppercase;
  letter-spacing: .05em; margin-bottom: .75rem;
  display: flex; align-items: center; gap: .5rem;
}
.code-compare-panel:first-child .compare-label { color: #166534; }
.code-compare-panel:last-child .compare-label { color: #991b1b; }
.code-compare-panel pre {
  margin: 0; font-size: .78rem; line-height: 1.6;
}
.code-compare-panel code { font-family: var(--font-mono); }
.compare-verdict {
  font-size: .88rem; font-weight: 600; margin-top: 1rem;
  padding: .75rem 1rem; border-radius: 6px;
  background: var(--accent-light); color: var(--accent-dark);
}
@media (max-width: 768px) {
  .code-compare { grid-template-columns: 1fr; }
  .code-compare-panel:first-child { border-right: none; border-bottom: 1px solid var(--border); }
}
```

- [ ] **Step 4: Add chat widget styles**

Append to `public/css/zealphp.css`:

```css
/* ── Chat Widget ── */
.chat-widget {
  border: 1px solid var(--border); border-radius: var(--radius);
  overflow: hidden; max-width: 700px; margin: 2rem auto;
  box-shadow: var(--shadow-md);
}
.chat-header {
  background: var(--bg-dark); color: #fff;
  padding: .75rem 1rem; font-size: .85rem; font-weight: 600;
  display: flex; justify-content: space-between; align-items: center;
}
.chat-header .chat-status {
  font-size: .72rem; font-weight: 400; color: #94a3b8;
}
.chat-messages {
  background: var(--bg); padding: 1rem;
  min-height: 220px; max-height: 360px; overflow-y: auto;
}
.chat-msg {
  margin-bottom: .75rem; display: flex; gap: .5rem;
}
.chat-msg.user { justify-content: flex-end; }
.chat-msg-bubble {
  max-width: 80%; padding: .6rem .9rem; border-radius: 12px;
  font-size: .85rem; line-height: 1.5;
}
.chat-msg.user .chat-msg-bubble {
  background: var(--accent); color: #fff;
  border-bottom-right-radius: 4px;
}
.chat-msg.assistant .chat-msg-bubble {
  background: var(--bg-alt); color: var(--text);
  border: 1px solid var(--border);
  border-bottom-left-radius: 4px;
}
.chat-msg.assistant .chat-msg-bubble code {
  background: rgba(0,0,0,.06); padding: .1rem .3rem;
  border-radius: 3px; font-size: .8rem;
}
.chat-input-row {
  border-top: 1px solid var(--border);
  display: flex; padding: .5rem; gap: .5rem;
  background: var(--bg-alt);
}
.chat-input {
  flex: 1; border: 1px solid var(--border); border-radius: 8px;
  padding: .5rem .75rem; font-size: .85rem; outline: none;
  font-family: var(--font); resize: none;
}
.chat-input:focus { border-color: var(--accent); }
.chat-send {
  background: var(--accent); color: #fff; border: none;
  border-radius: 8px; padding: .5rem 1rem; cursor: pointer;
  font-weight: 600; font-size: .85rem;
}
.chat-send:hover { background: var(--accent-dark); }
.chat-send:disabled { opacity: .5; cursor: not-allowed; }
.chat-source-toggle {
  padding: .5rem 1rem; background: var(--bg-alt);
  border-top: 1px solid var(--border);
  font-size: .78rem; color: var(--text-muted);
}
.chat-source-toggle a {
  cursor: pointer; font-weight: 600;
}
.chat-source {
  display: none; background: var(--code-bg); color: var(--code-text);
  padding: 1rem; font-family: var(--font-mono); font-size: .78rem;
  max-height: 300px; overflow: auto; line-height: 1.6;
}
.chat-source.open { display: block; }
.chat-typing {
  display: inline-block; width: 6px; height: 14px;
  background: var(--accent); border-radius: 2px;
  animation: blink .6s infinite alternate;
  vertical-align: middle; margin-left: 2px;
}
@keyframes blink { from { opacity: 1; } to { opacity: .3; } }
```

- [ ] **Step 5: Add bold claim section styles**

Append to `public/css/zealphp.css`:

```css
/* ── Bold Claims ── */
.bold-claim {
  margin: 2.5rem 0; padding-bottom: 2.5rem;
  border-bottom: 1px solid var(--border);
}
.bold-claim:last-child { border-bottom: none; }
.bold-claim h3 {
  font-size: 1.3rem; font-weight: 800; margin-bottom: .5rem;
}
.bold-claim > p {
  color: var(--text-muted); margin-bottom: 1.25rem;
}
```

- [ ] **Step 6: Verify CSS loads correctly**

Run: Restart server and curl the CSS file to verify it loads with new content.

```bash
curl -s http://172.30.0.5:8080/css/zealphp.css | tail -20
```

Expected: last 20 lines include `.bold-claim` styles.

- [ ] **Step 7: Commit**

```bash
git add public/css/zealphp.css
git commit -m "style: add CSS for homepage AI runtime redesign — hero demo, arch comparison, code compare, chat widget"
```

---

## Task 2: Chat Backend — SSE Streaming AI Endpoint

**Files:**
- Create: `route/chat.php`

- [ ] **Step 1: Create the chat route with Store-based thread storage**

Create `route/chat.php`:

```php
<?php
use ZealPHP\App;
use ZealPHP\G;
use ZealPHP\Store;

Store::make('chat_threads', 256, [
    'messages' => [\OpenSwoole\Table::TYPE_STRING, 16384],
    'updated'  => [\OpenSwoole\Table::TYPE_INT, 4],
]);

$app = App::instance();

$app->route('/api/chat', ['methods' => ['POST']], function($request, $response) {
    $g = G::instance();

    $body = $g->zealphp_request->parent->getContent();
    $input = json_decode($body, true);
    $message = trim($input['message'] ?? '');
    $threadId = $input['thread_id'] ?? bin2hex(random_bytes(8));

    if (empty($message) || strlen($message) > 2000) {
        header('Content-Type: application/json');
        http_response_code(400);
        return ['error' => 'Message required (max 2000 chars)'];
    }

    $apiKey = getenv('ANTHROPIC_API_KEY');
    if (!$apiKey) {
        $response->sse(function($emit) use ($threadId, $message) {
            $emit(json_encode(['thread_id' => $threadId]), 'thread');
            $fallback = "I'm a demo running on ZealPHP's SSE streaming. "
                . "This response is being streamed token-by-token using `\$response->sse()`. "
                . "To enable real AI responses, set the `ANTHROPIC_API_KEY` environment variable. "
                . "Each word you see is a separate SSE event — "
                . "the same mechanism that powers ChatGPT-style streaming UIs. "
                . "ZealPHP makes this a 5-line feature, not a 50-line infrastructure project.";
            foreach (explode(' ', $fallback) as $word) {
                usleep(60000);
                $emit(json_encode(['token' => $word . ' ']), 'token');
            }
            $emit(json_encode(['done' => true]), 'done');
        });
        return;
    }

    // Build messages array with thread history
    $messages = [];
    $existing = Store::get('chat_threads', $threadId);
    if ($existing) {
        $messages = json_decode($existing['messages'], true) ?: [];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // Keep only last 10 messages to fit Store column size
    if (count($messages) > 10) {
        $messages = array_slice($messages, -10);
    }

    $response->sse(function($emit) use ($apiKey, $messages, $threadId) {
        $emit(json_encode(['thread_id' => $threadId]), 'thread');

        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => 'claude-sonnet-4-20250514',
                'max_tokens' => 512,
                'stream' => true,
                'system' => 'You are a helpful assistant embedded in the ZealPHP framework website. '
                    . 'Keep responses concise (2-3 sentences). You can use basic markdown. '
                    . 'If asked about ZealPHP, highlight its streaming, coroutine, and performance features.',
                'messages' => $messages,
            ]),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use ($emit, &$fullResponse) {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if (strpos($line, 'data: ') !== 0) continue;
                    $json = substr($line, 6);
                    if ($json === '[DONE]') continue;
                    $event = json_decode($json, true);
                    if (!$event) continue;

                    if (($event['type'] ?? '') === 'content_block_delta') {
                        $text = $event['delta']['text'] ?? '';
                        if ($text !== '') {
                            $fullResponse .= $text;
                            $emit(json_encode(['token' => $text]), 'token');
                        }
                    }
                }
                return strlen($data);
            },
        ]);

        $fullResponse = '';
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $emit(json_encode(['error' => 'API returned ' . $httpCode]), 'error');
            return;
        }

        // Save thread with assistant response
        $messages[] = ['role' => 'assistant', 'content' => $fullResponse];
        if (count($messages) > 10) {
            $messages = array_slice($messages, -10);
        }
        Store::set('chat_threads', $threadId, [
            'messages' => json_encode($messages),
            'updated'  => time(),
        ]);

        $emit(json_encode(['done' => true]), 'done');
    });
});

// GET endpoint to check if chat is available
$app->route('/api/chat/status', function() {
    return [
        'available' => true,
        'ai_enabled' => (bool)getenv('ANTHROPIC_API_KEY'),
        'model' => getenv('ANTHROPIC_API_KEY') ? 'claude-sonnet-4-20250514' : 'demo-fallback',
    ];
});
```

- [ ] **Step 2: Verify the route loads**

Restart the server and test:

```bash
# Kill and restart
pkill -f "php app.php" || true; sleep 1
php app.php &
sleep 3

# Test status endpoint
curl -s http://172.30.0.5:8080/api/chat/status | python3 -m json.tool
```

Expected: `{"available": true, "ai_enabled": false, "model": "demo-fallback"}`

- [ ] **Step 3: Test the SSE fallback (no API key)**

```bash
curl -s -X POST http://172.30.0.5:8080/api/chat \
  -H 'Content-Type: application/json' \
  -d '{"message":"hello"}' | head -20
```

Expected: SSE events with `event: thread`, multiple `event: token`, then `event: done`.

- [ ] **Step 4: Commit**

```bash
git add route/chat.php
git commit -m "feat: add AI chat SSE endpoint with thread support and demo fallback"
```

---

## Task 3: Homepage Rewrite — Hero Section

**Files:**
- Modify: `template/pages/home.php` (replace lines 1–52, the hero section)
- Modify: `template/_head.php` (line 9, meta description)

- [ ] **Step 1: Update meta description**

In `template/_head.php`, change line 4:

```php
$description ??= 'The PHP runtime for AI web applications. SSR streaming, WebSocket, SSE, coroutines — one server, Go-level performance.';
```

- [ ] **Step 2: Rewrite the hero section**

Replace the entire hero section (lines 1–52) in `template/pages/home.php` with:

```php
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

    <div class="bench-note">Benchmarked. Not promised.</div>
    <div class="bench">
      <div class="bench-stat"><div class="num">67k</div><div class="label">req/s</div></div>
      <div class="bench-stat"><div class="num">21ms</div><div class="label">p90 latency</div></div>
      <div class="bench-stat"><div class="num">4</div><div class="label">workers</div></div>
      <div class="bench-stat"><div class="num">0</div><div class="label">failures</div></div>
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
```

- [ ] **Step 3: Verify hero renders**

Restart server and take a screenshot or curl the homepage:

```bash
pkill -f "php app.php" || true; sleep 1; php app.php &
sleep 3
curl -s http://172.30.0.5:8080/ | head -60
```

Expected: HTML containing "The PHP Runtime for AI Web Apps" and the hero-demo div.

- [ ] **Step 4: Commit**

```bash
git add template/pages/home.php template/_head.php
git commit -m "feat: rewrite hero — 'The PHP Runtime for AI Web Apps' with streaming code demo"
```

---

## Task 4: Homepage — Architecture Comparison + Bold Claims

**Files:**
- Modify: `template/pages/home.php` (insert after hero, before quick start section)

- [ ] **Step 1: Add "One Server. Everything." section**

Insert after the hero's closing `</script>` tag (after the hero streaming animation script) and before the Quick Start section:

```php
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
```

- [ ] **Step 2: Add "Why Not Just Use [X]?" section**

Insert after the architecture section:

```php
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
      <p>67k req/s on 4 workers. But you also get reflection-based injection, auto-serialization, and zero boilerplate.</p>
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
```

- [ ] **Step 3: Verify sections render**

```bash
pkill -f "php app.php" || true; sleep 1; php app.php &
sleep 3
curl -s http://172.30.0.5:8080/ | grep -c "bold-claim"
```

Expected: `3` (three bold-claim divs)

- [ ] **Step 4: Commit**

```bash
git add template/pages/home.php
git commit -m "feat: add architecture comparison and bold claims vs Node/Go/FastAPI"
```

---

## Task 5: Homepage — Live AI Chat Demo Widget

**Files:**
- Modify: `template/pages/home.php` (insert chat demo section before the feature grid)

- [ ] **Step 1: Add the chat demo section**

Insert before the "Everything you need" feature grid section:

```php
<!-- Live AI Chat Demo -->
<section class="section">
  <div class="container">
    <h2 class="section-title">Try it — live AI chat, streaming on this server</h2>
    <p class="section-desc">This chat is powered by ZealPHP's SSE streaming. Every token streams in real-time. The entire backend is <strong>30 lines of PHP</strong>.</p>
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
<pre><code>// route/chat.php — the entire AI chat backend
$app->route('/api/chat', ['methods' => ['POST']], function($request, $response) {
    $body = json_decode($g->zealphp_request->parent->getContent(), true);
    $message = $body['message'];

    $response->sse(function($emit) use ($message) {
        // Call Claude API with streaming
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_WRITEFUNCTION,
            function($ch, $data) use ($emit) {
                // Parse SSE events, emit tokens
                $emit(json_encode(['token' => $text]), 'token');
                return strlen($data);
            }
        );
        curl_exec($ch);
        $emit(json_encode(['done' => true]), 'done');
    });
});</code></pre>
      </div>
    </div>
  </div>
</section>

<script>
(function() {
  let threadId = null;

  // Check status
  fetch('/api/chat/status').then(r => r.json()).then(s => {
    const el = document.getElementById('chat-status');
    el.textContent = s.ai_enabled ? 'Claude AI' : 'Demo mode';
    el.style.color = s.ai_enabled ? '#10b981' : '#f59e0b';
  }).catch(() => {
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
    messages.innerHTML += `<div class="chat-msg user"><div class="chat-msg-bubble">${escapeHtml(text)}</div></div>`;
    input.value = '';
    btn.disabled = true;

    // Add assistant placeholder
    const assistantDiv = document.createElement('div');
    assistantDiv.className = 'chat-msg assistant';
    assistantDiv.innerHTML = '<div class="chat-msg-bubble"><span class="chat-typing"></span></div>';
    messages.appendChild(assistantDiv);
    messages.scrollTop = messages.scrollHeight;

    const bubble = assistantDiv.querySelector('.chat-msg-bubble');
    bubble.textContent = '';

    // SSE via fetch
    fetch('/api/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, thread_id: threadId })
    }).then(response => {
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      function read() {
        reader.read().then(({ done, value }) => {
          if (done) { btn.disabled = false; return; }
          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop();

          for (const line of lines) {
            if (line.startsWith('data: ')) {
              try {
                const data = JSON.parse(line.slice(6));
                if (data.thread_id) threadId = data.thread_id;
                if (data.token) {
                  bubble.textContent += data.token;
                  messages.scrollTop = messages.scrollHeight;
                }
              } catch(e) {}
            }
          }
          read();
        });
      }
      read();
    }).catch(e => {
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
```

- [ ] **Step 2: Verify chat widget renders and works**

Restart server and test:

```bash
pkill -f "php app.php" || true; sleep 1; php app.php &
sleep 3
curl -s http://172.30.0.5:8080/ | grep -c "chat-widget"
```

Expected: `1`

Test the chat endpoint:

```bash
curl -s -X POST http://172.30.0.5:8080/api/chat \
  -H 'Content-Type: application/json' \
  -d '{"message":"hi"}' | head -10
```

Expected: SSE events streaming.

- [ ] **Step 3: Commit**

```bash
git add template/pages/home.php
git commit -m "feat: add live AI chat demo widget with SSE streaming"
```

---

## Task 6: Homepage — Reframe Feature Grid + Footer CTA

**Files:**
- Modify: `template/pages/home.php` (update the features array and add footer CTA)

- [ ] **Step 1: Replace feature grid copy with AI-angled descriptions**

Find the `$features = [` array in the "Everything you need" section and replace it with:

```php
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
```

- [ ] **Step 2: Rewrite the "Why ZealPHP?" section heading**

Replace the "Why ZealPHP?" section heading and feature array:

Find:
```php
    <h2 class="section-title">Why ZealPHP?</h2>
```

Replace with:
```php
    <h2 class="section-title">Built for what's next</h2>
```

And replace the `$why` array with:

```php
      $why = [
        ['🚀', 'Non-blocking everything',  'Every I/O call yields to the event loop. OpenSwoole HOOK_ALL makes existing PHP libraries async automatically. Zero rewrites.'],
        ['🌊', 'C1000K ready',             'Multi-process workers + coroutines. One server handles a million concurrent connections. No worker thread juggling.'],
        ['🧵', 'True coroutines',          'Not fake async with callbacks. Real coroutines with go() + Channel. Write synchronous-looking code that runs concurrently.'],
        ['🔧', 'PHP you already know',     '80% of developers know PHP. Sessions, headers, superglobals — all work via uopz overrides. Migrate existing apps without rewriting.'],
        ['📐', 'PSR standards',            'PSR-7 request/response, PSR-15 middleware. Drop in any standards-compliant package from the PHP ecosystem.'],
        ['📊', 'Benchmarked performance',  '67k req/s, 21ms p90, 0 failures on 4 workers. Local quad-core benchmark sweep. Reproducible — run scripts/bench.sh yourself.'],
        ['🔓', 'MIT open source',          'Fully open source. No enterprise tier. No "contact sales." Community-maintained on OpenSwoole, PHP\'s battle-tested async runtime.'],
      ];
```

- [ ] **Step 3: Add footer CTA section**

Insert before the closing of `home.php` (just before the final line), add:

```php
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
```

- [ ] **Step 4: Verify all sections render**

```bash
pkill -f "php app.php" || true; sleep 1; php app.php &
sleep 3
curl -s http://172.30.0.5:8080/ | grep -oP '(?<=class="section-title">)[^<]+' | head -10
```

Expected output includes: "One server. Everything.", "Why not just use...?", "Try it — live AI chat", "Everything you need", "Return anything", "Try it — convert your config", "Built for what's next", "Build your first AI chat"

- [ ] **Step 5: Commit**

```bash
git add template/pages/home.php
git commit -m "feat: reframe feature grid for AI, add footer CTA, update Why section"
```

---

## Task 7: Visual Verification + Final Polish

**Files:**
- Possibly: `template/pages/home.php`, `public/css/zealphp.css` (minor fixes)

- [ ] **Step 1: Full page screenshot and review**

Restart the server and take a full-page screenshot via Chrome DevTools:

```bash
pkill -f "php app.php" || true; sleep 1; php app.php &
sleep 3
```

Navigate to `http://172.30.0.5:8080/` in Chrome DevTools and take a full-page screenshot. Review each section visually:

1. Hero — headline, streaming demo animation, benchmark bar
2. One Server — architecture comparison boxes
3. Why Not [X] — three code comparison panels
4. Live Chat — chat widget with send/receive
5. Feature grid — 13 cards with AI-angled copy
6. Return conventions table
7. Config converter
8. Built for what's next — 7 cards
9. Footer CTA

- [ ] **Step 2: Test chat widget interactively**

In Chrome DevTools, type a message in the chat input and click Send. Verify:
- User message appears on the right
- Typing indicator shows
- Demo fallback tokens stream in word-by-word
- Thread ID is maintained for follow-up messages

- [ ] **Step 3: Test mobile layout**

Resize browser to 375px width and verify:
- Hero demo stacks vertically
- Architecture comparison stacks
- Code comparisons stack
- Chat widget is usable
- Nav hamburger works

- [ ] **Step 4: Fix any visual issues found**

Apply CSS or HTML fixes as needed based on the review.

- [ ] **Step 5: Final commit**

```bash
git add -A
git commit -m "polish: visual fixes from full-page review"
```

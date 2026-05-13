<?php use ZealPHP\App; ?>
<section class="section">
<div class="container">
<h1 class="section-title">SSR Streaming</h1>
<p class="section-desc">Send HTML to the browser progressively as coroutines resolve — like React's <code>renderToPipeableStream</code>, but in PHP. Three APIs, same result: the browser paints content incrementally.</p>

<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:2rem">
  <div class="card"><div class="card-icon">📤</div><h3>Generator yield</h3><p>Return a <code>\Generator</code> from your handler. Each <code>yield $html</code> flushes immediately. No API changes needed.</p></div>
  <div class="card"><div class="card-icon">🔁</div><h3>stream() callback</h3><p>Get a <code>$write(string)</code> closure. Headers flush before callback runs. Fine-grained control.</p></div>
  <div class="card"><div class="card-icon">📡</div><h3>sse()</h3><p>Server-Sent Events. Get an <code>$emit($data, $event, $id)</code> closure. JS <code>EventSource</code> compatible.</p></div>
</div>

<?php App::render('/components/_code', [
    'label' => 'Generator SSR — parallel coroutine fetches',
    'code'  => <<<'PHP'
$app->route('/stream/ssr', function() {
    $start = microtime(true);
    return (function() use ($start) {
        // 1. Shell sent to browser immediately
        yield '<html><body><h1>Page</h1>';

        // 2. Parallel fetch via coroutines
        $ch = new Channel(2);
        go(fn() => [$ch->push(fetchUsers()), co::sleep(1)]);
        go(fn() => [$ch->push(fetchPosts()), co::sleep(2)]);

        // 3. Stream each section as it resolves
        yield '<div id="users">' . $ch->pop() . '</div>';  // arrives at ~1s
        yield '<div id="posts">' . $ch->pop() . '</div>';  // arrives at ~2s
        yield '</body></html>';
    })();
    // Total: ~2s (parallel), not 3s (sequential)
});
PHP]); ?>

<h2 style="margin:2rem 0 1rem">Live streaming demos</h2>

<!-- Generator SSR -->
<div class="inject-case" style="margin-bottom:1.5rem">
  <div class="inject-case-header"><span class="badge badge-get">GET</span><code>/stream/ssr — Generator yield</code></div>
  <div style="padding:1rem;background:var(--bg-alt)">
    <p style="font-size:.85rem;margin-bottom:.75rem">Opens a streaming connection. Watch sections appear one by one (1s, then 2s):</p>
    <button class="btn btn-primary btn-sm" onclick="runStreamSSR()">▶ Run Generator SSR</button>
    <div id="ssr-out" style="margin-top:.75rem;font-family:var(--font-mono);font-size:.78rem;padding:.75rem;background:var(--code-bg);color:var(--code-text);border-radius:6px;min-height:60px">Click Run to start…</div>
  </div>
</div>

<!-- SSE demo -->
<div class="inject-case">
  <div class="inject-case-header"><span class="badge badge-sse">SSE</span><code>/stream/events — Server-Sent Events</code></div>
  <div style="padding:1rem;background:var(--bg-alt)">
    <p style="font-size:.85rem;margin-bottom:.75rem">10 events, 1 second apart. EventSource reconnects automatically on drop.</p>
    <button class="btn btn-primary btn-sm" onclick="startSSE()">▶ Connect EventSource</button>
    <button class="btn btn-ghost btn-sm" onclick="stopSSE()">■ Disconnect</button>
    <div class="sse-log" id="sse-out" style="margin-top:.75rem">Click Connect to start…</div>
  </div>
</div>

<?php App::render('/components/_code', [
    'label' => 'SSE — $response->sse()',
    'code'  => <<<'PHP'
$app->route('/stream/events', function($response) {
    $response->sse(function($emit) {
        $emit(json_encode(['status' => 'connected']), 'open');
        for ($i = 1; $i <= 10; $i++) {
            co::sleep(1);
            $emit(json_encode(['tick' => $i, 'time' => date('H:i:s')]), 'tick', (string)$i);
        }
        $emit(json_encode(['done' => true]), 'done');
    });
});

// Browser:
// const es = new EventSource('/stream/events');
// es.addEventListener('tick', e => console.log(JSON.parse(e.data)));
PHP]); ?>

</div>
</section>

<script>
async function runStreamSSR() {
  const out = document.getElementById('ssr-out');
  out.textContent = 'Connecting…';
  const res = await fetch('/stream/ssr');
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  out.textContent = '';
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    const chunk = decoder.decode(value, { stream: true });
    out.textContent += chunk;
  }
}

let es = null;
function startSSE() {
  if (es) es.close();
  const log = document.getElementById('sse-out');
  log.textContent = '';
  es = new EventSource('/stream/events');
  const addLine = (text, cls) => {
    const el = document.createElement('div');
    el.className = 'sse-event ' + (cls || '');
    el.textContent = new Date().toLocaleTimeString() + ' — ' + text;
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
  };
  es.addEventListener('open', e => addLine(e.data, 'open'));
  es.addEventListener('tick', e => addLine(e.data, 'tick'));
  es.addEventListener('done', e => { addLine(e.data, 'done'); es.close(); });
}
function stopSSE() { if (es) { es.close(); es = null; } }
</script>

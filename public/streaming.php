<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>ZealPHP · SSR Streaming Hub</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:system-ui,sans-serif;background:#f0f4f8;color:#1a1a2e;line-height:1.6}
    header{background:#1a1a2e;color:#fff;padding:2rem;text-align:center}
    header h1{font-size:2rem;margin-bottom:.3rem}
    header p{color:#8892b0;font-size:1rem}
    .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1.5rem;max-width:1100px;margin:2rem auto;padding:0 1.5rem}
    .card{background:#fff;border-radius:12px;padding:1.5rem;box-shadow:0 2px 8px rgba(0,0,0,.07);border-top:4px solid #0070f3}
    .card.sse{border-top-color:#7928ca}.card.stream{border-top-color:#ff6b35}
    .card h2{font-size:1.1rem;margin-bottom:.5rem}
    .badge{display:inline-block;border-radius:4px;padding:2px 8px;font-size:.72rem;font-weight:600;margin-bottom:.6rem}
    .badge.gen{background:#e0f0ff;color:#005}
    .badge.str{background:#fff0d0;color:#630}
    .badge.sse-b{background:#f0e0ff;color:#400}
    .card p{font-size:.9rem;color:#555;margin-bottom:1rem}
    .card code{font-size:.8rem;background:#f4f4f4;padding:.1rem .35rem;border-radius:3px}
    .btn{display:inline-block;padding:.5rem 1.1rem;background:#0070f3;color:#fff;text-decoration:none;border-radius:6px;font-size:.88rem;transition:background .15s}
    .btn:hover{background:#005cc5}
    .btn.sec{background:#6e7680;margin-left:.5rem}
    .btn.sec:hover{background:#555}
    /* SSE live demo */
    #sse-box{background:#0d1117;border-radius:8px;padding:1rem;min-height:120px;font-family:monospace;font-size:.8rem;color:#58a6ff;margin:1rem 0;overflow-y:auto;max-height:200px}
    #sse-box .ev{margin:2px 0}
    #sse-box .ev.open{color:#3fb950}
    #sse-box .ev.tick{color:#58a6ff}
    #sse-box .ev.done{color:#f78166;font-weight:bold}
    .sse-controls{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.5rem}
    .sse-controls button{padding:.35rem .9rem;border:1px solid #ccc;border-radius:5px;cursor:pointer;font-size:.84rem;background:#fff}
    .sse-controls button:hover{background:#f0f0f0}
    /* curl snippet */
    .curl{background:#1e1e2e;color:#cdd6f4;border-radius:8px;padding:.8rem 1rem;font-family:monospace;font-size:.78rem;margin-top:1rem;overflow-x:auto;white-space:nowrap}
    footer{text-align:center;color:#8892b0;font-size:.82rem;padding:2rem;margin-top:1rem}
  </style>
</head>
<body>

<header>
  <h1>ZealPHP SSR Streaming</h1>
  <p>Three ways to stream — Generator <code>yield</code>, <code>stream()</code> callback, and Server-Sent Events</p>
</header>

<div class="grid">

  <!-- Generator SSR card -->
  <div class="card">
    <span class="badge gen">Generator yield</span>
    <h2>Parallel SSR</h2>
    <p>
      Route handler returns a <code>\Generator</code> — ZealPHP streams each
      <code>yield</code>ed chunk straight to the browser. Launch coroutines with
      <code>go()</code> + <code>Channel</code> for parallel data fetching; sections
      stream in as they resolve. Total time = slowest fetch, not sum.
    </p>
    <div class="curl">curl -N http://localhost:8080/stream/ssr</div>
    <br>
    <a class="btn" href="/stream/ssr" target="_blank">Open demo</a>
  </div>

  <!-- stream() callback card -->
  <div class="card stream">
    <span class="badge str">$response->stream()</span>
    <h2>Streaming Callback</h2>
    <p>
      <code>$response->stream($fn)</code> flushes headers immediately and gives
      <code>$fn</code> a <code>$write(string)</code> closure. Call it as many
      times as needed — each call reaches the browser instantly. Use
      <code>co::sleep()</code> between writes to keep the event loop free.
    </p>
    <div class="curl">curl -N http://localhost:8080/stream/words</div>
    <br>
    <a class="btn" href="/stream/words" target="_blank">Open demo</a>
  </div>

  <!-- SSE card — live demo embedded -->
  <div class="card sse">
    <span class="badge sse-b">$response->sse()</span>
    <h2>Server-Sent Events — live</h2>
    <p>
      <code>$response->sse($fn)</code> sets <code>text/event-stream</code> headers
      and provides <code>$emit($data, $event, $id)</code>. The browser's
      <code>EventSource</code> API receives events as they arrive.
    </p>
    <div class="sse-controls">
      <button onclick="sseConnect()">Connect</button>
      <button onclick="sseDisconnect()">Disconnect</button>
      <button onclick="sseClear()">Clear</button>
    </div>
    <div id="sse-box"><span style="color:#555">Click Connect to start…</span></div>
    <div class="curl">curl -N http://localhost:8080/stream/events</div>
  </div>

</div>

<footer>
  ZealPHP · OpenSwoole · PHP 8.3 &nbsp;|&nbsp;
  <a href="https://github.com/sibidharan/zealphp" style="color:#0070f3">GitHub</a>
</footer>

<script>
  let es = null;

  function sseConnect() {
    if (es) es.close();
    sseClear();
    sseLog('connecting…', '');

    es = new EventSource('/stream/events');

    es.addEventListener('open', e => {
      const d = JSON.parse(e.data);
      sseLog('● ' + d.message + ' @ ' + d.time, 'open');
    });

    es.addEventListener('tick', e => {
      const d = JSON.parse(e.data);
      sseLog(`[${d.tick.toString().padStart(2,'0')}] ${d.message}  ${d.time}`, 'tick');
    });

    es.addEventListener('done', e => {
      const d = JSON.parse(e.data);
      sseLog('■ ' + d.message, 'done');
      es.close();
    });

    es.onerror = () => sseLog('connection error / closed', 'done');
  }

  function sseDisconnect() {
    if (es) { es.close(); es = null; }
    sseLog('disconnected', 'done');
  }

  function sseLog(text, cls) {
    const box = document.getElementById('sse-box');
    if (box.children.length === 1 && !box.children[0].classList.length) box.innerHTML = '';
    const el = document.createElement('div');
    el.className = 'ev ' + cls;
    el.textContent = text;
    box.appendChild(el);
    box.scrollTop = box.scrollHeight;
  }

  function sseClear() {
    document.getElementById('sse-box').innerHTML = '';
  }
</script>

</body>
</html>

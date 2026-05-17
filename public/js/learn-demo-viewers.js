// Viewer-page handlers for the streaming demo shells at /demo/view/streaming/*.
// Loaded site-wide via _head.php with cache-bust. Uses event delegation so the
// buttons keep working across htmx swaps and after reattach.

(function () {
  let eventSource = null;

  // Streaming Generator SSR — fetch and render chunks as they arrive
  async function runSSR(button) {
    const out = document.getElementById(button.dataset.target || 'demo-ssr-out');
    if (!out) return;
    button.disabled = true;
    out.textContent = '';
    const ts0 = performance.now();
    try {
      const res = await fetch(button.dataset.demoUrl || '/stream/ssr');
      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        out.textContent += decoder.decode(value, { stream: true });
        out.scrollTop = out.scrollHeight;
      }
      const dt = Math.round(performance.now() - ts0);
      const note = document.createElement('div');
      note.className = 'ev done';
      note.textContent = `--- stream closed in ${dt} ms ---`;
      out.appendChild(note);
    } catch (e) {
      const err = document.createElement('div');
      err.className = 'ev err';
      err.textContent = 'Error: ' + e.message;
      out.appendChild(err);
    } finally {
      button.disabled = false;
    }
  }

  // $response->stream() word-by-word — fetch and render text progressively
  async function runStream(button) {
    const out = document.getElementById(button.dataset.target || 'demo-stream-out');
    if (!out) return;
    button.disabled = true;
    out.textContent = '';
    try {
      const res = await fetch(button.dataset.demoUrl || '/stream/words');
      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        out.textContent += decoder.decode(value, { stream: true });
        out.scrollTop = out.scrollHeight;
      }
    } catch (e) {
      out.textContent = 'Error: ' + e.message;
    } finally {
      button.disabled = false;
    }
  }

  // Server-Sent Events — EventSource lifecycle
  function startSSE(button) {
    const out = document.getElementById(button.dataset.target || 'demo-sse-out');
    if (!out) return;
    stopSSE();
    out.textContent = '';
    const url = button.dataset.demoUrl || '/stream/events';
    eventSource = new EventSource(url);
    const addLine = (data, cls) => {
      const el = document.createElement('div');
      el.className = 'ev ' + (cls || '');
      const ts = document.createElement('span');
      ts.className = 'ts';
      ts.textContent = new Date().toLocaleTimeString();
      el.appendChild(ts);
      el.appendChild(document.createTextNode(data));
      out.appendChild(el);
      out.scrollTop = out.scrollHeight;
    };
    eventSource.addEventListener('open', e => addLine(e.data || '(connected)', 'open'));
    eventSource.addEventListener('tick', e => addLine(e.data, 'tick'));
    eventSource.addEventListener('done', e => { addLine(e.data, 'done'); stopSSE(); });
    eventSource.onerror = () => addLine('connection error', 'err');
  }

  function stopSSE() {
    if (eventSource) { eventSource.close(); eventSource = null; }
  }

  // One global event delegate — survives htmx swaps because it lives on document
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-viewer-action]');
    if (!b) return;
    const action = b.dataset.viewerAction;
    if (action === 'ssr')      runSSR(b);
    else if (action === 'stream') runStream(b);
    else if (action === 'sse-start') startSSE(b);
    else if (action === 'sse-stop')  stopSSE();
  });
})();

// WebSocket cross-tab counter demo on /learn/websocket. Uses event
// delegation so it keeps working after htmx swaps to/from this lesson.
(function () {
  let ws = null;
  let connected = false;

  function update(value) {
    document.querySelectorAll('[data-ws-counter-value]').forEach(el => {
      el.textContent = value;
      el.classList.remove('flash');
      void el.offsetWidth;  // force reflow to restart animation
      el.classList.add('flash');
    });
  }

  function setStatus(text, cls) {
    document.querySelectorAll('[data-ws-counter-status]').forEach(el => {
      el.textContent = text;
      el.className = 'ws-counter-status ' + (cls || '');
    });
  }

  function connect() {
    if (ws) return;
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    ws = new WebSocket(proto + '//' + location.host + '/ws/counter-demo');
    setStatus('connecting…', 'connecting');
    ws.onopen = () => { connected = true; setStatus('connected', 'open'); };
    ws.onmessage = e => {
      try {
        const m = JSON.parse(e.data);
        if (typeof m.value === 'number') update(m.value);
      } catch (_) {}
    };
    ws.onclose = () => { connected = false; ws = null; setStatus('disconnected — click +1 to reconnect', 'closed'); };
    ws.onerror = () => { setStatus('error', 'err'); };
  }

  async function bump() {
    if (!connected) connect();
    try { await fetch('/api/learn/demo/counter-bump', { method: 'POST' }); }
    catch (e) { setStatus('bump failed: ' + e.message, 'err'); }
  }

  async function reset() {
    try { await fetch('/api/learn/demo/counter-reset', { method: 'POST' }); }
    catch (e) { setStatus('reset failed: ' + e.message, 'err'); }
  }

  document.addEventListener('click', e => {
    const t = e.target.closest('[data-ws-counter]');
    if (!t) return;
    const action = t.dataset.wsCounter;
    if (action === 'bump')  bump();
    if (action === 'reset') reset();
  });

  // Auto-connect when the lesson page is visible
  function maybeConnect() {
    if (document.querySelector('[data-ws-counter-value]')) connect();
  }
  document.addEventListener('DOMContentLoaded', maybeConnect);
  document.addEventListener('htmx:afterSettle', maybeConnect);
})();

// Session-counter cross-tab live sync (Sessions lesson + /demo/view/sessions/counter).
// Same PHPSESSID = same session: when one tab clicks +1, the WS broadcasts the
// new button HTML to every other tab in the session. The clicking tab gets the
// update via htmx; this handler just keeps the rest in sync.
(function () {
  let ws = null;

  function setStatus(text, cls) {
    document.querySelectorAll('[data-session-counter-status]').forEach(el => {
      el.textContent = text;
      el.className = 'ws-counter-status ' + (cls || '');
    });
  }

  function applyHtml(html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html.trim();
    const incoming = tmp.firstElementChild;
    if (!incoming) return;
    const existing = document.getElementById('session-counter-btn');
    if (existing) {
      existing.outerHTML = incoming.outerHTML;
      // Re-process htmx attrs on the new node so it stays clickable
      if (window.htmx) htmx.process(document.getElementById('session-counter-btn'));
    }
  }

  function connect() {
    if (ws || !document.querySelector('[data-session-counter]')) return;
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    ws = new WebSocket(proto + '//' + location.host + '/ws/session-counter');
    setStatus('connecting…', 'connecting');
    ws.onopen    = () => setStatus('connected — other tabs in this session will sync live', 'open');
    ws.onmessage = e => { if (e.data && e.data !== 'pong') applyHtml(e.data); };
    ws.onclose   = () => { ws = null; setStatus('disconnected', 'closed'); };
    ws.onerror   = () => setStatus('error', 'err');
  }

  document.addEventListener('DOMContentLoaded', connect);
  document.addEventListener('htmx:afterSettle', connect);
})();

// Store-demo cross-tab live sync (/demo/view/store/incr + /demo/view/store/set-get).
// Mirrors the [data-ws-counter] handler but talks to /ws/store-demo and
// shows both a single integer (incr viewer) and the full row payload
// (set-get viewer).
(function () {
  let ws = null;
  let lastRow = { n: 0, name: '', who: '', ts: 0 };

  function setStatus(text, cls) {
    document.querySelectorAll('[data-store-status]').forEach(el => {
      el.textContent = text;
      el.className = 'ws-counter-status ' + (cls || '');
    });
  }

  function render(row) {
    lastRow = row;
    // incr viewer: just the counter value
    document.querySelectorAll('[data-store-counter-value]').forEach(el => {
      const oldVal = el.textContent;
      el.textContent = String(row.n ?? 0);
      if (oldVal !== el.textContent) {
        el.classList.remove('flash');
        void el.offsetWidth;
        el.classList.add('flash');
      }
    });
    // set-get viewer: full row payload
    document.querySelectorAll('[data-store-row]').forEach(el => {
      el.textContent = JSON.stringify(row, null, 2);
    });
  }

  function connect() {
    if (ws) return;
    const wantsConnection = document.querySelector('[data-store-counter-value], [data-store-row], [data-store-form]');
    if (!wantsConnection) return;
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    ws = new WebSocket(proto + '//' + location.host + '/ws/store-demo');
    setStatus('connecting…', 'connecting');
    ws.onopen    = () => setStatus('connected — every tab will see the row update live', 'open');
    ws.onmessage = e => {
      try {
        const row = JSON.parse(e.data);
        if (!row || typeof row !== 'object') return;
        if (row.type === 'heartbeat') return;  // global WS keepalive, not a row update
        render(row);
      } catch (_) {}
    };
    ws.onclose   = () => { ws = null; setStatus('disconnected — click +1 or Write row to reconnect', 'closed'); };
    ws.onerror   = () => setStatus('error', 'err');
  }

  async function bump()  { try { await fetch('/api/learn/demo/store-bump',  { method: 'POST' }); } catch (e) { setStatus('bump failed: '  + e.message, 'err'); } }
  async function reset() { try { await fetch('/api/learn/demo/store-reset', { method: 'POST' }); } catch (e) { setStatus('reset failed: ' + e.message, 'err'); } }
  async function write(form) {
    const fd = new FormData(form);
    try {
      const res = await fetch('/api/learn/demo/store-write', { method: 'POST', body: fd });
      if (!res.ok) setStatus('write failed: HTTP ' + res.status, 'err');
    } catch (e) { setStatus('write failed: ' + e.message, 'err'); }
  }

  document.addEventListener('click', e => {
    const t = e.target.closest('[data-store-counter]');
    if (!t) return;
    if (t.dataset.storeCounter === 'bump')  { connect(); bump(); }
    if (t.dataset.storeCounter === 'reset') { connect(); reset(); }
  });

  document.addEventListener('submit', e => {
    const f = e.target.closest('[data-store-form]');
    if (!f) return;
    e.preventDefault();
    connect();
    write(f);
    // Don't reset; user might want to bump-edit. Clear name only.
    const name = f.querySelector('input[name="name"]');
    if (name) name.value = '';
  });

  document.addEventListener('DOMContentLoaded', connect);
  document.addEventListener('htmx:afterSettle', connect);
})();

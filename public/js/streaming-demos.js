// /streaming page demos. Loaded once in <head>; uses event delegation so
// it survives htmx swaps (inline <script> blocks in swapped HTML never re-run).

(function () {
  let es = null;

  async function runStreamSSR(out) {
    out.textContent = 'Connecting…';
    try {
      const res = await fetch('/stream/ssr');
      const reader = res.body.getReader();
      const decoder = new TextDecoder();
      out.textContent = '';
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        out.textContent += decoder.decode(value, { stream: true });
      }
    } catch (e) {
      out.textContent = 'Error: ' + e.message;
    }
  }

  function startSSE(log) {
    if (es) es.close();
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
    es.addEventListener('done', e => { addLine(e.data, 'done'); es.close(); es = null; });
  }

  function stopSSE() {
    if (es) { es.close(); es = null; }
  }

  // Event delegation on document — handlers stay live across htmx swaps.
  document.addEventListener('click', e => {
    const t = e.target.closest('[data-streaming-demo]');
    if (!t) return;
    const action = t.dataset.streamingDemo;
    if (action === 'ssr') {
      const out = document.getElementById('ssr-out');
      if (out) runStreamSSR(out);
    } else if (action === 'sse-start') {
      const log = document.getElementById('sse-out');
      if (log) startSSE(log);
    } else if (action === 'sse-stop') {
      stopSSE();
    }
  });
})();

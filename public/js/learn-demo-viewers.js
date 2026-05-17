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

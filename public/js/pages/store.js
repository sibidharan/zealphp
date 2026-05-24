// /store + /pubsub live demo widget — shared.
// Each [data-action="store-demo-*"] button fires a GET against the
// matching /demo/* endpoint and pretty-prints the JSON into the
// nearest .demo-output JSON pane.
//
// The page is server-state-aware: it shows a hint banner when the
// running server's Store backend is the Table backend (pub/sub
// endpoints will return ok:false in that case).

(function () {
  'use strict';

  const fmt = (obj) => JSON.stringify(obj, null, 2);
  const stamp = () => new Date().toLocaleTimeString();

  function paneFor(btn) {
    // pane = sibling element with class .demo-json-pane
    const wrap = btn.closest('.demo-output, .inject-case-body, .store-demo-panel') || document;
    return wrap.querySelector('.demo-json-pane');
  }

  async function hit(btn, url) {
    const pane = paneFor(btn);
    if (!pane) { return; }
    pane.textContent = `→ ${url}\n   (loading…)`;
    pane.classList.remove('is-error');
    try {
      const r = await fetch(url, { credentials: 'same-origin' });
      const text = await r.text();
      let pretty;
      try { pretty = fmt(JSON.parse(text)); } catch (_) { pretty = text; }
      pane.textContent = `[${stamp()}] ${url}\n${'─'.repeat(40)}\n${pretty}`;
    } catch (err) {
      pane.classList.add('is-error');
      pane.textContent = `[${stamp()}] ERROR fetching ${url}\n${err.message || err}`;
    }
  }

  function wire() {
    const handlers = {
      'store-demo-roundtrip':         '/demo/store-roundtrip',
      'store-demo-publish':           '/demo/pubsub/publish?channel=demo:pubsub&msg=hello-from-' + Math.floor(Math.random() * 999),
      'store-demo-publish-reliable':  '/demo/pubsub/publish-reliable?stream=demo:reliable&msg=durable-' + Math.floor(Math.random() * 999),
      'store-demo-pubsub-log':        '/demo/pubsub/log',
    };
    Object.entries(handlers).forEach(([action, url]) => {
      document.querySelectorAll(`[data-action="${action}"]`).forEach((btn) => {
        if (btn.dataset.wired === '1') { return; }
        btn.dataset.wired = '1';
        btn.addEventListener('click', () => {
          // Re-roll the random suffix per click so the publish payload changes.
          let u = url;
          if (action === 'store-demo-publish')         { u = '/demo/pubsub/publish?channel=demo:pubsub&msg=' + encodeURIComponent('hello-' + Math.floor(Math.random() * 9999)); }
          if (action === 'store-demo-publish-reliable'){ u = '/demo/pubsub/publish-reliable?stream=demo:reliable&msg=' + encodeURIComponent('durable-' + Math.floor(Math.random() * 9999)); }
          hit(btn, u);
        });
      });
    });
  }

  // Initial wire + re-wire on hx-boost navigation
  if (document.readyState !== 'loading') { wire(); }
  else { document.addEventListener('DOMContentLoaded', wire); }
  document.addEventListener('htmx:afterSettle', wire);
})();

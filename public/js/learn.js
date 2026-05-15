// /js/learn.js — chat timeline client
(function () {
  'use strict';

  function initLearn() {
    const chatRoot = document.getElementById('learn-chat');
    if (chatRoot && !chatRoot.dataset.initialized) {
      chatRoot.dataset.initialized = '1';
      initChat(chatRoot);
    }
    initWebSocket();
    initNoteFormHighlight();
    initMermaidViewer();
  }

  document.addEventListener('DOMContentLoaded', initLearn);
  document.addEventListener('htmx:afterSettle', initLearn);

  function htmlFragment(html) {
    return document.createRange().createContextualFragment(html);
  }

  function makeEl(tag, className, text) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (text != null) el.textContent = text;
    return el;
  }

  function initChat(root) {
    const historyEl = root.querySelector('.chat-history');
    const messages  = root.querySelector('.chat-messages');
    const form      = root.querySelector('.chat-form');
    const input     = form.querySelector('input[name="message"]');
    const sendBtn   = form.querySelector('button');
    const modeBadge = root.querySelector('.chat-mode');
    const newBtn    = root.querySelector('.chat-new');

    let threadId = localStorage.getItem('zealphp_learn_thread') || cryptoRandomId();
    localStorage.setItem('zealphp_learn_thread', threadId);
    root.dataset.threadId = threadId;

    function loadHistory() {
      if (!historyEl) return;
      historyEl.textContent = '';
      fetch('/api/learn/chat_history?thread_id=' + encodeURIComponent(threadId))
        .then(r => r.ok ? r.text() : '')
        .then(html => {
          if (!html || html.includes('chat-empty')) return;
          historyEl.appendChild(htmlFragment(html));
          const scroll = root.querySelector('.chat-scroll');
          if (scroll) scroll.scrollTop = scroll.scrollHeight;
        })
        .catch(() => {});
    }
    loadHistory();

    if (newBtn) newBtn.addEventListener('click', () => {
      threadId = cryptoRandomId();
      localStorage.setItem('zealphp_learn_thread', threadId);
      root.dataset.threadId = threadId;
      if (historyEl) historyEl.textContent = '';
      messages.textContent = '';
    });

    fetch('/api/learn/chat_status').then(r => r.json()).then(s => {
      if (modeBadge) {
        modeBadge.textContent = s.mock_mode ? 'Mock mode' : s.model;
        modeBadge.title = s.mock_mode ? 'Set OPENAI_API_KEY for real AI' : '';
      }
    }).catch(() => {});

    form.addEventListener('submit', (e) => {
      e.preventDefault();
      const text = input.value.trim();
      if (!text) return;
      appendUser(messages, text);
      input.value = '';
      sendBtn.disabled = true;
      streamChat(text, threadId, messages, () => { sendBtn.disabled = false; input.focus(); });
    });
  }

  function appendUser(messages, text) {
    const wrap = makeEl('div', 'chat-msg user');
    const bub  = makeEl('div', 'chat-bubble', text);
    wrap.appendChild(bub);
    messages.appendChild(wrap);
    requestAnimationFrame(() => {
      const scr = messages.closest('.chat-scroll');
      if (scr) scr.scrollTop = scr.scrollHeight;
    });
  }

  function streamChat(message, threadId, messages, done) {
    const wrap = makeEl('div', 'chat-msg assistant');
    const bubble = makeEl('div', 'chat-bubble');
    const typing = makeEl('div', 'chat-typing');
    typing.append(makeEl('span'), makeEl('span'), makeEl('span'));
    bubble.appendChild(typing);
    wrap.appendChild(bubble);
    messages.appendChild(wrap);
    requestAnimationFrame(() => {
      const scr = messages.closest('.chat-scroll');
      if (scr) scr.scrollTop = scr.scrollHeight;
    });

    let lastItem = null;
    let textHtmlBuf = '';
    let typingRemoved = false;
    const removeTyping = () => { if (!typingRemoved) { typing.remove(); typingRemoved = true; } };
    const ensureText = () => {
      removeTyping();
      if (lastItem && lastItem.classList.contains('text')) return lastItem;
      textHtmlBuf = '';
      lastItem = makeEl('div', 'chat-item text');
      bubble.appendChild(lastItem);
      return lastItem;
    };

    let gotData = false;
    const timeoutId = setTimeout(() => {
      if (!gotData) {
        removeTyping();
        bubble.appendChild(makeEl('p', null, 'Response timed out. Try again.'));
        bubble.lastChild.style.color = '#b91c1c';
        done();
      }
    }, 30000);

    fetch('/api/learn/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message, thread_id: threadId }),
    }).then(resp => {
      if (resp.status === 401) {
        clearTimeout(timeoutId);
        removeTyping();
        bubble.appendChild(makeEl('p', null, 'Please log in first.'));
        done();
        return;
      }
      const reader = resp.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';
      let currentEvent = null;

      function read() {
        reader.read().then(({ value, done: streamDone }) => {
          if (streamDone) { clearTimeout(timeoutId); done(); return; }
          if (!gotData) { gotData = true; clearTimeout(timeoutId); }
          buffer += decoder.decode(value, { stream: true });
          const lines = buffer.split('\n');
          buffer = lines.pop();
          for (const line of lines) {
            if (line.startsWith('event: ')) {
              currentEvent = line.slice(7).trim();
            } else if (line.startsWith('data: ')) {
              try { handleEvent(currentEvent, JSON.parse(line.slice(6))); }
              catch (e) { /* ignore */ }
            }
          }
          requestAnimationFrame(() => {
            const scr = messages.closest('.chat-scroll');
            if (scr) scr.scrollTop = scr.scrollHeight;
          });
          read();
        }).catch(() => done());
      }
      read();

      function handleEvent(ev, data) {
        if (ev === 'token') {
          const t = ensureText();
          textHtmlBuf += (data.token || '');
          const tmpl = document.createElement('template');
          tmpl.content.textContent = '';
          // Re-render the full accumulated HTML on each token so partial
          // tags (e.g. "<str" from character-by-character streaming) don't
          // break the DOM. The browser's HTML parser handles incomplete tags
          // gracefully when given the full buffer each time.
          const range = document.createRange();
          range.selectNodeContents(t);
          range.deleteContents();
          t.appendChild(document.createRange().createContextualFragment(textHtmlBuf));
        } else if (ev === 'tool_call') {
          eventLog('sse', 'tool_call', data.name || '');
          removeTyping();
          textHtmlBuf = '';
          const card = makeEl('div', 'chat-item tool');
          card.dataset.id = data.id;
          card.dataset.status = 'running';
          const head = makeEl('div', 'tool-head');
          head.appendChild(makeEl('span', 'tool-icon', '⚙'));
          head.appendChild(makeEl('span', 'tool-name', data.name || ''));
          head.appendChild(makeEl('span', 'tool-status', 'running'));
          card.appendChild(head);
          const det = makeEl('details', 'tool-detail');
          det.appendChild(makeEl('summary', null, 'args + result'));
          det.appendChild(makeEl('pre', 'tool-args'));
          const res = makeEl('pre', 'tool-result'); res.hidden = true;
          det.appendChild(res);
          card.appendChild(det);
          bubble.appendChild(card);
          lastItem = card;
        } else if (ev === 'tool_args') {
          const card = bubble.querySelector(`.chat-item.tool[data-id="${cssEscape(data.id)}"]`);
          if (card) card.querySelector('.tool-args').textContent += (data.delta || '');
        } else if (ev === 'tool_done') {
          const card = bubble.querySelector(`.chat-item.tool[data-id="${cssEscape(data.id)}"]`);
          if (card) {
            card.dataset.status = data.status || 'ok';
            card.querySelector('.tool-status').textContent = data.status === 'error' ? 'failed' : 'done';
            if (data.result_preview) {
              const r = card.querySelector('.tool-result');
              r.textContent = data.result_preview;
              r.hidden = false;
            }
          }
          eventLog('sse', 'tool_done', (data.status || 'ok') + (data.result_preview ? ' — ' + data.result_preview.substring(0, 40) : ''));
          lastItem = null;
        } else if (ev === 'notes_changed') {
          eventLog('sse', 'notes_changed', '→ refreshing notes');
          handleNoteChanged('refresh', null);
        } else if (ev === 'error') {
          removeTyping();
          const p = makeEl('p', null, 'Error: ' + (data.error || ''));
          p.style.color = '#b91c1c';
          bubble.appendChild(p);
        }
      }
    }).catch(err => {
      removeTyping();
      const p = makeEl('p', null, 'Network error: ' + String(err));
      p.style.color = '#b91c1c';
      bubble.appendChild(p);
      done();
    });
  }

  function cssEscape(s) { return String(s).replace(/"/g, '\\"'); }
  function cryptoRandomId() {
    const a = new Uint8Array(8);
    (window.crypto || window.msCrypto).getRandomValues(a);
    return Array.from(a, b => b.toString(16).padStart(2, '0')).join('');
  }

  // Cross-tab notes sync via WebSocket — opens on /learn/notes and /learn/ai-chat.
  let wsConnected = false;
  function eventLog(channel, event, detail) {
    const log = document.getElementById('ws-log');
    if (!log) return;
    const time = new Date().toLocaleTimeString('en-GB', { hour12: false });
    const line = document.createElement('div');
    line.className = 'event-log-line event-log-' + channel;

    const ts = makeEl('span', 'event-log-time', time);
    const tag = makeEl('span', 'event-log-tag event-log-tag-' + channel, channel === 'ws' ? 'WS' : 'SSE');
    const ev = makeEl('span', 'event-log-event', event);
    line.appendChild(ts);
    line.append(' ');
    line.appendChild(tag);
    line.append(' ');
    line.appendChild(ev);
    if (detail) {
      line.append(' ');
      line.appendChild(makeEl('span', 'event-log-detail', detail));
    }
    log.appendChild(line);
    log.scrollTop = log.scrollHeight;
  }

  function wsLog(msg) {
    if (!msg.type || msg.type === 'heartbeat' || msg.type === 'pong') return;
    const op = msg.op ? msg.op : '';
    const id = msg.id ? '#' + msg.id : '';
    eventLog('ws', msg.type, [op, id].filter(Boolean).join(' '));
  }

  var _lastLocalCreateId = null;

  function handleNoteChanged(op, noteId) {
    if (op === 'delete' && noteId) {
      const card = document.getElementById('note-' + noteId);
      if (card) {
        card.classList.add('note-deleting');
        setTimeout(() => card.remove(), 400);
        const list = document.getElementById('notes-list');
        if (list) setTimeout(() => { if (!list.querySelector('.note')) { list.textContent = ''; list.appendChild(makeEl('p', 'notes-empty', 'No notes yet. Add one above.')); } }, 450);
      }
      return;
    }
    if (op === 'create' && noteId && document.getElementById('note-' + noteId)) {
      const card = document.getElementById('note-' + noteId);
      if (!card.classList.contains('note-created')) {
        card.classList.add('note-created');
        setTimeout(() => card.classList.remove('note-created'), 2500);
      }
      return;
    }
    if (!window.htmx) return;
    window.htmx.ajax('GET', '/api/learn/notes', { target: '#notes-list', swap: 'innerHTML' });
    if (noteId) {
      setTimeout(() => {
        const card = document.getElementById('note-' + noteId);
        if (card) {
          card.classList.add(op === 'create' ? 'note-created' : 'note-updated');
          setTimeout(() => card.classList.remove('note-created', 'note-updated'), 2500);
        }
      }, 300);
    }
  }

  function initNoteFormHighlight() {}

  function initMermaidViewer() {
    document.querySelectorAll('pre.mermaid').forEach(function (pre) {
      if (pre.dataset.viewerInit) return;
      pre.dataset.viewerInit = '1';
      pre.addEventListener('click', function () {
        var svg = pre.querySelector('svg');
        if (!svg) return;
        openMermaidViewer(svg);
      });
    });
  }

  function openMermaidViewer(svg) {
    var scale = 1, tx = 0, ty = 0, dragging = false, lastX = 0, lastY = 0;

    var overlay = document.createElement('div');
    overlay.className = 'mermaid-viewer';

    var inner = document.createElement('div');
    inner.className = 'mermaid-viewer-inner';
    var clone = svg.cloneNode(true);
    clone.removeAttribute('style');
    var svgRect = svg.getBoundingClientRect();
    var vb = svg.getAttribute('viewBox');
    var vbParts = vb ? vb.split(/[\s,]+/).map(Number) : null;
    var natW = (vbParts && vbParts[2]) || parseFloat(svg.getAttribute('width')) || svgRect.width || 400;
    var natH = (vbParts && vbParts[3]) || parseFloat(svg.getAttribute('height')) || svgRect.height || 300;
    clone.removeAttribute('width');
    clone.removeAttribute('height');
    clone.removeAttribute('style');
    if (!clone.getAttribute('viewBox')) clone.setAttribute('viewBox', '0 0 ' + natW + ' ' + natH);
    clone.style.width = natW + 'px';
    clone.style.height = natH + 'px';
    clone.style.background = '#ffffff';
    clone.style.borderRadius = '8px';
    var origW = natW, origH = natH;
    clone.style.background = '#ffffff';
    clone.style.borderRadius = '8px';
    inner.appendChild(clone);
    overlay.appendChild(inner);

    var closeBtn = document.createElement('button');
    closeBtn.className = 'mermaid-viewer-close';
    closeBtn.textContent = '✕';
    overlay.appendChild(closeBtn);

    var hint = document.createElement('div');
    hint.className = 'mermaid-viewer-hint';
    hint.textContent = 'Pinch to zoom · Scroll or drag to pan · Esc to close';
    overlay.appendChild(hint);

    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';

    function applyTransform() {
      inner.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ')';
    }

    var pad = 80;
    var fitScaleX = (window.innerWidth - pad * 2) / origW;
    var fitScaleY = (window.innerHeight - pad * 2) / origH;
    scale = Math.min(fitScaleX, fitScaleY, 4);
    tx = (window.innerWidth - origW * scale) / 2;
    ty = (window.innerHeight - origH * scale) / 2;
    applyTransform();

    overlay.addEventListener('wheel', function (e) {
      e.preventDefault();
      if (e.ctrlKey) {
        var delta = e.deltaY > 0 ? 0.95 : 1.05;
        var mx = e.clientX, my = e.clientY;
        tx = mx - (mx - tx) * delta;
        ty = my - (my - ty) * delta;
        scale *= delta;
        scale = Math.max(0.2, Math.min(scale, 8));
      } else {
        tx -= e.deltaX;
        ty -= e.deltaY;
      }
      applyTransform();
    }, { passive: false });

    overlay.addEventListener('mousedown', function (e) {
      if (e.target === closeBtn) return;
      dragging = true; lastX = e.clientX; lastY = e.clientY;
    });
    overlay.addEventListener('mousemove', function (e) {
      if (!dragging) return;
      tx += e.clientX - lastX; ty += e.clientY - lastY;
      lastX = e.clientX; lastY = e.clientY;
      applyTransform();
    });
    overlay.addEventListener('mouseup', function () { dragging = false; });

    function close() {
      document.body.removeChild(overlay);
      document.body.style.overflow = '';
    }
    closeBtn.addEventListener('click', close);
    overlay.addEventListener('dblclick', close);
    document.addEventListener('keydown', function onKey(e) {
      if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onKey); }
    });
  }

  function initWebSocket() {
    if (wsConnected) return;
    const notesList = document.getElementById('notes-list');
    if (!notesList) return;
    wsConnected = true;
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    let ws = null;
    let reconnectDelay = 500;
    function connect() {
      try { ws = new WebSocket(proto + '//' + location.host + '/ws/learn'); }
      catch (e) { return; }
      ws.addEventListener('open', () => { reconnectDelay = 500; });
      ws.addEventListener('message', (ev) => {
        try {
          const msg = JSON.parse(ev.data);
          wsLog(msg);
          if (msg.type === 'note_changed') handleNoteChanged(msg.op, msg.id);
        } catch (e) { /* ignore */ }
      });
      ws.addEventListener('close', (ev) => {
        if (ev.code === 1008) return;
        reconnectDelay = Math.min(reconnectDelay * 2, 10000);
        setTimeout(connect, reconnectDelay);
      });
    }
    connect();
    setInterval(() => { if (ws && ws.readyState === 1) ws.send('ping'); }, 25000);
  }
})();

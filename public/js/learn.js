// /js/learn.js — chat timeline client
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', () => {
    const chatRoot = document.getElementById('learn-chat');
    if (chatRoot) initChat(chatRoot);
  });

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
      historyEl.hidden = false;
      fetch('/api/learn/chat_history?thread_id=' + encodeURIComponent(threadId))
        .then(r => r.ok ? r.text() : '')
        .then(html => {
          if (!html || html.includes('chat-empty')) { historyEl.hidden = true; return; }
          historyEl.appendChild(htmlFragment(html));
        })
        .catch(() => { historyEl.hidden = true; });
    }
    loadHistory();

    if (newBtn) newBtn.addEventListener('click', () => {
      threadId = cryptoRandomId();
      localStorage.setItem('zealphp_learn_thread', threadId);
      root.dataset.threadId = threadId;
      if (historyEl) { historyEl.textContent = ''; historyEl.hidden = true; }
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
    messages.scrollTop = messages.scrollHeight;
  }

  function streamChat(message, threadId, messages, done) {
    const wrap = makeEl('div', 'chat-msg assistant');
    const bubble = makeEl('div', 'chat-bubble');
    wrap.appendChild(bubble);
    messages.appendChild(wrap);
    messages.scrollTop = messages.scrollHeight;

    let lastItem = null;
    const ensureText = () => {
      if (lastItem && lastItem.classList.contains('text')) return lastItem;
      lastItem = makeEl('div', 'chat-item text');
      bubble.appendChild(lastItem);
      return lastItem;
    };

    fetch('/api/learn/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message, thread_id: threadId }),
    }).then(resp => {
      if (resp.status === 401) {
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
          if (streamDone) { done(); return; }
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
          messages.scrollTop = messages.scrollHeight;
          read();
        }).catch(() => done());
      }
      read();

      function handleEvent(ev, data) {
        if (ev === 'token') {
          const t = ensureText();
          t.appendChild(htmlFragment(data.token || ''));
        } else if (ev === 'tool_call') {
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
          lastItem = null;
        } else if (ev === 'notes_changed') {
          if (window.htmx) window.htmx.ajax('GET', '/api/learn/notes', { target: '#notes-list', swap: 'innerHTML' });
        } else if (ev === 'error') {
          const p = makeEl('p', null, 'Error: ' + (data.error || ''));
          p.style.color = '#b91c1c';
          bubble.appendChild(p);
        }
      }
    }).catch(err => {
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
})();

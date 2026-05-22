/* ==========================================================================
   home.js — page-scoped behavior for template/pages/home.php
   Auto-loaded by template/_head.php when $page === 'home' (deferred).
   Extracted verbatim from the four inline <script> blocks that previously
   lived in home.php (separation-of-concerns refactor). Functions referenced
   by inline onclick= (qsTab, chatSourceTab, chatSend) stay global.
   ========================================================================== */

/* --- Hero token-stream animation ---------------------------------------- */
(function () {
  const output = document.getElementById('hero-stream-output');
  if (!output) return;
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

/* --- Chat source-code tab switcher -------------------------------------- */
function chatSourceTab(btn, id) {
  btn.parentElement.querySelectorAll('.chat-source-tab').forEach(function (t) { t.classList.remove('active'); });
  btn.classList.add('active');
  btn.closest('.chat-source').querySelectorAll('.chat-src-panel').forEach(function (p) { p.classList.add('home-chat-src-hidden'); });
  document.getElementById(id).classList.remove('home-chat-src-hidden');
}
window.chatSourceTab = chatSourceTab;

/* --- Live AI chat widget ------------------------------------------------- */
(function () {
  let threadId = localStorage.getItem('zealphp_chat_thread');

  // Check status
  const statusEl = document.getElementById('chat-status');
  if (statusEl) {
    fetch('/api/chat/status').then(function (r) { return r.json(); }).then(function (s) {
      const el = document.getElementById('chat-status');
      el.textContent = s.ai_enabled ? 'Agents SDK' : 'Demo mode';
      el.style.color = s.ai_enabled ? '#10b981' : '#f59e0b';
    }).catch(function () {
      document.getElementById('chat-status').textContent = 'Offline';
    });
  }

  // Enter to send
  const chatInput = document.getElementById('chat-input');
  if (chatInput) {
    chatInput.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); chatSend(); }
    });
  }

  window.chatSend = function () {
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
    assistantDiv.innerHTML = '<div class="chat-msg-bubble"><span class="chat-typing"><span></span><span></span><span></span></span></div>';
    messages.appendChild(assistantDiv);
    messages.scrollTop = messages.scrollHeight;

    const bubble = assistantDiv.querySelector('.chat-msg-bubble');

    // SSE via fetch — accumulate HTML and render via innerHTML
    let rawHtml = '';
    fetch('/api/chat', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ message: text, thread_id: threadId })
    }).then(function (response) {
      const reader = response.body.getReader();
      const decoder = new TextDecoder();
      let buffer = '';

      function read() {
        reader.read().then(function (result) {
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
                  if (!rawHtml) bubble.querySelector('.chat-typing')?.remove();
                  rawHtml += data.token;
                  bubble.innerHTML = rawHtml;
                  messages.scrollTop = messages.scrollHeight;
                }
              } catch (e) {}
            }
          }
          read();
        });
      }
      read();
    }).catch(function (e) {
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

/* --- Quick Start tabs + copy-to-clipboard ------------------------------- */
function qsTab(name) {
  document.querySelectorAll('.qs-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
  document.querySelectorAll('.qs-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === name));
}
window.qsTab = qsTab;

document.addEventListener('click', function (e) {
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

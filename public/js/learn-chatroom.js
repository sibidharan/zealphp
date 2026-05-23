/* Lesson 22 — multi-room group chat widget client.
 *
 * One WebSocket per widget (rebound on every htmx swap). Renders message
 * stream + room lobby via createElement + textContent (no innerHTML — XSS-safe
 * by construction; user-supplied strings can't break out of text-node context).
 * Reconnects on accidental drop.
 */
(function () {
  'use strict';

  function init() {
    var root = document.querySelector('.chatroom-widget');
    if (!root || root.dataset.wired === '1') return;
    root.dataset.wired = '1';

    var username   = root.dataset.username || 'anonymous';
    var roomInput  = document.getElementById('chatroom-room-input');
    var joinForm   = root.querySelector('.chatroom-join-form');
    var lobby      = document.getElementById('chatroom-lobby');
    var status     = document.getElementById('chatroom-status');
    var messages   = document.getElementById('chatroom-messages');
    var typing     = document.getElementById('chatroom-typing');
    var form       = document.getElementById('chatroom-form');
    var body       = document.getElementById('chatroom-body');
    var sendBtn    = form ? form.querySelector('button') : null;

    var ws = null;
    var currentRoom = '';

    // Typing-presence state — keyed by username (other users only).
    // Per-user "they stopped typing" timeout in case the 'off' frame is dropped
    // (network blip, browser tab close). Auto-clears after TYPING_TIMEOUT_MS.
    var TYPING_TIMEOUT_MS = 4000;
    var typingUsers = Object.create(null);   // username -> timeoutId
    // Outbound debounce: send 'on' once + reset on every keystroke; send 'off'
    // when input is empty OR after TYPING_IDLE_MS of inactivity.
    var TYPING_IDLE_MS = 2500;
    var lastTypingSentState = 'off';
    var typingIdleTimer = null;

    function setStatus(state, label) {
      if (!status) return;
      status.classList.remove('chatroom-status-on', 'chatroom-status-off');
      status.classList.add(state === 'on' ? 'chatroom-status-on' : 'chatroom-status-off');
      status.textContent = label;
    }

    function makeSpan(cls, text) {
      var s = document.createElement('span');
      s.className = cls;
      s.textContent = text;
      return s;
    }

    function clearChildren(node) {
      while (node.firstChild) { node.removeChild(node.firstChild); }
    }

    function renderMsg(m) {
      var isSystem = m.kind === 'system';
      var hhmm = new Date((m.created_at || 0) * 1000).toTimeString().slice(0, 5);

      var div = document.createElement('div');
      div.className = 'chatroom-msg' + (isSystem ? ' system' : '');
      div.appendChild(makeSpan('chatroom-msg-user', String(m.username || '')));
      div.appendChild(makeSpan('chatroom-msg-body', String(m.body || '')));
      div.appendChild(makeSpan('chatroom-msg-time', hhmm));

      var empty = messages.querySelector('.chatroom-empty');
      if (empty) empty.remove();
      messages.appendChild(div);
      messages.scrollTop = messages.scrollHeight;
    }

    function renderLobby(rooms) {
      if (!lobby) return;
      clearChildren(lobby);
      if (!rooms.length) {
        var empty = document.createElement('li');
        empty.className = 'chatroom-lobby-empty';
        empty.textContent = 'No rooms yet — be the first.';
        lobby.appendChild(empty);
        return;
      }
      rooms.forEach(function (r) {
        var li = document.createElement('li');
        li.dataset.room = r.room;
        if (r.room === currentRoom) li.className = 'active';
        li.appendChild(document.createTextNode('#' + r.room + ' '));
        li.appendChild(makeSpan('chatroom-lobby-count', '(' + r.count + ')'));
        lobby.appendChild(li);
      });
    }

    function renderTyping() {
      if (!typing) return;
      var names = Object.keys(typingUsers);
      if (!names.length) { typing.textContent = ''; return; }
      var label;
      if (names.length === 1)      { label = names[0] + ' is typing…'; }
      else if (names.length === 2) { label = names[0] + ' and ' + names[1] + ' are typing…'; }
      else                         { label = names.length + ' people are typing…'; }
      typing.textContent = label;
    }

    function handleTypingEvent(user, state) {
      if (!user || user === username) return;   // ignore self-echoes
      if (typingUsers[user]) { clearTimeout(typingUsers[user]); }
      if (state === 'on') {
        // Auto-clear after timeout in case the matching 'off' is dropped.
        typingUsers[user] = setTimeout(function () {
          delete typingUsers[user];
          renderTyping();
        }, TYPING_TIMEOUT_MS);
      } else {
        delete typingUsers[user];
      }
      renderTyping();
    }

    function sendTypingState(state) {
      if (!ws || ws.readyState !== 1) return;
      if (lastTypingSentState === state) return;   // dedup repeats
      lastTypingSentState = state;
      try { ws.send(JSON.stringify({ type: 'typing', state: state })); } catch (_) {}
    }

    function noteOutboundKeystroke() {
      if (body && body.value.length === 0) {
        sendTypingState('off');
        if (typingIdleTimer) { clearTimeout(typingIdleTimer); typingIdleTimer = null; }
        return;
      }
      sendTypingState('on');
      if (typingIdleTimer) { clearTimeout(typingIdleTimer); }
      typingIdleTimer = setTimeout(function () { sendTypingState('off'); }, TYPING_IDLE_MS);
    }

    function loadLobby() {
      fetch('/api/learn/chatroom/lobby').then(function (r) { return r.json(); })
        .then(function (j) { if (j.ok) renderLobby(j.rooms || []); })
        .catch(function () { /* tolerant */ });
    }

    function connectAndJoin(room) {
      if (ws) {
        try { ws.close(); } catch (_) {}
      }
      var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
      ws = new WebSocket(proto + '//' + location.host + '/ws/learn/chatroom');
      setStatus('off', 'connecting…');

      ws.onopen = function () {
        setStatus('on', '#' + room);
        ws.send(JSON.stringify({ type: 'join', room: room, username: username }));
        currentRoom = room;
        if (body) body.disabled = false;
        if (sendBtn) sendBtn.disabled = false;
        loadLobby();
      };
      ws.onmessage = function (ev) {
        var m;
        try { m = JSON.parse(ev.data); } catch (_) { return; }
        if (m.type === 'history') {
          clearChildren(messages);
          (m.items || []).forEach(renderMsg);
          return;
        }
        if (m.type === 'message' && m.message) {
          renderMsg(m.message);
          loadLobby();
          return;
        }
        if (m.type === 'typing') {
          handleTypingEvent(m.user, m.state);
          return;
        }
      };
      ws.onclose = function () {
        setStatus('off', 'disconnected');
        if (body) body.disabled = true;
        if (sendBtn) sendBtn.disabled = true;
      };
      ws.onerror = function () { setStatus('off', 'error'); };
    }

    if (joinForm) {
      joinForm.addEventListener('submit', function (e) {
        e.preventDefault();
        var room = (roomInput && roomInput.value || 'general').trim() || 'general';
        connectAndJoin(room);
      });
    }

    if (form) {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!ws || ws.readyState !== 1 || !body || !body.value.trim()) return;
        ws.send(JSON.stringify({ type: 'message', body: body.value.trim() }));
        body.value = '';
        // Sending a message ends the typing burst — let peers know immediately.
        sendTypingState('off');
        if (typingIdleTimer) { clearTimeout(typingIdleTimer); typingIdleTimer = null; }
      });
    }

    if (body) {
      // Per-keystroke: emit 'on' once, refresh the 2.5s idle timer; auto 'off'
      // when the input becomes empty.
      body.addEventListener('input', noteOutboundKeystroke);
    }

    if (lobby) {
      lobby.addEventListener('click', function (e) {
        var li = e.target.closest('li[data-room]');
        if (!li) return;
        var room = li.dataset.room;
        if (roomInput) roomInput.value = room;
        connectAndJoin(room);
      });
    }

    loadLobby();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  if (window.htmx) {
    document.addEventListener('htmx:afterSettle', init);
  }
})();

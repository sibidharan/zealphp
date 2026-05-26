(function () {
  let ws = null;
  let me = { symbol: '?', room: '' };
  let lastState = null;
  let backoffMs = 500;
  let manualClose = false;
  let currentRoom = '';
  let currentName = '';
  let currentView = false;

  function $(id) { return document.getElementById(id); }

  function setStatus(text, cls) {
    const s = $('ttt-status');
    if (!s) return;
    s.textContent = text;
    s.className = 'ttt-status ' + (cls || '');
  }

  function showStage(show) {
    const stage = $('ttt-stage');
    const form  = $('ttt-join-form');
    if (stage) stage.hidden = !show;
    if (form)  form.hidden = !!show;
  }

  function setBoard(board, winLine) {
    const root = $('ttt-board');
    if (!root) return;
    root.querySelectorAll('.ttt-cell').forEach(function (btn) {
      const i = parseInt(btn.dataset.cell, 10);
      const ch = board[i];
      btn.textContent = ch === '_' ? '' : ch;
      btn.classList.remove('ttt-cell-x', 'ttt-cell-o', 'ttt-cell-win', 'ttt-cell-filled');
      if (ch === 'X') btn.classList.add('ttt-cell-x', 'ttt-cell-filled');
      if (ch === 'O') btn.classList.add('ttt-cell-o', 'ttt-cell-filled');
      btn.disabled = ch !== '_';
    });
    if (Array.isArray(winLine)) {
      winLine.forEach(function (i) {
        var cell = root.querySelector('.ttt-cell[data-cell="' + i + '"]');
        if (cell) cell.classList.add('ttt-cell-win');
      });
    }
  }

  function updateScoreCell(id, value) {
    var el = document.getElementById(id);
    if (!el) return;
    if (el.textContent === String(value)) return;
    el.textContent = String(value);
    el.classList.remove('ttt-score-flash');
    void el.offsetWidth;
    el.classList.add('ttt-score-flash');
  }

  function applyState(state) {
    lastState = state;
    setBoard(state.board, state.win_line);

    var px = state.players.X, po = state.players.O;
    var pxn = $('ttt-px-name'), pon = $('ttt-po-name');
    var pxs = $('ttt-px-status'), pos = $('ttt-po-status');
    if (pxn) pxn.textContent = px.name || 'waiting...';
    if (pon) pon.textContent = po.name || 'waiting...';
    if (pxs) { pxs.textContent = px.connected ? 'online' : 'offline'; pxs.classList.toggle('online', !!px.connected); }
    if (pos) { pos.textContent = po.connected ? 'online' : 'offline'; pos.classList.toggle('online', !!po.connected); }

    var viewers = state.viewers | 0;
    var v = $('ttt-viewers');
    if (v) v.textContent = viewers > 0 ? viewers + ' viewer' + (viewers === 1 ? '' : 's') : '';
    var r = $('ttt-rounds');
    if (r) r.textContent = state.rounds > 0 ? 'round ' + (state.rounds + (state.winner ? 0 : 1)) : '';

    var score = state.score || { X: 0, O: 0, draw: 0 };
    updateScoreCell('ttt-score-x', score.X | 0);
    updateScoreCell('ttt-score-o', score.O | 0);
    updateScoreCell('ttt-score-draws', score.draw | 0);

    var isMyTurn = me.symbol === state.turn;
    var board = $('ttt-board');
    if (board) board.setAttribute('data-disabled', (!isMyTurn || me.symbol === 'S' || state.winner !== '') ? '1' : '0');

    var reset = $('ttt-reset');
    if (reset) reset.hidden = me.symbol === 'S';
    var resetScore = $('ttt-reset-score');
    if (resetScore) {
      var hasScore = (score.X | 0) + (score.O | 0) + (score.draw | 0) > 0;
      resetScore.hidden = me.symbol === 'S' || !hasScore;
    }

    if (state.winner === 'X' || state.winner === 'O') {
      var w = state.players[state.winner];
      setStatus('Winner: ' + (w.name || state.winner) + ' (' + state.winner + ')', 'win');
    } else if (state.winner === 'draw') {
      setStatus('Draw — click Reset to play again', 'draw');
    } else if (me.symbol === 'S') {
      setStatus('Spectating — ' + state.turn + "'s turn", 'spectating');
    } else if (!px.connected || !po.connected) {
      setStatus('Waiting for opponent (you are ' + me.symbol + ')', 'waiting');
    } else if (isMyTurn) {
      setStatus('Your turn (' + me.symbol + ')', 'your-turn');
    } else {
      setStatus('Waiting on ' + state.turn, 'their-turn');
    }
  }

  function setYou(symbol, room) {
    me.symbol = symbol;
    me.room = room;
    var you = $('ttt-you');
    if (you) {
      you.textContent = symbol === 'S' ? 'spectator' : symbol;
      you.className = 'ttt-symbol-chip ' + (
        symbol === 'X' ? 'ttt-symbol-x' :
        symbol === 'O' ? 'ttt-symbol-o' : 'ttt-symbol-s'
      );
    }
    var rn = $('ttt-room-name');
    if (rn) rn.textContent = room;
  }

  function connect(room, name, view) {
    if (ws) return;
    manualClose = false;
    currentRoom = room;
    currentName = name;
    currentView = view;
    var proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    var qs = '?room=' + encodeURIComponent(room) + '&name=' + encodeURIComponent(name);
    if (view) qs += '&view=1';
    ws = new WebSocket(proto + '//' + location.host + '/ws/game' + qs);
    setStatus('connecting...', 'connecting');
    showStage(true);

    ws.onopen = function () { backoffMs = 500; };

    ws.onmessage = function (e) {
      var msg;
      try { msg = JSON.parse(e.data); } catch (_) { return; }
      if (!msg || typeof msg !== 'object') return;
      if (msg.type === 'welcome') { setYou(msg.symbol || '?', msg.room || ''); return; }
      if (msg.type === 'state') applyState(msg);
    };

    ws.onclose = function (ev) {
      ws = null;
      if (manualClose) { setStatus('left room', 'closed'); return; }
      if (ev && ev.code === 1008) { setStatus('disconnected: ' + (ev.reason || 'error'), 'err'); return; }
      setStatus('reconnecting...', 'closed');
      setTimeout(function () { if (currentRoom) connect(currentRoom, currentName, currentView); }, backoffMs);
      backoffMs = Math.min(backoffMs * 2, 8000);
    };

    ws.onerror = function () { setStatus('connection error', 'err'); };
  }

  function send(obj) { if (ws && ws.readyState === 1) ws.send(JSON.stringify(obj)); }

  document.addEventListener('submit', function (e) {
    var f = e.target.closest('#ttt-join-form');
    if (!f) return;
    e.preventDefault();
    var name = (f.querySelector('input[name="name"]').value || '').trim().slice(0, 24);
    var room = (f.querySelector('input[name="room"]').value || '').trim().toLowerCase().replace(/[^a-z0-9\-]/g, '').slice(0, 32);
    if (!name || !room) return;
    var view = !!(f.querySelector('input[name="view"]') || {}).checked;
    connect(room, name, view);
  });

  document.addEventListener('click', function (e) {
    var cell = e.target.closest('.ttt-cell');
    if (cell) {
      var board = $('ttt-board');
      if (board && board.getAttribute('data-disabled') === '1') return;
      if (cell.disabled) return;
      send({ type: 'move', cell: parseInt(cell.dataset.cell, 10) });
      return;
    }
    if (e.target.closest('#ttt-reset')) { send({ type: 'reset' }); return; }
    if (e.target.closest('#ttt-reset-score')) {
      if (confirm('Reset the scoreboard?')) send({ type: 'reset_score' });
      return;
    }
    if (e.target.closest('#ttt-leave')) {
      manualClose = true;
      if (ws) try { ws.close(1000); } catch (_) {}
      ws = null;
      me = { symbol: '?', room: '' };
      lastState = null;
      showStage(false);
    }
  });
})();

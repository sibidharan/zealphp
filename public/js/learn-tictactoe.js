// Tic-tac-toe client.
// - Connects to /ws/tictactoe?room=X (auth via PHPSESSID cookie).
// - All client→server traffic is JSON over the socket; the server validates
//   that the sender's fd holds the symbol whose turn it is.
// - Event delegation on document so htmx swaps in /out of the lesson page
//   don't break the handlers.
// - Filters {type:'heartbeat'} global pings (route/ws.php broadcasts every
//   30s to all open fds; the lesson is auth-gated so heartbeats arrive too).
(function () {
  let ws = null;
  let me = { symbol: '?', room: '', name: '' };
  let lastState = null;
  let backoffMs = 500;
  let manualClose = false;

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
    root.querySelectorAll('.ttt-cell').forEach(btn => {
      const i = parseInt(btn.dataset.cell, 10);
      const ch = board[i];
      btn.textContent = ch === '_' ? '' : ch;
      btn.classList.remove('ttt-cell-x', 'ttt-cell-o', 'ttt-cell-win', 'ttt-cell-filled');
      if (ch === 'X') btn.classList.add('ttt-cell-x', 'ttt-cell-filled');
      if (ch === 'O') btn.classList.add('ttt-cell-o', 'ttt-cell-filled');
      btn.disabled = ch !== '_';
    });
    if (Array.isArray(winLine)) {
      winLine.forEach(i => {
        const cell = root.querySelector(`.ttt-cell[data-cell="${i}"]`);
        if (cell) cell.classList.add('ttt-cell-win');
      });
    }
  }

  function applyState(state) {
    lastState = state;
    setBoard(state.board, state.win_line);

    const px = state.players.X, po = state.players.O;
    $('ttt-px-name') && ($('ttt-px-name').textContent = px.name || 'waiting…');
    $('ttt-po-name') && ($('ttt-po-name').textContent = po.name || 'waiting…');
    $('ttt-px-status') && ($('ttt-px-status').textContent = px.connected ? 'online' : 'offline');
    $('ttt-po-status') && ($('ttt-po-status').textContent = po.connected ? 'online' : 'offline');
    $('ttt-px-status')?.classList.toggle('online', !!px.connected);
    $('ttt-po-status')?.classList.toggle('online', !!po.connected);

    const viewers = state.viewers | 0;
    const v = $('ttt-viewers');
    if (v) v.textContent = viewers > 0 ? `${viewers} viewer${viewers === 1 ? '' : 's'}` : '';
    const r = $('ttt-rounds');
    if (r) r.textContent = state.rounds > 0 ? `round ${state.rounds + (state.winner ? 0 : 1)}` : '';

    // Scoreboard — flash the cell whose count changed since the previous state.
    const score = state.score || { X: 0, O: 0, draw: 0 };
    updateScoreCell('ttt-score-x',     score.X    | 0);
    updateScoreCell('ttt-score-o',     score.O    | 0);
    updateScoreCell('ttt-score-draws', score.draw | 0);

    const isMyTurn = me.symbol === state.turn;
    const board = $('ttt-board');
    board?.setAttribute('data-disabled', (!isMyTurn || me.symbol === 'S' || state.winner !== '') ? '1' : '0');

    const reset = $('ttt-reset');
    if (reset) reset.hidden = me.symbol === 'S';
    const resetScore = $('ttt-reset-score');
    if (resetScore) {
      // Show "Reset score" only to seated players AND only when there's
      // anything to clear (avoids dead-button clutter on a fresh room).
      const hasScore = (score.X | 0) + (score.O | 0) + (score.draw | 0) > 0;
      resetScore.hidden = me.symbol === 'S' || !hasScore;
    }

    if (state.winner === 'X' || state.winner === 'O') {
      const w = state.players[state.winner];
      setStatus(`Winner: ${w.name || state.winner} (${state.winner}) — click Reset for round ${state.rounds + 1}`, 'win');
    } else if (state.winner === 'draw') {
      setStatus('Draw — click Reset to play again', 'draw');
    } else if (me.symbol === 'S') {
      setStatus(`Spectating · ${state.turn}'s turn`, 'spectating');
    } else if (!px.connected || !po.connected) {
      setStatus(`Waiting for opponent (you are ${me.symbol})`, 'waiting');
    } else if (isMyTurn) {
      setStatus(`Your turn (${me.symbol})`, 'your-turn');
    } else {
      setStatus(`Waiting on ${state.turn}`, 'their-turn');
    }
  }

  function updateScoreCell(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    const prev = el.textContent;
    if (prev === String(value)) return;
    el.textContent = String(value);
    el.classList.remove('ttt-score-flash');
    void el.offsetWidth;
    el.classList.add('ttt-score-flash');
  }

  function setYou(symbol, room) {
    me.symbol = symbol;
    me.room = room;
    const you = $('ttt-you');
    if (you) {
      you.textContent = symbol === 'S' ? 'viewer' : symbol;
      you.className = 'ttt-symbol-chip ' + (
        symbol === 'X' ? 'ttt-symbol-x' :
        symbol === 'O' ? 'ttt-symbol-o' :
        'ttt-symbol-s'
      );
    }
    const rn = $('ttt-room-name');
    if (rn) rn.textContent = room;
  }

  function connect(room, viewMode) {
    if (ws) return;
    manualClose = false;
    const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
    const qs = '?room=' + encodeURIComponent(room) + (viewMode ? '&view=1' : '');
    ws = new WebSocket(proto + '//' + location.host + '/ws/tictactoe' + qs);
    setStatus('connecting…', 'connecting');
    showStage(true);

    ws.onopen = () => { backoffMs = 500; };

    ws.onmessage = e => {
      let msg;
      try { msg = JSON.parse(e.data); } catch (_) { return; }
      if (!msg || typeof msg !== 'object') return;
      if (msg.type === 'heartbeat') return;
      if (msg.type === 'welcome') {
        setYou(String(msg.symbol || '?'), String(msg.room || ''));
        return;
      }
      if (msg.type === 'state') applyState(msg);
    };

    ws.onclose = (ev) => {
      ws = null;
      if (manualClose) {
        setStatus('left room', 'closed');
        return;
      }
      // 1008 = policy violation (auth / no_session / no_room) — don't retry.
      if (ev && ev.code === 1008) {
        setStatus('disconnected: ' + (ev.reason || 'policy'), 'err');
        return;
      }
      setStatus('disconnected — reconnecting…', 'closed');
      setTimeout(() => {
        if (me.room) connect(me.room, viewMode);
      }, backoffMs);
      backoffMs = Math.min(backoffMs * 2, 8000);
    };

    ws.onerror = () => setStatus('connection error', 'err');
  }

  function sendMove(cell) {
    if (!ws || ws.readyState !== 1) return;
    ws.send(JSON.stringify({ type: 'move', cell }));
  }

  function sendReset() {
    if (!ws || ws.readyState !== 1) return;
    ws.send(JSON.stringify({ type: 'reset' }));
  }

  function sendResetScore() {
    if (!ws || ws.readyState !== 1) return;
    if (!confirm('Reset the scoreboard? Wins/losses/draws will be zeroed.')) return;
    ws.send(JSON.stringify({ type: 'reset_score' }));
  }

  function leave() {
    manualClose = true;
    if (ws) try { ws.close(1000); } catch (_) {}
    ws = null;
    me = { symbol: '?', room: '', name: '' };
    lastState = null;
    showStage(false);
  }

  // Event delegation — survives htmx swaps
  document.addEventListener('submit', e => {
    const f = e.target.closest('#ttt-join-form');
    if (!f) return;
    e.preventDefault();
    const room = (f.querySelector('input[name="room"]').value || '').trim().toLowerCase().replace(/[^a-z0-9-]/g, '').slice(0, 32);
    if (!room) return;
    const viewMode = !!f.querySelector('input[name="view"]')?.checked;
    // Update URL so reload preserves the room
    const u = new URL(location.href);
    u.searchParams.set('room', room);
    if (viewMode) u.searchParams.set('view', '1'); else u.searchParams.delete('view');
    history.replaceState({}, '', u.toString());
    connect(room, viewMode);
  });

  document.addEventListener('click', e => {
    const cell = e.target.closest('.ttt-cell');
    if (cell) {
      const root = $('ttt-board');
      if (root?.getAttribute('data-disabled') === '1') return;
      if (cell.disabled) return;
      sendMove(parseInt(cell.dataset.cell, 10));
      return;
    }
    if (e.target.closest('#ttt-reset'))       { sendReset(); return; }
    if (e.target.closest('#ttt-reset-score')) { sendResetScore(); return; }
    if (e.target.closest('#ttt-leave'))       { leave(); return; }
  });

  // Auto-connect if the URL already carries ?room=… (deep-linking from
  // the lesson sidebar's standalone-tab link).
  function maybeAutoConnect() {
    const form = $('ttt-join-form');
    if (!form) return;
    const u = new URL(location.href);
    const room = (u.searchParams.get('room') || '').trim().toLowerCase().replace(/[^a-z0-9-]/g, '').slice(0, 32);
    if (!room) return;
    const viewMode = u.searchParams.get('view') === '1';
    // Don't auto-reconnect if we already have a socket
    if (ws) return;
    connect(room, viewMode);
  }
  document.addEventListener('DOMContentLoaded', maybeAutoConnect);
  document.addEventListener('htmx:afterSettle', maybeAutoConnect);
})();

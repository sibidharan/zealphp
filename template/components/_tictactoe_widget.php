<?php
// Multiplayer tic-tac-toe widget. Rendered inline at /learn/tictactoe and
// standalone at /demo/view/tictactoe/play. Expects $user (required) —
// player display name comes from $user['username'].
//
// Until the user enters a room, only the join form is interactive. After
// `/js/learn-tictactoe.js` opens a WebSocket and receives the welcome
// frame, the JS injects board state into the slots below.
$user ??= null;
if (!$user) return;
$preRoom = htmlspecialchars((string) ($_GET['room'] ?? ''), ENT_QUOTES);
$preView = (((string) ($_GET['view'] ?? '')) === '1') ? ' checked' : '';
?>
<section class="ttt" data-ttt-user="<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>">
  <form id="ttt-join-form" class="ttt-join">
    <label class="ttt-label">
      <span>Room ID</span>
      <input type="text" name="room" maxlength="32" required
             pattern="[a-z0-9-]{1,32}"
             placeholder="alpha-1"
             autocomplete="off"
             value="<?= $preRoom ?>">
    </label>
    <label class="ttt-view-toggle">
      <input type="checkbox" name="view" value="1"<?= $preView ?>> <span>Join as viewer (spectator)</span>
    </label>
    <button type="submit" class="btn btn-primary">Join room</button>
    <p class="ttt-hint">Pick any room ID. Two players share the same ID to play; extra connections join as viewers.</p>
  </form>

  <div id="ttt-stage" class="ttt-stage" hidden>
    <div class="ttt-meta">
      <div class="ttt-room">Room <code id="ttt-room-name" class="ttt-room-name"></code></div>
      <div class="ttt-you">You are <span id="ttt-you" class="ttt-symbol-chip">?</span></div>
    </div>
    <div class="ttt-players">
      <div class="ttt-player" data-side="X">
        <span class="ttt-symbol-chip ttt-symbol-x">X</span>
        <span id="ttt-px-name" class="ttt-player-name">waiting…</span>
        <span id="ttt-px-status" class="ttt-player-status">offline</span>
      </div>
      <div class="ttt-player" data-side="O">
        <span class="ttt-symbol-chip ttt-symbol-o">O</span>
        <span id="ttt-po-name" class="ttt-player-name">waiting…</span>
        <span id="ttt-po-status" class="ttt-player-status">offline</span>
      </div>
    </div>
    <div id="ttt-status" class="ttt-status">connecting…</div>
    <div id="ttt-scoreboard" class="ttt-scoreboard" aria-label="Match scoreboard">
      <span class="ttt-score-cell" data-side="X">
        <span class="ttt-symbol-chip ttt-symbol-x">X</span>
        <span class="ttt-score-num" id="ttt-score-x">0</span>
      </span>
      <span class="ttt-score-sep">vs</span>
      <span class="ttt-score-cell" data-side="O">
        <span class="ttt-score-num" id="ttt-score-o">0</span>
        <span class="ttt-symbol-chip ttt-symbol-o">O</span>
      </span>
      <span class="ttt-score-draws"><span id="ttt-score-draws">0</span> draws</span>
    </div>
    <div id="ttt-board" class="ttt-board" data-disabled="1">
      <?php for ($i = 0; $i < 9; $i++): ?>
        <button type="button" class="ttt-cell" data-cell="<?= $i ?>" aria-label="cell <?= $i + 1 ?>"></button>
      <?php endfor; ?>
    </div>
    <div class="ttt-actions">
      <button type="button" id="ttt-reset" class="btn btn-ghost" hidden>Reset game</button>
      <button type="button" id="ttt-reset-score" class="btn btn-ghost ttt-reset-score" hidden>Reset score</button>
      <button type="button" id="ttt-leave" class="btn btn-ghost ttt-leave">Leave room</button>
      <span id="ttt-viewers" class="ttt-viewers"></span>
      <span id="ttt-rounds" class="ttt-rounds"></span>
    </div>
  </div>
</section>

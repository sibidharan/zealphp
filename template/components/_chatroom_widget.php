<?php
// Reusable Lesson 22 chatroom widget.
// Expects: $user (array{user_id:int, username:string} — required, non-null).
// Wired by /js/learn-chatroom.js — opens the WebSocket, handles join/send/recv,
// renders the room list + messages, all via DOM hooks (#chatroom-* ids).
$user ??= null;
if (!$user) { return; }
$username = (string) $user['username'];
?>
<section class="chatroom-widget" data-username="<?= htmlspecialchars($username, ENT_QUOTES) ?>">
  <div class="chatroom-rooms">
    <h4 class="chatroom-h">Rooms</h4>
    <form class="chatroom-join-form" hx-boost="false" autocomplete="off">
      <input id="chatroom-room-input" name="room" value="general" maxlength="32" placeholder="room name" />
      <button id="chatroom-join-btn" type="submit">Join</button>
    </form>
    <ul id="chatroom-lobby" class="chatroom-lobby"><li class="chatroom-lobby-empty">Loading rooms…</li></ul>
  </div>
  <div class="chatroom-main">
    <header class="chatroom-header">
      <span>Logged in as <strong><?= htmlspecialchars($username) ?></strong></span>
      <span id="chatroom-status" class="chatroom-status chatroom-status-off">disconnected</span>
    </header>
    <div id="chatroom-messages" class="chatroom-messages">
      <p class="chatroom-empty">Pick a room and click <strong>Join</strong> to start chatting.</p>
    </div>
    <form id="chatroom-form" class="chatroom-form" hx-boost="false" autocomplete="off">
      <input id="chatroom-body" name="body" placeholder="Type a message…" maxlength="2000" disabled />
      <button type="submit" disabled>Send</button>
    </form>
  </div>
</section>
<script src="/js/learn-chatroom.js?v=<?= htmlspecialchars((string) (defined('ZEALPHP_ASSET_VERSION') ? constant('ZEALPHP_ASSET_VERSION') : time())) ?>"></script>

<?php
// Reusable AI Chat widget.
// Expects: $user (array{user_id:int, username:string} — required, non-null).
// Identical HTML in lesson + standalone demo shell. /js/learn.js wires up
// the chat-form submit handler (SSE EventSource), event log, and notes-panel
// WS sync via #learn-chat / #ws-log / #notes-list ids.
$user ??= null;
if (!$user) return;
?>
<p>Logged in as <strong><?= htmlspecialchars($user['username']) ?></strong>.</p>
<section class="chat">
  <div>
    <h3 class="chat-h">Your notes</h3>
    <div id="notes-list" class="notes-list" hx-get="/api/learn/notes" hx-trigger="load" hx-swap="innerHTML">
      <p class="notes-empty">Loading…</p>
    </div>
  </div>
  <div>
    <div id="learn-chat" class="chat-box" data-thread-id="">
      <div class="chat-head">
        Notes assistant
        <span class="chat-mode">…</span>
        <button type="button" class="chat-new" title="Start a fresh conversation">New thread</button>
      </div>
      <div class="chat-scroll">
        <div class="chat-history"></div>
        <div class="chat-messages"></div>
      </div>
      <form class="chat-form" autocomplete="off" hx-boost="false">
        <input type="text" name="message" placeholder="Ask anything about your notes…" required>
        <button type="submit">Send</button>
      </form>
    </div>
    <div class="event-log-wrap">
      <h4 class="event-log-title">Event log</h4>
      <div id="ws-log" class="event-log"></div>
    </div>
  </div>
</section>

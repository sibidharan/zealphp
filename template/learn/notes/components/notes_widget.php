<?php
// Reusable Personal Notes widget.
// Expects: $user (array{user_id:int, username:string} — required, non-null).
// Rendered in two places: inline at /learn/notes and standalone at
// /demo/view/notes/widget. The HTML output is identical in both contexts —
// /js/learn.js wires up form-submit, list-load, and WS cross-tab sync via
// the #notes-list id which stays stable across renderings.
$user ??= null;
if (!$user) return;  // caller is responsible for gating auth
?>
<div class="notes-user-bar">
  <span class="notes-user-avatar"><?= strtoupper(substr($user['username'], 0, 1)) ?></span>
  <span class="notes-user-name"><?= htmlspecialchars($user['username']) ?></span>
  <a href="/api/learn/logout" class="notes-user-logout">Log out</a>
</div>

<section class="notes-app">
  <form class="note-form"
        hx-post="/api/learn/notes"
        hx-target="#notes-list"
        hx-swap="afterbegin"
        hx-on::after-request="this.reset()"
        hx-on::after-settle="var f=document.querySelector('#notes-list .note:first-child');if(f){f.classList.add('note-created');setTimeout(function(){f.classList.remove('note-created')},2500)}">
    <input type="text" name="title" placeholder="Note title" required maxlength="200">
    <textarea name="body" placeholder="What's on your mind?" maxlength="4096"></textarea>
    <button type="submit">Add note</button>
  </form>

  <div id="notes-list" class="notes-list"
       hx-get="/api/learn/notes"
       hx-trigger="load"
       hx-swap="innerHTML">
    <p class="notes-empty">Loading…</p>
  </div>
</section>

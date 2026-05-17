<?php
// Compact login/register panel used in demo viewers when no session is
// active. Submits via fetch (not htmx) so the success path can simply
// reload the current demo viewer instead of following HX-Redirect to
// /learn/notes. The fetch handler lives in /js/learn-demo-viewers.js,
// keyed off the data-demo-login attribute on the <form>.
$intro ??= 'This demo needs an account. Log in or create one — you stay on this page.';
?>
<div class="demo-login-wrap">
  <p class="demo-login-intro"><?= $intro ?></p>

  <form data-demo-login="login" class="demo-login-form" autocomplete="on">
    <label class="demo-login-row">
      <span>Username</span>
      <input type="text" name="username" required minlength="3" maxlength="64" autocomplete="username">
    </label>
    <label class="demo-login-row">
      <span>Password</span>
      <input type="password" name="password" required minlength="8" autocomplete="current-password">
    </label>
    <div class="demo-login-actions">
      <button type="submit" class="demo-login-btn">Log in</button>
      <button type="button" class="demo-login-toggle" data-demo-login-toggle>Need an account? Register</button>
    </div>
    <div class="demo-login-msg" data-demo-login-msg></div>
  </form>

  <form data-demo-login="register" class="demo-login-form" autocomplete="on" hidden>
    <label class="demo-login-row">
      <span>New username</span>
      <input type="text" name="username" required minlength="3" maxlength="64" autocomplete="username">
    </label>
    <label class="demo-login-row">
      <span>New password (8+)</span>
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
    </label>
    <div class="demo-login-actions">
      <button type="submit" class="demo-login-btn">Create account</button>
      <button type="button" class="demo-login-toggle" data-demo-login-toggle>Have an account? Log in</button>
    </div>
    <div class="demo-login-msg" data-demo-login-msg></div>
  </form>
</div>

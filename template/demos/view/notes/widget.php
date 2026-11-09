<?php
/**
 * /demo/view/notes/widget — the /learn/notes widget in its own shell (cross-tab WS sync testing)
 *
 * @var array<string, mixed>|null $user the signed-in user, or null (shows the login card)
 */
use ZealPHP\App;
?>
<?php if (!$user): ?>
<p class="demo-shell-desc">Log in below to use the standalone notes widget. You'll stay on this page after sign-in.</p>
<h2 class="demo-shell-h"></h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/demos/components/login_card', [
          'intro' => 'This demo needs an account. Sign in or create one — no redirects, the widget loads in place.',
      ]) ?>
    </div>
  </div>
</div>
<?php else: ?>
<p class="demo-shell-desc">Same <code class="demo-inline">_notes_widget</code> partial that renders inline in the <a href="/learn/notes">Personal Notes lesson</a> — here in its own shell so you can open multiple windows for cross-tab WebSocket sync testing.</p>
<h2 class="demo-shell-h">Live widget</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/learn/notes/components/notes_widget', ['user' => $user]) ?>
    </div>
  </div>
</div>
<?php endif; ?>

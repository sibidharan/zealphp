<?php
/**
 * /demo/view/chatroom/widget — the /learn/chatroom widget in its own shell
 *
 * @var array<string, mixed>|null $user the signed-in user, or null (shows the login card)
 */
use ZealPHP\App;
?>
<?php if (!$user): ?>
<p class="demo-shell-desc">Log in below to chat. Same auth as Notes / Tic-Tac-Toe — one account, all the demos.</p>
<h2 class="demo-shell-h"></h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/demos/components/login_card', [
          'intro' => 'The chat tags each message with your username so the other tabs know who said it. Sign in or create one — the widget loads in place.',
      ]) ?>
    </div>
  </div>
</div>
<?php else: ?>
<p class="demo-shell-desc">Same <code class="demo-inline">_chatroom_widget</code> partial that renders inline in the <a href="/learn/chatroom">lesson</a>. SQLite-backed history persists across reloads; open in two tabs to chat with yourself. <a href="/learn/chatroom">Read the build</a>.</p>
<h2 class="demo-shell-h"></h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/learn/chatroom/components/chatroom_widget', ['user' => $user]) ?>
    </div>
  </div>
</div>
<?php endif; ?>

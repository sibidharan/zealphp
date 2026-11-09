<?php
/**
 * /demo/view/tictactoe/play — the /learn/tictactoe board in its own shell (the widget is the page)
 *
 * @var array<string, mixed>|null $user the signed-in user, or null (shows the login card)
 */
use ZealPHP\App;
?>
<?php if (!$user): ?>
<p class="demo-shell-desc">Sign in to play. Display name = your username so opponents see who they're playing.</p>
<h2 class="demo-shell-h"></h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/demos/components/login_card', [
          'intro' => 'Tic-tac-toe needs an account so other players see your name. Sign in or create one — the game loads in place.',
      ]) ?>
    </div>
  </div>
</div>
<?php else: ?>
<p class="demo-shell-desc">Same room ID = same game. First two players take X and O; rest are viewers. <code class="demo-inline">?view=1</code> forces viewer. <a href="/learn/tictactoe">Read the build</a>.</p>
<h2 class="demo-shell-h"></h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/learn/tictactoe/components/tictactoe_widget', ['user' => $user]) ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php
/**
 * /demo/view/chat/widget — the /learn/ai-chat widget in its own shell
 *
 * @var array<string, mixed>|null $user the signed-in user, or null (shows the login card)
 */
use ZealPHP\App;
?>
<?php if (!$user): ?>
<p class="demo-shell-desc">Log in below to use the standalone chat widget.</p>
<h2 class="demo-shell-h"></h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/demos/components/login_card', [
          'intro' => 'The chat needs an account so it can read and modify your notes. Sign in or create one — the chat loads in place.',
      ]) ?>
    </div>
  </div>
</div>
<?php else: ?>
<p class="demo-shell-desc">Same <code class="demo-inline">_chat_widget</code> partial that renders inline in the <a href="/learn/ai-chat">AI Chat lesson</a>. Try a prompt like <em>"create a note titled shopping list"</em> and watch the Event Log below the chat for live <span class="proto-badge sse">SSE</span> + <span class="proto-badge ws">WS</span> events.</p>
<h2 class="demo-shell-h">Live widget</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/learn/ai-chat/components/chat_widget', ['user' => $user]) ?>
    </div>
  </div>
</div>
<?php endif; ?>

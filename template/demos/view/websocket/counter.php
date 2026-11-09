<?php
/**
 * /demo/view/websocket/counter — the /learn/websocket counter in its own shell (no auth: one global value)
 */
use ZealPHP\App;
?>
<p class="demo-shell-desc">Click <strong>+1</strong>. Open this URL in a second window — both update live via a WebSocket broadcast to every connected client. <a href="/learn/websocket">Read the build</a>.</p>
<h2 class="demo-shell-h"></h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <?= App::component('/learn/websocket/components/ws_counter_widget', ['as_demo' => true]) ?>
    </div>
  </div>
</div>

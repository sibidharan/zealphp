<?php
/**
 * /demo/view/response/json
 *
 * @var string $payload
 */
use ZealPHP\App;
?>
<p class="demo-shell-desc">Handler returns a plain PHP array. The framework auto-encodes it as JSON and sets <code class="demo-inline">Content-Type: application/json</code>. No need to call <code class="demo-inline">$response->json()</code>.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/response/json</code>
    </div>
  </div>
</div>
<?= App::component('/demos/components/response', ['status' => 200, 'type' => 'application/json', 'payload' => $payload]) ?>

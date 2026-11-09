<?php
/**
 * /demo/view/inject/request-only
 *
 * @var string $payload
 */
use ZealPHP\App;
?>
<p class="demo-shell-desc">Route handler declares only <code class="demo-inline">$request</code>. The framework injects the <code class="demo-inline">ZealPHP\HTTP\Request</code> wrapper — headers, query, body, server vars, all accessible.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/inject/request-only</code>
    </div>
  </div>
</div>
<?= App::component('/demos/components/response', ['status' => 200, 'type' => 'application/json', 'payload' => $payload]) ?>

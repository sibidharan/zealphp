<?php
/**
 * /demo/view/inject/url/{id}
 *
 * @var string $id
 * @var string $payload JSON the raw /demo/inject/url endpoint returns
 */
use ZealPHP\App;
use function ZealPHP\e;
?>
<p class="demo-shell-desc">Route: <code class="demo-inline">$app->route('/demo/inject/url/{id}', function ($id) { ... })</code>. The framework injected <code class="demo-inline">$id</code> by name from the URL pattern. Nothing else was injected.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/inject/url/<?= e($id) ?></code>
    </div>
  </div>
</div>
<?= App::component('/demos/components/response', ['status' => 200, 'type' => 'application/json', 'payload' => $payload]) ?>

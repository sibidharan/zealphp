<?php
/**
 * /demo/view/inject/defaults/{id}
 *
 * @var string $id
 * @var string $payload
 */
use ZealPHP\App;
use function ZealPHP\e;
?>
<p class="demo-shell-desc">Handler signature: <code class="demo-inline">function ($id, $page = 1)</code>. The framework injects <code class="demo-inline">$id</code> from the URL; <code class="demo-inline">$page</code> isn't in the URL or the framework injection table, so the default value (<code class="demo-inline">1</code>) is used. To override, use the path with two segments — see the related URL below.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/inject/defaults/<?= e($id) ?></code> · with page: <code class="demo-inline">/demo/inject/defaults/<?= e($id) ?>/7</code>
    </div>
  </div>
</div>
<?= App::component('/demos/components/response', ['status' => 200, 'type' => 'application/json', 'payload' => $payload]) ?>

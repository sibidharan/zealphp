<?php
/**
 * /demo/view/inject/url-response/{id}
 *
 * @var string $id
 * @var string $payload
 */
use ZealPHP\App;
use function ZealPHP\e;
?>
<p class="demo-shell-desc">Two-name injection: <code class="demo-inline">function ($id, $response)</code>. The framework injects <code class="demo-inline">$id</code> from the URL and <code class="demo-inline">$response</code> from the framework. Note the custom <code class="demo-inline">X-Demo-Injected</code> header on this very response.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/inject/url-response/<?= e($id) ?></code>
    </div>
  </div>
</div>
<?= App::component('/demos/components/response', ['status' => 200, 'type' => 'application/json', 'payload' => $payload]) ?>

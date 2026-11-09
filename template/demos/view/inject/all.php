<?php
/**
 * /demo/view/inject/all/{id}
 *
 * @var string $id
 * @var string $payload
 */
use ZealPHP\App;
use function ZealPHP\e;
?>
<p class="demo-shell-desc">Three-name injection: <code class="demo-inline">function ($id, $request, $response)</code>. Order does not matter — injection is by parameter name. Try adding <code class="demo-inline">?foo=bar&debug=1</code> to the URL and reload.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/inject/all/<?= e($id) ?></code> · try <code class="demo-inline">?foo=bar</code>
    </div>
  </div>
</div>
<?= App::component('/demos/components/response', ['status' => 200, 'type' => 'application/json', 'payload' => $payload]) ?>

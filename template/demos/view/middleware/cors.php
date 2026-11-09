<?php
/**
 * /demo/view/middleware/cors
 *
 * @var string $origin the request's Origin header, or '(none)'
 */
use function ZealPHP\e;
?>
<p class="demo-shell-desc">The <code class="demo-inline">CorsMiddleware</code> registered in <code class="demo-inline">app.php</code> adds <code class="demo-inline">Access-Control-Allow-Origin</code> + related headers to every response. It also intercepts OPTIONS preflight requests automatically — try <code class="demo-inline">curl -X OPTIONS http://host/anything</code>.</p>
<h2 class="demo-shell-h">Origin you sent</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline"><?= e($origin) ?></code>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Response</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>Access-Control-Allow-Origin</dt><dd><code class="demo-inline">*</code> (or whatever <code class="demo-inline">ZEALPHP_CORS_ORIGINS</code> is set to)</dd><dt>Vary</dt><dd><code class="demo-inline">Origin</code></dd></dl>
    </div>
  </div>
</div>

<?php
/**
 * /demo/view/response/headers
 *
 * @var int $now the X-Demo-Time value
 */
use function ZealPHP\e;
?>
<p class="demo-shell-desc">Handler called <code class="demo-inline">$response->header('X-Demo-Method', 'response-header()')</code> and similar for two more custom headers. Open DevTools → Network → this request to see them all in the response.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/response/headers</code>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Response</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>X-Demo-Method</dt><dd><code class="demo-inline">response-header()</code></dd><dt>X-Powered-By</dt><dd><code class="demo-inline">ZealPHP + OpenSwoole</code></dd><dt>X-Demo-Time</dt><dd><code class="demo-inline"><?= e($now) ?></code></dd></dl><p class="demo-note">Inspect this very response in DevTools Network — the headers above are real, set live by the demo route.</p>
    </div>
  </div>
</div>

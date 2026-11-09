<?php
/**
 * /demo/view/response/cookie
 *
 * @var int $now the timestamp in the cookie value
 */
use function ZealPHP\e;
?>
<p class="demo-shell-desc">Handler called <code class="demo-inline">$response->cookie('zealphp_demo_cookie', ...)</code>. Look at <code class="demo-inline">document.cookie</code> in DevTools console to confirm it landed. Cookie path <code class="demo-inline">/</code>, SameSite=Lax, expires in 1 hour.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/response/cookie</code>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Response</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>Set-Cookie</dt><dd><code class="demo-inline">zealphp_demo_cookie=set-at-<?= e($now) ?>; Max-Age=3600; Path=/; SameSite=Lax</code></dd></dl>
    </div>
  </div>
</div>

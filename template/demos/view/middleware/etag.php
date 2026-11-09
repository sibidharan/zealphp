<?php
/**
 * /demo/view/middleware/etag
 *
 * @var string $etag the weak ETag of the demo body
 */
use function ZealPHP\e;
?>
<p class="demo-shell-desc">The <code class="demo-inline">ETagMiddleware</code> generates a weak ETag from the response body. Re-request this URL with <code class="demo-inline">If-None-Match: <?= e($etag) ?></code> and you'll get a <code class="demo-inline">304 Not Modified</code> — body skipped, bandwidth saved.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/middleware/etag</code>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Response</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s2xx">200 OK</span></dd><dt>ETag</dt><dd><code class="demo-inline"><?= e($etag) ?></code></dd></dl><p class="demo-note">Try in DevTools → Network: reload, then reload again — second request returns 304.</p>
    </div>
  </div>
</div>

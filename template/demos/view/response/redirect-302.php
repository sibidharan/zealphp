<?php
/**
 * /demo/view/response/redirect-302
 */
?>
<p class="demo-shell-desc">Handler called <code class="demo-inline">$response->redirect('/')</code> which returns a PSR-7 response with status 302 and a <code class="demo-inline">Location: /</code> header. The browser would normally follow this — opened directly so you see the response, not the destination.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/response/redirect-302</code> · related: <a href="/demo/view/response/redirect-301">redirect-301</a>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Response</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s3xx">302 Found</span></dd><dt>Location</dt><dd><code class="demo-inline">/</code></dd></dl>
    </div>
  </div>
</div>

<?php
/**
 * /demo/view/response/redirect-301
 */
?>
<p class="demo-shell-desc">Handler called <code class="demo-inline">$response->redirect('/', 301)</code>. Status 301 tells the browser and search engines that the move is permanent — bookmarks and indexes get updated.</p>
<h2 class="demo-shell-h">Live URL</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <code class="demo-inline">GET /demo/response/redirect-301</code>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Response</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <dl class="demo-kv"><dt>Status</dt><dd><span class="demo-status s3xx">301 Moved Permanently</span></dd><dt>Location</dt><dd><code class="demo-inline">/</code></dd></dl>
    </div>
  </div>
</div>

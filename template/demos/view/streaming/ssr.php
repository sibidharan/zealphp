<?php
/**
 * /demo/view/streaming/ssr — drives /stream/ssr from the page (/js/learn-demo-viewers.js)
 */
?>
<p class="demo-shell-desc">A route handler that returns a <code class="demo-inline">\Generator</code> streams every <code class="demo-inline">yield</code> chunk to the browser the moment it produces it &mdash; the page renders progressively instead of waiting for the whole HTML to assemble. Click <strong>Run</strong> below; the chunks arrive in the dark panel.</p>
<h2 class="demo-shell-h">How it's wired (route side)</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <pre class="demo-payload">$app->route('/stream/ssr', function () {
    return (function () {
        yield '&lt;section&gt;&lt;h2&gt;Loading…&lt;/h2&gt;&lt;/section&gt;';
        foreach (slow_data_source() as $row) {
            yield "&lt;article&gt;{$row}&lt;/article&gt;";
        }
        yield '&lt;footer&gt;Done.&lt;/footer&gt;';
    })();
});</pre>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Run it</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <button class="demo-action-btn" type="button" data-viewer-action="ssr" data-demo-url="/stream/ssr" data-target="demo-ssr-out">▶ Run Generator SSR</button><div id="demo-ssr-out" class="demo-live-output">Click <em>Run</em> to start the stream…</div>
    </div>
  </div>
</div>

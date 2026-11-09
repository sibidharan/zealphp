<?php
/**
 * /demo/view/streaming/stream — drives /stream/words from the page
 */
?>
<p class="demo-shell-desc">The <code class="demo-inline">$response->stream($fn)</code> primitive gives you fine-grained control: <code class="demo-inline">$fn</code> receives a <code class="demo-inline">$write(string)</code> closure that flushes chunks the moment you call it. Useful when you want to decide what to send <em>at run time</em> instead of yielding a fixed sequence.</p>
<h2 class="demo-shell-h">How it's wired (route side)</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <pre class="demo-payload">$app->route('/stream/words', function ($response) {
    return $response->stream(function ($write) {
        foreach (explode(' ', 'streamed word by word from the server') as $w) {
            $write($w . ' ');
            co::sleep(0.15);  // a real handler waits on real I/O instead
        }
    });
});</pre>
    </div>
  </div>
</div>
<h2 class="demo-shell-h">Run it</h2>
<div class="inject-case">
  <div class="inject-case-body inject-case-body--solo">
    <div class="demo-output">
      <button class="demo-action-btn" type="button" data-viewer-action="stream" data-demo-url="/stream/words" data-target="demo-stream-out">▶ Run stream() demo</button><div id="demo-stream-out" class="demo-live-output">Click <em>Run</em>. Each word arrives as a separate chunk.</div>
    </div>
  </div>
</div>
